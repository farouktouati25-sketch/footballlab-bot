<?php

namespace App\Console\Commands;

use App\Services\Payments\ZiinaClient;
use Illuminate\Console\Command;

class ZiinaSetWebhook extends Command
{
    protected $signature = 'ziina:set-webhook {--url= : Defaults to APP_URL/api/webhooks/ziina}';

    protected $description = 'Register this app as the Ziina webhook (payment completed events)';

    public function handle(ZiinaClient $ziina): int
    {
        $secret = (string) config('fbl.payments.ziina_webhook_secret');
        if (! $ziina->enabled() || strlen($secret) < 24) {
            $this->error('Set ZIINA_API_TOKEN and ZIINA_WEBHOOK_SECRET (24+ random characters) first.');

            return self::FAILURE;
        }

        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/api/webhooks/ziina';
        if (! $ziina->registerWebhook($url, $secret)) {
            $this->error('Ziina refused the webhook. Check that the token has the write_webhooks permission, and the logs.');

            return self::FAILURE;
        }

        $this->info("Ziina webhook set to {$url}");

        return self::SUCCESS;
    }
}
