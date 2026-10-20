<?php

declare(strict_types=1);

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// T258 — Handler.php renders ThrottleRequestsException as 429 RATE_LIMIT_EXCEEDED
// ---------------------------------------------------------------------------

describe('Handler — ThrottleRequestsException → 429 (T258)', function (): void {

    it('renders ThrottleRequestsException as 429 RATE_LIMIT_EXCEEDED with Retry-After header', function (): void {
        Route::get('/_test/throttle-258', function (): never {
            throw new ThrottleRequestsException(60);
        });

        $response = $this->getJson('/_test/throttle-258');

        $response->assertStatus(429);
        expect($response->json('success'))->toBeFalse();
        expect($response->json('data'))->toBeNull();
        expect($response->json('error.code'))->toBe('RATE_LIMIT_EXCEEDED');
        expect($response->json('error.message'))->toBe('Too many requests — please slow down.');
        expect($response->headers->has('Retry-After'))->toBeTrue();
        expect((int) $response->headers->get('Retry-After'))->toBe(60);
    });

    it('does not include PII or internal details in 429 error response', function (): void {
        Route::get('/_test/throttle-258-clean', function (): never {
            throw new ThrottleRequestsException(30);
        });

        $response = $this->getJson('/_test/throttle-258-clean');

        $response->assertStatus(429);

        $body = $response->json();
        expect($body)->toHaveKey('success');
        expect($body)->toHaveKey('data');
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('correlation_id');
        // No stack trace, no file paths, no internal detail keys
        expect($body)->not->toHaveKey('exception');
        expect($body)->not->toHaveKey('trace');
        expect($body)->not->toHaveKey('file');
    });

    it('previously returned 500 for throttled requests (regression guard)', function (): void {
        // Confirm the exception is now handled explicitly — not falling through to internalError()
        Route::get('/_test/throttle-258-regression', function (): never {
            throw new ThrottleRequestsException(120);
        });

        $response = $this->getJson('/_test/throttle-258-regression');

        // Must be 429, never 500
        expect($response->status())->not->toBe(500);
        $response->assertStatus(429);
    });

});
