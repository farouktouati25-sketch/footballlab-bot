<?php

namespace App\Providers;

use App\Services\Ai\ClaudeClient;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppClient::class, fn () => new WhatsAppClient(
            token: (string) config('whatsapp.token'),
            phoneNumberId: (string) config('whatsapp.phone_number_id'),
            baseUrl: (string) config('whatsapp.api_base'),
            graphVersion: (string) config('whatsapp.graph_version'),
            provider: (string) config('whatsapp.provider'),
        ));

        $this->app->singleton(ClaudeClient::class, fn () => new ClaudeClient(
            apiKey: (string) config('claude.api_key'),
            model: (string) config('claude.model'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
