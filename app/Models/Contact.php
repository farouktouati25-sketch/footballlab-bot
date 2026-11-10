<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    protected $fillable = [
        'wa_id', 'name', 'last_inbound_at', 'bot_paused_until', 'act_as_client', 'first_referral', 'source', 'reminders_opted_out_at', 'player_name', 'preferred_days',
        'handoff_at', 'handoff_reason', 'handoff_reminders', 'saved_at',
    ];

    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
            'bot_paused_until' => 'datetime',
            'saved_at' => 'datetime',
            'reminders_opted_out_at' => 'datetime',
            'handoff_at' => 'datetime',
            'handoff_reminders' => 'integer',
            'preferred_days' => 'array',
            'act_as_client' => 'boolean',
            'first_referral' => 'array',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->wa_id, config('whatsapp.admins', []), true);
    }

    public function adminName(): string
    {
        $index = array_search($this->wa_id, config('whatsapp.admins', []), true);
        $names = config('whatsapp.admin_names', []);

        return ($index !== false && ! empty($names[$index])) ? $names[$index] : ($this->name ?: '+'.$this->wa_id);
    }

    /** Free-form messages are only allowed within 24h of the contact's last inbound message. */
    public function windowIsOpen(): bool
    {
        return $this->last_inbound_at !== null
            && $this->last_inbound_at->gt(now()->subMinutes((int) config('whatsapp.window_minutes', 1420)));
    }

    public function botIsPaused(): bool
    {
        return $this->isSaved() || ($this->bot_paused_until !== null && $this->bot_paused_until->isFuture());
    }

    /** Saved in the admins' phone (an old client): the bot never replies or messages them; the team does. */
    public function isSaved(): bool
    {
        return $this->saved_at !== null && ! $this->isAdmin();
    }

    public function displayName(): string
    {
        return $this->name ?: '+'.$this->wa_id;
    }
}
