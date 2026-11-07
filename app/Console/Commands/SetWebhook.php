<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Console\Command;

class SetWebhook extends Command
{
    protected $signature = 'whatsapp:set-webhook {--url= : Defaults to APP_URL/api/webhooks/whatsapp}';

    protected $description = '360dialog: point the number\'s webhook at this app (with the shared secret header)';

    public function handle(WhatsAppClient $client): int
    {
        if (! $client->is360()) {
            $this->warn('WHATSAPP_PROVIDER is "meta": set the webhook in the Meta app dashboard instead (see README).');

            return self::FAILURE;
        }

        $secret = (string) config('whatsapp.webhook_secret');
        if (strlen($secret) < 24) {
            $this->error('Set WHATSAPP_WEBHOOK_SECRET to a random string of 24+ characters first.');

            return self::FAILURE;
        }

        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/api/webhooks/whatsapp';
        if (! str_starts_with($url, 'https://')) {
            $this->error("Webhook URL must be HTTPS: {$url}");

            return self::FAILURE;
        }

        if (! $client->configureWebhook($url, $secret)) {
            $this->error('360dialog refused the webhook config. Check WHATSAPP_TOKEN (your 360dialog API key) and storage/logs/laravel.log.');

            return self::FAILURE;
        }

        $this->info("Webhook set to {$url}");

        return self::SUCCESS;
    }
}
