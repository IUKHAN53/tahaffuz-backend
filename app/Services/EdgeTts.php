<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Text-to-speech via Microsoft Edge's "Read Aloud" neural voices, using the
 * `edge-tts` CLI installed in a venv on the server. Free, no API key, no daily
 * quota, and — crucially — it has Pashto voices (ps-AF) that no other provider
 * we can use offers. The text is passed through a temp FILE, never the command
 * line, so non-Latin scripts and shell metacharacters are handled safely.
 */
class EdgeTts
{
    public function __construct(
        protected ?string $binary = null,
        protected int $timeout = 30,
    ) {
        $this->binary ??= (string) config('rag.edge_tts.binary', '/opt/edge-tts/bin/edge-tts');
    }

    public function isAvailable(): bool
    {
        return $this->binary !== '' && is_executable($this->binary);
    }

    /**
     * Synthesize $text with the given Edge voice and return MP3 bytes.
     */
    public function synthesize(string $text, string $voice): string
    {
        $tmpIn = tempnam(sys_get_temp_dir(), 'edgetts_in_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'edgetts_out_');
        if ($tmpIn === false || $tmpOut === false) {
            throw new RuntimeException('edge-tts: cannot create temp files.');
        }
        file_put_contents($tmpIn, $text);

        try {
            $process = new Process([
                $this->binary,
                '--voice', $voice,
                '--file', $tmpIn,
                '--write-media', $tmpOut,
            ]);
            $process->setTimeout($this->timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('edge-tts failed: '.trim($process->getErrorOutput()));
            }

            $mp3 = @file_get_contents($tmpOut);
            // A valid MP3 for even a short phrase is several KB; anything tiny
            // means the voice produced nothing usable.
            if ($mp3 === false || strlen($mp3) < 512) {
                throw new RuntimeException('edge-tts produced no audio.');
            }

            return $mp3;
        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
        }
    }
}
