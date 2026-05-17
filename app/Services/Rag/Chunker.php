<?php

namespace App\Services\Rag;

class Chunker
{
    /**
     * Paragraph-aware char-windowed chunker. Splits on blank lines first; if a
     * paragraph exceeds $size, it's hard-split with $overlap chars carried over.
     *
     * @return string[]
     */
    public function chunk(string $text, int $size = 900, int $overlap = 120): array
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
