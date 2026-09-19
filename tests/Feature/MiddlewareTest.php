<?php

declare(strict_types=1);

use Core45\HCaptcha\Rules\HCaptcha;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    app()->setLocale('en');

    Route::middleware('hcaptcha')->group(function (): void {
        Route::post('/guarded', fn (): string => 'ok');
        Route::get('/guarded', fn (): string => 'form');
        Route::post('/guarded-custom-field/{field?}', fn (): string => 'ok')
            ->middleware('hcaptcha:my_token')
            ->withoutMiddleware('hcaptcha');
    });
});

function acceptToken(): void
{
    Http::fake(['api.hcaptcha.com/*' => Http::response(['success' => true])]);
}

function rejectToken(array $errorCodes = ['bad-request']): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response([
            'success' => false,
            'error-codes' => $errorCodes,
        ]),
    ]);
}

it('lets a verified request through', function (): void {
    acceptToken();

    $this->post('/guarded', ['h-captcha-response' => TestCase::TEST_TOKEN])
        ->assertOk()
        ->assertSee('ok');
});

it('rejects an unverified request as a validation error, not a server error', function (): void {
    rejectToken();

    $this->postJson('/guarded', ['h-captcha-response' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('h-captcha-response');
});

it('rejects a request with no token at all as 422', function (): void {
    acceptToken();

    $this->postJson('/guarded', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('h-captcha-response');

    Http::assertNothingSent();
});

/*
 * The middleware has no method allowlist, on purpose. One keyed on
 * $request->method() is spoofable: Symfony's _method override refuses only GET,
 * HEAD, CONNECT and TRACE (vendor/symfony/http-foundation/Request.php,
 * getMethod()), so a POST carrying _method=OPTIONS reports OPTIONS. Under a
 * skip-list that request bypassed the captcha entirely while the controller
 * still received the full POST body.
 */
it('cannot be bypassed by overriding the method to OPTIONS', function (): void {
    rejectToken();

    // Route::any() is what makes the vector reachable: it registers OPTIONS, so
    // no Allow-header stub intercepts the request and the action would have run
    // on the full POST body. _method must be a form field, not JSON -- the
    // override reads the request ParameterBag.
    Route::any('/any-guarded', fn (): string => 'reached the action')
        ->middleware('hcaptcha');

    $this->post('/any-guarded', [
        '_method' => 'OPTIONS',
        'h-captcha-response' => 'nope',
    ])->assertDontSee('reached the action');
});

it('guards every method it is applied to, including GET', function (): void {
    rejectToken();

    // The cost of having no skip-list: applied to a route group that also
    // serves the form's GET, that page fails too. That is the documented
    // contract -- put the middleware on the state-changing route only. A route
    // that breaks loudly in development beats one silently bypassable in
    // production.
    $this->getJson('/guarded')->assertStatus(422);
});

it('reads the token from a field named by the middleware parameter', function (): void {
    acceptToken();

    $this->post('/guarded-custom-field', ['my_token' => TestCase::TEST_TOKEN])
        ->assertOk();

    Http::assertSent(fn ($request): bool => $request['response'] === TestCase::TEST_TOKEN);
});

it('reports an outage as a validation error rather than a 500', function (): void {
    Http::fake(['api.hcaptcha.com/*' => Http::response('down', 503)]);

    $this->postJson('/guarded', ['h-captcha-response' => TestCase::TEST_TOKEN])
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.h-captcha-response.0',
            trans('hcaptcha::hcaptcha.unavailable'),
        );
});

it('reads a field whose name contains a dot as a literal key', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'localhost']),
    ]);

    Route::post('/dotted', fn () => response()->json(['ok' => true]))
        ->middleware('hcaptcha:my.captcha');

    $this->postJson('/dotted', ['my.captcha' => TestCase::TEST_TOKEN])
        ->assertOk();

    Http::assertSentCount(1);
});

it('shares one verdict with a rule that names the same explicit sitekey', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'localhost']),
    ]);

    Route::post('/keyed', function (Request $request) {
        $request->validate(['h-captcha-response' => [new HCaptcha(sitekey: '20000000-ffff-ffff-ffff-000000000002')]]);

        return response()->json(['ok' => true]);
    })->middleware('hcaptcha:h-captcha-response,20000000-ffff-ffff-ffff-000000000002');

    $this->postJson('/keyed', ['h-captcha-response' => TestCase::TEST_TOKEN])
        ->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(fn (Illuminate\Http\Client\Request $request): bool => $request['sitekey'] === '20000000-ffff-ffff-ffff-000000000002');
});

it('falls back to dot notation for a nested body when no literal key matches', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'localhost']),
    ]);

    Route::post('/nested', fn () => response()->json(['ok' => true]))
        ->middleware('hcaptcha:my.captcha');

    $this->postJson('/nested', ['my' => ['captcha' => TestCase::TEST_TOKEN]])
        ->assertOk();

    Http::assertSentCount(1);
});
