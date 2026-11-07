<?php

namespace App\Jobs;

use App\Services\InboundRouter;
use App\Services\WhatsApp\InboundMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ProcessInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 150;

    public function __construct(public array $message, public array $value = []) {}

    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(InboundRouter $router): void
    {
        $inbound = InboundMessage::fromWebhook($this->message, $this->value);
        if ($inbound->id === '' || $inbound->from === '') {
            return;
        }

        $recorded = $router->record($inbound);
        if (! $recorded) {
            return; // duplicate delivery, already handled
        }
        [$contact, $message] = $recorded;

        // One message at a time per contact, so replies stay in order and bookings don't race.
        Cache::lock('contact:'.$inbound->from, 180)
            ->block(60, fn () => $router->process($contact, $message, $inbound));
    }
}
