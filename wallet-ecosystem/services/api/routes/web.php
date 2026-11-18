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

    Route::post('/mock-bank/complete', static function (\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\Response {
        $ref     = $request->input('ref', '');
        $outcome = $request->input('outcome', 'success');
        abort_unless(is_string($ref) && \Ramsey\Uuid\Uuid::isValid($ref), 404, 'Mock payment attempt was not found.');
        abort_unless(in_array($outcome, ['success', 'failed'], true), 422, 'Mock payment outcome is invalid.');
        $attempt = \Illuminate\Support\Facades\DB::connection('payment')->table('payment.payment_attempts')
            ->where('id', $ref)->first(['payment_intent_id', 'provider_account_id', 'provider_code']);
        abort_if($attempt === null || $attempt->provider_code !== 'mock_bank', 404, 'Mock payment attempt was not found.');
        $intent = \Illuminate\Support\Facades\DB::connection('payment')->table('payment.payment_intents')
            ->where('id', $attempt->payment_intent_id)->first(['amount_minor', 'currency_code']);
        $account = \Illuminate\Support\Facades\DB::connection('payment')->table('payment.payment_provider_accounts')
            ->where('id', $attempt->provider_account_id)->first(['merchant_account_reference']);
        abort_if($intent === null || $account === null, 404, 'Mock payment attempt was not found.');
        $secret  = config('payment.mock_bank.callback_secret', 'mock-test-secret');

        $timestamp = (string) time();
        $payload   = json_encode([
            'event_id' => $ref.':'.$timestamp,
            'transaction_ref' => $ref,
            'merchant_account' => $account->merchant_account_reference,
            'amount' => (int) $intent->amount_minor,
            'currency' => (string) $intent->currency_code,
            'status'          => $outcome,
            'timestamp'       => $timestamp,
        ]);

        if ($payload === false) {
            return response()->json(['error' => 'Failed to encode payload'], 500);
        }

        $signature = hash_hmac('sha256', $payload, $secret);

        // Dispatch through the same callback controller without making an HTTP
        // request back to this server. PHP's local development server can handle
        // only one request at a time, so a self-request deadlocks until timeout.
        $callbackRequest = \Illuminate\Http\Request::create(
            '/api/v1/payment/callbacks/mock_bank',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_MOCK_BANK_SIGNATURE' => $signature,
                'REMOTE_ADDR' => $request->ip() ?: '127.0.0.1',
            ],
            $payload,
        );
        $callbackResponse = app(\App\Modules\Payment\Http\Controllers\PaymentCallbackController::class)
            ->store($callbackRequest, 'mock_bank');
        $callbackData = $callbackResponse->getData(true);

        // In a local demo, deliver only this intent's Payment confirmation so
        // the durable Events inbox can issue tickets without a scheduler process.
        if (app()->environment('local')
            && $callbackResponse->getStatusCode() >= 200
            && $callbackResponse->getStatusCode() < 300) {
            \App\Jobs\OutboxDispatcherJob::dispatchSync('payment.confirmed', (string) $attempt->payment_intent_id, true);
        }

        $result = [
            'mock'             => true,
            'ref'              => $ref,
            'outcome'          => $outcome,
            'callback_status'  => $callbackResponse->getStatusCode(),
            'receipt_id'       => $callbackData['receipt_id'] ?? null,
        ];

        // Keep JSON available for API/manual diagnostics. Browser form submissions
        // need a human-readable completion page; the bank page is commonly opened
        // in a separate tab, so return focus to checkout and close this tab when
        // the browser permits it. Checkout polls Payment status independently.
        if ($request->expectsJson()) {
            return response()->json($result);
        }

        $callbackAccepted = $callbackResponse->getStatusCode() >= 200
            && $callbackResponse->getStatusCode() < 300;
        $title = $callbackAccepted ? 'Payment submitted' : 'Payment not confirmed';
        $message = $callbackAccepted
            ? 'The mock bank sent your result. Return to checkout; it will refresh the payment status.'
            : 'The mock bank could not confirm this payment. Return to checkout and check its status.';
        $safeTitle = e($title);
        $safeMessage = e($message);
        $safeRef = e($ref);
        $html = <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>{$safeTitle}</title><style>
        body{font:16px system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:24px;color:#172033}
        main{border:1px solid #dbe2ea;border-radius:16px;padding:28px;box-shadow:0 8px 30px #17203312}
        a{display:inline-block;margin-top:16px;padding:12px 18px;border-radius:9px;background:#155eef;color:white;text-decoration:none}
        code{overflow-wrap:anywhere}
        </style></head><body><main><h1>{$safeTitle}</h1><p>{$safeMessage}</p>
        <p>Payment reference: <code>{$safeRef}</code></p>
        <a id="return-link" href="#" hidden onclick="window.opener&&window.opener.focus();window.close();return false;">Return to checkout</a>
        <p id="return-note">Switch back to the checkout tab or app. It checks the payment status automatically.</p>
        </main><script>
        if(window.opener){document.getElementById('return-link').hidden=false;document.getElementById('return-note').hidden=true;window.opener.focus();setTimeout(function(){window.close();},700);}
        </script></body></html>
        HTML;

        return response($html, $callbackAccepted ? 200 : 502)->header('Content-Type', 'text/html; charset=UTF-8');
    })->middleware('web')->name('mock-bank.complete');
}
