<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZiinaWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentService $payments)
    {
        $secret = (string) config('fbl.payments.ziina_webhook_secret');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($expected, strtolower((string) $request->header('X-Hmac-Signature', '')))) {
            Log::warning('Ziina webhook rejected: bad signature');

            return response('Invalid signature', 401);
        }

        $event = $request->input('event');
        $data = (array) $request->input('data', []);

        if ($event === 'payment_intent.status.updated' && ($data['status'] ?? null) === 'completed' && ! empty($data['id'])) {
            $payments->markPaidByIntent((string) $data['id']);
        }

        return response('OK', 200);
    }
}
