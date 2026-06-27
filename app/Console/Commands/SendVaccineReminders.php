<?php

namespace App\Console\Commands;

use App\Models\PushToken;
use App\Models\VaccinationCard;
use App\Services\ExpoPush;
use App\Services\VaccineSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendVaccineReminders extends Command
{
    protected $signature = 'vaccines:remind';

    protected $description = 'Push reminders to workers for scanned children with overdue or soon-due vaccines';

    public function handle(VaccineSchedule $sched, ExpoPush $push): int
    {
        // Latest card per device.
        $cards = VaccinationCard::orderByDesc('id')->get()->unique('device_id');
        $today = Carbon::today();
        $messages = [];

        foreach ($cards as $card) {
            $pt = PushToken::where('device_id', $card->device_id)->first();
            if (! $pt || ! $pt->token) {
                continue;
            }

            $dob = $sched->parseDob($card->date_of_birth);
            $sum = $sched->summary($dob, $sched->givenFromCard($card->vaccines), $today);
            $child = trim((string) $card->child_name) ?: null;

            if (! empty($sum['overdue'])) {
                [$title, $body] = $this->overdueMessage($pt->language ?? 'ur', $child, $sum['overdue']);
            } elseif ($sum['next'] && $sum['next']['due_date']
                && Carbon::parse($sum['next']['due_date'])->between($today, $today->copy()->addDays(3))) {
                [$title, $body] = $this->dueMessage($pt->language ?? 'ur', $child, $sum['next']);
            } else {
                continue;
            }

            $messages[] = [
                'to' => $pt->token,
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'data' => ['type' => 'vaccine_reminder', 'card_id' => $card->id],
            ];
        }

        if ($messages) {
            $push->send($messages);
        }
        $this->info(count($messages).' reminder(s) sent.');

        return self::SUCCESS;
    }

    /** @param array<int,string> $overdue */
    protected function overdueMessage(string $lang, ?string $child, array $overdue): array
    {
        $first = preg_replace('/\s*\(due.*$/', '', $overdue[0]);
        $who = $child ?: ($lang === 'ur' ? 'بچے' : 'the child');

        return match ($lang) {
            'ur' => ['ٹیکہ دوست — یاد دہانی', "{$who} کے ٹیکے واجب الادا ہیں ({$first} وغیرہ)۔ براہ کرم مکمل کروائیں۔"],
            default => ['Tika Dost — reminder', "{$who} has overdue vaccines ({$first} and more). Please complete them."],
        };
    }

    /** @param array{code:string,due_date:?string} $next */
    protected function dueMessage(string $lang, ?string $child, array $next): array
    {
        $who = $child ?: ($lang === 'ur' ? 'بچے' : 'the child');

        return match ($lang) {
            'ur' => ['ٹیکہ دوست — یاد دہانی', "{$who} کا اگلا ٹیکہ {$next['code']} ({$next['due_date']}) کو ہے۔"],
            default => ['Tika Dost — reminder', "{$who}'s next vaccine {$next['code']} is due on {$next['due_date']}."],
        };
    }
}
