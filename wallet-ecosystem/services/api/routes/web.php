<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * T111: Mock bank local HTTP handler — DEV/TEST ONLY.
 * GET  /mock-bank/checkout?ref={attemptId} — renders simple HTML checkout page
 * POST /mock-bank/complete                 — triggers mock callback to payment callbacks endpoint
 *
 * These routes are permanently disabled in production and staging.
 */
if (! app()->isProduction() && ! app()->environment('staging')) {
    Route::get('/mock-bank/checkout', static function (\Illuminate\Http\Request $request): \Illuminate\Http\Response {
        $ref = $request->query('ref', '');
        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>[DEVELOPMENT MOCK] Mock Bank Checkout</title>
            <style>
                body { font-family: sans-serif; max-width: 500px; margin: 80px auto; padding: 20px; }
                .banner { background: #ff0; border: 2px solid #c00; padding: 8px; margin-bottom: 16px; }
                .btn { padding: 12px 24px; font-size: 16px; cursor: pointer; }
                .btn-success { background: #28a745; color: #fff; border: none; }
                .btn-fail    { background: #dc3545; color: #fff; border: none; margin-left: 8px; }
            </style>
        </head>
        <body>
            <div class="banner">⚠️ DEVELOPMENT MOCK — NOT FOR PRODUCTION USE ⚠️</div>
            <h2>Mock Bank Checkout</h2>
            <p>Reference: <code>{$ref}</code></p>
            <form method="POST" action="/mock-bank/complete">
                <input type="hidden" name="_token" value="{csrf_field_value}">
                <input type="hidden" name="ref" value="{$ref}">
                <input type="hidden" name="outcome" value="success">
                <button type="submit" class="btn btn-success" onclick="this.form.outcome.value='success'">
                    ✓ Complete Payment
                </button>
                <button type="submit" class="btn btn-fail" onclick="this.form.outcome.value='failed'">
                    ✗ Fail Payment
                </button>
            </form>
        </body>
        </html>
        HTML;

        // Replace placeholder with actual CSRF token
        $html = str_replace('{csrf_field_value}', csrf_token(), $html);

        return new \Illuminate\Http\Response($html, 200, ['Content-Type' => 'text/html']);
    })->middleware('web')->name('mock-bank.checkout');

    Route::post('/mock-bank/complete', static function (\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse {
        $ref     = $request->input('ref', '');
        $outcome = $request->input('outcome', 'success');
        $secret  = config('payment.mock_bank.callback_secret', 'mock-test-secret');

        $timestamp = (string) time();
        $payload   = json_encode([
            'transaction_ref' => $ref,
            'status'          => $outcome,
            'timestamp'       => $timestamp,
            'amount'          => 0,
            'currency'        => 'BWP',
        ]);

        if ($payload === false) {
            return response()->json(['error' => 'Failed to encode payload'], 500);
        }

        $signature = hash_hmac('sha256', $payload, $secret);

        // Post the callback internally
        $callbackUrl = url('/api/v1/payment/callbacks/mock_bank');

        $httpClient = new \Illuminate\Http\Client\Factory();
        $response   = $httpClient->withHeaders([
            'Content-Type'          => 'application/json',
            'X-Mock-Bank-Signature' => $signature,
        ])->post($callbackUrl, json_decode($payload, true) ?? []);

        return response()->json([
            'mock'             => true,
            'ref'              => $ref,
            'outcome'          => $outcome,
            'callback_status'  => $response->status(),
        ]);
    })->middleware('web')->name('mock-bank.complete');
}
