<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Minimal Expo push sender (https://exp.host/--/api/v2/push/send). */
class ExpoPush
{
    /**
     * @param  array<int, array{to:string,title:string,body:string,data?:array,sound?:string}>  $messages
     */
    public function send(array $messages): void
    {
        foreach (array_chunk($messages, 100) as $chunk) {
            try {
                Http::acceptJson()
                    ->timeout(20)
                    ->post('https://exp.host/--/api/v2/push/send', $chunk);
            } catch (Throwable $e) {
                Log::warning('Expo push failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
