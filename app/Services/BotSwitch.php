<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Global on/off switch, flipped by admins with "bot off" / "bot on" on WhatsApp.
 * While off: clients get no automatic replies and timed messages (housekeeping, reminders) stop.
 * Admin commands keep working, so "bot on" can always be sent.
 */
class BotSwitch
{
    private const KEY = 'fbl:bot_off';

    public static function isOff(): bool
    {
        return Cache::get(self::KEY) !== null;
    }

    /** @return array{by:string, at:string}|null */
    public static function offInfo(): ?array
    {
        $v = Cache::get(self::KEY);

        return is_array($v) ? $v : null;
    }

    public static function turnOff(string $by): void
    {
        Cache::forever(self::KEY, ['by' => $by, 'at' => now()->toIso8601String()]);
    }

    public static function turnOn(): void
    {
        Cache::forget(self::KEY);
    }
}
