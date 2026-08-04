<?php

namespace App\Services\Rag;

use App\Services\Gemini;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory as WordIO;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;

class TextExtractor
{
    public function __construct(protected ?Gemini $gemini = null) {}

    public function extract(string $absolutePath, ?string $mime = null): string
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException("File not found: {$absolutePath}");
        }

        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mime = $mime ?: (function_exists('mime_content_type') ? mime_content_type($absolutePath) : null);

        return match (true) {
            $ext === 'pdf' || str_contains((string) $mime, 'pdf') => $this->extractPdf($absolutePath),
            $ext === 'docx' || str_contains((string) $mime, 'wordprocessingml') => $this->extractDocx($absolutePath),
            $ext === 'doc' => throw new RuntimeException('Legacy .doc not supported — convert to .docx first.'),
            $ext === 'txt' || $ext === 'md' || str_starts_with((string) $mime, 'text/') => (string) file_get_contents($absolutePath),
            default => throw new RuntimeException("Unsupported file type: {$ext} ({$mime})"),
        };
    }

    protected function extractPdf(string $path): string
    {
        $pageCount = null;
        try {
            $parser = new PdfParser;
            $doc = $parser->parseFile($path);
            $pageCount = count($doc->getPages());
            // RAG_EXTRACT_FORCE_VISION: the Urdu training modules are glyph
            // PDFs — PdfParser "extracts" them with scrambled glyph order that
            // still passes the letter check, so vision transcription must be
            // forced for them. PdfParser is kept only to learn the page count.
            if (! (bool) config('rag.extract_force_vision', false)) {
                $text = $this->normalize($doc->getText());
                $letters = preg_match_all('/\p{L}{3,}/u', $text);
                if ($letters >= 50) {
                    return $text;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to Gemini.
        }

        return $this->normalize($this->extractPdfViaGemini($path, $pageCount));
    }

    /**
     * Extract PDF text via Gemini. For docs > $pageBatchSize, requests text in
     * page-range batches so a single response never hits the output-token cap.
     */
    protected function extractPdfViaGemini(string $path, ?int $pageCount = null): string
    {
        $cfg = config('rag.gemini');
        $apiKey = $cfg['api_key'] ?? null;
        $model = $cfg['extract_model'] ?? $cfg['chat_model'];
        $base = $cfg['base_url'];
        if (! $apiKey) {
            throw new RuntimeException('Gemini API key required for image/glyph PDF extraction.');
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Cannot read PDF: {$path}");
        }
        $b64 = base64_encode($bytes);
        $url = "{$base}/models/{$model}:generateContent?key={$apiKey}";

        $batchSize = (int) config('rag.gemini.page_batch', 20);
        $batches = $this->planBatches($pageCount, $batchSize);

        $out = [];
        $failures = [];
        $interBatchMs = (int) config('rag.gemini.inter_batch_delay_ms', 1500);
        foreach ($batches as $i => [$from, $to]) {
            if ($i > 0 && $interBatchMs > 0) {
                usleep($interBatchMs * 1000);
            }
            $text = $this->callGeminiForRange($url, $b64, $from, $to);
            if ($text === null) {
                $failures[] = $from === null ? 'full' : "{$from}-{$to}";
                continue;
            }
            $out[] = $text;
        }

        if (empty($out)) {
            throw new RuntimeException('Gemini PDF extract failed for all batches: '.implode(',', $failures));
        }
        if (! empty($failures)) {
            Log::warning('Gemini PDF extract: some batches failed', ['file' => $path, 'failed' => $failures]);
        }

        return implode("\n\n", $out);
    }

    /**
     * @return array<int, array{0:?int,1:?int}>  list of [from,to] page ranges; [null,null] = whole doc
     */
    protected function planBatches(?int $pageCount, int $batchSize): array
    {
        if ($pageCount === null || $pageCount <= $batchSize) {
            return [[null, null]];
        }
        $batches = [];
        for ($from = 1; $from <= $pageCount; $from += $batchSize) {
            $batches[] = [$from, min($from + $batchSize - 1, $pageCount)];
        }
        return $batches;
    }

    protected function callGeminiForRange(string $url, string $b64, ?int $from, ?int $to): ?string
    {
        // VERBATIM transcription discipline: the training modules are official
        // medical content, so the wording must never be paraphrased, corrected,
        // summarized, or completed from outside knowledge — only faithfully
        // transcribed and STRUCTURED (headings marked, tables linearized row by
        // row, page furniture and decorative images skipped).
        $rules = 'You are transcribing an official EPI training document. Transcribe the text EXACTLY as '
            .'written — word for word, in the original language and script (Urdu, Arabic, English). '
            .'NEVER paraphrase, summarize, translate, "fix", or add ANY words that are not printed on the page. '
            ."\nStructure rules:\n"
            ."- Put every section heading/title on its own line prefixed with '## ' (the heading text itself verbatim).\n"
            ."- Transcribe tables ROW BY ROW as lines of 'cell | cell | cell' keeping each row's cells together in "
            ."reading order — never output a column of numbers detached from their row labels.\n"
            ."- For numbered lists, keep each number WITH its item text on the same line.\n"
            ."- SKIP page numbers, running headers/footers, watermarks and purely decorative images. If a figure "
            ."carries a caption or labels, transcribe them on one line starting with 'تصویر: '.\n"
            .'Output plain text only (the ## heading markers are the only markup). Separate pages with two newlines.';

        $prompt = $from === null
            ? $rules
            : "Transcribe ONLY pages {$from} through {$to} (inclusive) of this document. Skip every page outside that range entirely.\n".$rules;

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    ['inlineData' => ['mimeType' => 'application/pdf', 'data' => $b64]],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.0, 'maxOutputTokens' => 32768],
        ];

        $maxAttempts = 5;
        $backoffMs = 2000;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $resp = Http::timeout(300)->post($url, $payload);
            } catch (\Throwable $e) {
                Log::warning('Gemini HTTP transport error', ['range' => "{$from}-{$to}", 'attempt' => $attempt, 'error' => $e->getMessage()]);
                if ($attempt < $maxAttempts) {
                    usleep($backoffMs * 1000);
                    $backoffMs *= 2;
                }
                continue;
            }

            if ($resp->successful()) {
                $text = (string) $resp->json('candidates.0.content.parts.0.text', '');
                if (trim($text) !== '') {
                    return $text;
                }
                // Empty body with 200 — often a transient rate / safety hiccup. Retry.
                Log::warning('Gemini returned empty text — retrying', [
                    'range' => "{$from}-{$to}", 'attempt' => $attempt,
                    'finishReason' => $resp->json('candidates.0.finishReason'),
                    'promptFeedback' => $resp->json('promptFeedback'),
                ]);
            } else {
                $status = $resp->status();
                // 4xx (other than 429) won't fix itself — abort.
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    Log::error('Gemini PDF extract: non-retryable error', ['range' => "{$from}-{$to}", 'status' => $status, 'body' => substr($resp->body(), 0, 400)]);
                    return null;
                }
                Log::warning('Gemini PDF extract: retryable error', ['range' => "{$from}-{$to}", 'status' => $status, 'attempt' => $attempt, 'body' => substr($resp->body(), 0, 200)]);
            }

            if ($attempt < $maxAttempts) {
                usleep($backoffMs * 1000);
                $backoffMs = (int) min($backoffMs * 2, 30000);
            }
        }
        Log::error('Gemini PDF extract: exhausted retries', ['range' => "{$from}-{$to}"]);
        return null;
    }

    protected function extractDocx(string $path): string
    {
        $phpWord = WordIO::load($path);
        $out = [];
        foreach ($phpWord->getSections() as $section) {
            $this->collectTextFromContainer($section, $out);
        }
        return $this->normalize(implode("\n", $out));
    }

    protected function collectTextFromContainer($container, array &$out): void
    {
        foreach ($container->getElements() as $el) {
            $cls = class_basename($el);
            if ($cls === 'Text' || $cls === 'Link') {
                $out[] = method_exists($el, 'getText') ? (string) $el->getText() : '';
            } elseif ($cls === 'TextRun') {
                $line = '';
                foreach ($el->getElements() as $sub) {
                    if (method_exists($sub, 'getText')) {
                        $line .= (string) $sub->getText();
                    }
                }
                $out[] = $line;
            } elseif ($cls === 'Table') {
                foreach ($el->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->collectTextFromContainer($cell, $out);
                    }
                }
            } elseif (method_exists($el, 'getElements')) {
                $this->collectTextFromContainer($el, $out);
            }
        }
    }

    protected function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim((string) $text);
    }
}
