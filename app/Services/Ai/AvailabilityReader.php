<?php

namespace App\Services\Ai;

use App\Models\Contact;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reads a player's recent messages for "I'm injured / travelling / not available" and remembers it,
 * so suggestions leave them out until they're back.
 */
class AvailabilityReader
{
    // Cheap first pass: only chats with one of these words are read by Claude.
    private const HINTS = '/injur|hurt|pain|knee|ankle|hamstring|sick|ill\b|surgery|travel|abroad|vacation|holiday|leaving|away|flight|not available|unavailable|can\'?t (play|make|come)|cannot (play|make|come)|busy|next month|after ramadan|'
        .'مصاب|إصابة|اصابة|مسافر|سفر|مريض|مشغول|ما نقدر|مانقدرش|'
        .'bless[ée]|voyage|vacances|malade|pas dispo|indispo/iu';

    public function __construct(private readonly ClaudeClient $claude) {}

    /** Check new messages from this contact (once per message). Returns true if they're unavailable now. */
    public function check(Contact $contact): bool
    {
        $new = Message::where('contact_id', $contact->id)->where('direction', 'in')
            ->when($contact->availability_checked_message_id, fn ($q, $id) => $q->where('id', '>', $id))
            ->where('created_at', '>', now()->subDays(21))
            ->orderBy('id')->get(['id', 'body', 'created_at']);
        if ($new->isEmpty()) {
            return $this->isUnavailable($contact);
        }
        $contact->update(['availability_checked_message_id' => $new->max('id')]);

        $hits = $new->filter(fn ($m) => preg_match(self::HINTS, (string) $m->body));
        if ($hits->isEmpty()) {
            return $this->isUnavailable($contact);
        }

        $tz = config('fbl.timezone');
        $chat = $contact->messages()->where('created_at', '>', now()->subDays(21))->orderBy('id')->limit(40)->get(['direction', 'author', 'body', 'created_at'])
            ->map(fn ($m) => $m->created_at->setTimezone($tz)->format('D j M').' '.($m->direction === 'in' ? 'PLAYER' : 'US').': '.mb_substr((string) $m->body, 0, 300))
            ->implode("\n");

        try {
            $out = ClaudeClient::text($this->claude->messages(
                'You read a football player\'s WhatsApp chat with the organisers and decide if the player said they can\'t play for a while '
                .'(injured, sick, travelling, away, busy for weeks…). Today is '.now($tz)->format('l j F Y').'. '
                .'Answer ONLY with JSON: {"unavailable": true|false, "until": "YYYY-MM-DD" or null, "reason": "2-4 words"}. '
                .'until = the date they said they are back; if they gave no date use null. unavailable=false if it was only about one game or they are already back.',
                [['role' => 'user', 'content' => $chat]], [], null, 150));
            $json = json_decode((string) (preg_match('/\{.*\}/s', $out, $m) ? $m[0] : ''), true);
        } catch (\Throwable $e) {
            Log::warning('Availability check failed', ['contact' => $contact->wa_id, 'error' => $e->getMessage()]);

            return $this->isUnavailable($contact);
        }

        if (! empty($json['unavailable'])) {
            $until = null;
            try {
                $until = ! empty($json['until']) ? Carbon::parse($json['until'], $tz) : null;
            } catch (\Throwable) {
            }
            // No date given: leave them out for 2 weeks after they said it.
            $until ??= Carbon::parse($hits->last()->created_at)->setTimezone($tz)->addDays(14);
            $contact->update(['unavailable_until' => $until->toDateString(), 'unavailable_reason' => mb_substr((string) ($json['reason'] ?? 'not available'), 0, 120)]);
        } elseif (isset($json['unavailable']) && $contact->unavailable_until) {
            $contact->update(['unavailable_until' => null, 'unavailable_reason' => null]); // they said they're back
        }

        return $this->isUnavailable($contact->fresh());
    }

    public function isUnavailable(Contact $contact, ?string $onDay = null): bool
    {
        return $contact->unavailable_until && $contact->unavailable_until->toDateString() >= ($onDay ?? now(config('fbl.timezone'))->toDateString());
    }
}
