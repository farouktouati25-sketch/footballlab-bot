<?php

use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\ZiinaWebhookController;
use Illuminate\Support\Facades\Route;

// Callback URL for Meta: https://your-domain/api/webhooks/whatsapp
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);

// Ziina payment events (register with: php artisan ziina:set-webhook)
Route::post('/webhooks/ziina', ZiinaWebhookController::class);
