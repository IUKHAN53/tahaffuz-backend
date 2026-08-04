<?php

namespace App\Services\Rag;

class Chunker
{
    /**
     * Section-aware chunker. The extractor marks headings with a leading
     * "## ", so the text splits into titled topic sections first; each section
     * becomes one chunk (heading + verbatim body) when it fits, and larger
     * sections are paragraph-packed with the heading carried onto every piece
     * so retrieval always knows what topic a chunk belongs to. Text without
     * heading markers falls back to plain paragraph packing.
     *
     * @return string[]
     */
    public function chunk(string $text, int $size = 900, int $overlap = 120): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        if (preg_match('/^##\s+/mu', $text)) {
            return $this->chunkSections($text, $size, $overlap);
        }

        return $this->chunkParagraphs($text, $size, $overlap);
    }

    /**
     * Split on "## heading" markers; one chunk per section when it fits.
     *
     * @return string[]
     */
    protected function chunkSections(string $text, int $size, int $overlap): array
    {
        // Keep any preamble before the first heading as its own block.
        $parts = preg_split('/^(?=##\s+)/mu', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $chunks = [];
        foreach ($parts as $section) {
            $section = trim($section);
            if ($section === '') {
                continue;
            }

            if (mb_strlen($section) <= $size) {
                $chunks[] = $section;

                continue;
            }

            // Oversized section: keep the heading on every piece.
            $heading = '';
            $body = $section;
            if (preg_match('/^##\s+(.+)$/mu', $section, $m)) {
                $heading = trim($m[0]);
                $body = trim((string) preg_replace('/^##\s+.+$/mu', '', $section, 1));
            }
            $bodySize = max(200, $size - mb_strlen($heading) - 2);
            foreach ($this->chunkParagraphs($body, $bodySize, $overlap) as $piece) {
                $chunks[] = $heading === '' ? $piece : $heading."\n".$piece;
            }
        }

        return array_values(array_filter($chunks, fn ($c) => $this->isUseful($c)));
    }

    /**
     * Paragraph-aware char-windowed packing (the original strategy). Splits on
     * blank lines; a paragraph exceeding $size is hard-split with $overlap.
     *
     * @return string[]
     */
    protected function chunkParagraphs(string $text, int $size = 900, int $overlap = 120): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $paragraphs = preg_split("/\n{2,}/u", $text) ?: [];
        $chunks = [];
        $buffer = '';

        $flush = function () use (&$buffer, &$chunks) {
            $b = trim($buffer);
            if ($b !== '') {
                $chunks[] = $b;
            }
            $buffer = '';
        };

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }

            if (mb_strlen($buffer) + mb_strlen($para) + 2 <= $size) {
                $buffer .= ($buffer === '' ? '' : "\n\n").$para;
                continue;
            }

            // Buffer full — flush and start fresh with this paragraph.
            $flush();

            if (mb_strlen($para) <= $size) {
                $buffer = $para;
                continue;
            }

            // Paragraph itself is bigger than $size — hard-split with overlap.
            $start = 0;
            $len = mb_strlen($para);
            while ($start < $len) {
                $piece = mb_substr($para, $start, $size);
                $chunks[] = trim($piece);
                if ($start + $size >= $len) {
                    break;
                }
                $start += max(1, $size - $overlap);
            }
        }

        $flush();

        $cleaned = array_values(array_filter($chunks, fn ($c) => $this->isUseful($c)));
        return $cleaned;
    }

    /**
     * Reject junk chunks: page-footer noise, mostly digits/symbols, or under-length.
     * A chunk is "useful" only if it has enough real letter content.
     */
    public function isUseful(string $chunk): bool
    {
        $text = trim($chunk);
        if (mb_strlen($text) < 80) {
            return false;
        }

        // Count Unicode letters (any script: Urdu, Arabic, Latin, etc.)
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters < 40) {
            return false;
        }

        // Letters must be at least 35% of total characters
        $total = mb_strlen($text);
        if ($letters / $total < 0.35) {
            return false;
        }

        // At least one "word" of 3+ letters
        if (! preg_match('/\p{L}{3,}/u', $text)) {
            return false;
        }

        return true;
    }
}
