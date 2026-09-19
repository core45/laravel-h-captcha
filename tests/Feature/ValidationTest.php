<?php

declare(strict_types=1);

use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Rules\HCaptcha;
use Core45\HCaptcha\Tests\Fixtures\CaptchaGuardedComponent;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

beforeEach(function (): void {
    app()->setLocale('en');
});

function fakeVerification(bool $success, array $overrides = []): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(array_merge([
            'success' => $success,
            'hostname' => 'example.test',
        ], $overrides)),
    ]);
}

it('passes validation for an accepted token', function (): void {
    fakeVerification(true);

    $validator = Validator::make(
        ['h-captcha-response' => TestCase::TEST_TOKEN],
        ['h-captcha-response' => ['required', new HCaptcha]],
    );

    expect($validator->passes())->toBeTrue();
});

it('fails validation with the translated message for a rejected token', function (): void {
    fakeVerification(false, ['error-codes' => ['bad-request']]);

    $validator = Validator::make(
        ['h-captcha-response' => 'nope'],
        ['h-captcha-response' => [new HCaptcha]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('h-captcha-response'))
        ->toBe(trans('hcaptcha::hcaptcha.failed'));
});

it('reports a missing token distinctly from a rejected one', function (): void {
    fakeVerification(true);

    $validator = Validator::make(
        ['h-captcha-response' => ''],
        ['h-captcha-response' => [new HCaptcha]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('h-captcha-response'))
        ->toBe(trans('hcaptcha::hcaptcha.missing'));

    Http::assertNothingSent();
});

/*
 * Pairing `required` with the rule is what the migration path from
 * buzz/laravel-h-captcha looks like, and what the Filament field does. Since
 * the rule is implicit it already handles an empty token, so this pins down
 * which of the two messages actually reaches the visitor.
 */
it('reports the required message, not the captcha message, when paired with required', function (): void {
    fakeVerification(true);

    $validator = Validator::make(
        ['h-captcha-response' => ''],
        ['h-captcha-response' => ['required', new HCaptcha]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->get('h-captcha-response'))
        ->toContain(trans('validation.required', ['attribute' => 'h-captcha-response']));

    Http::assertNothingSent();
});

it('supports the hcaptcha string rule', function (): void {
    fakeVerification(false);

    $validator = Validator::make(
        ['h-captcha-response' => 'nope'],
        ['h-captcha-response' => 'hcaptcha'],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('h-captcha-response'))
        ->toBe(trans('hcaptcha::hcaptcha.failed'));
});

/*
 * The string rule is registered with extendImplicit, so like the rule object it
 * fires on an empty token. Were it registered with plain extend, an empty
 * submission would skip the rule entirely and pass.
 */
it('fires the string rule on an empty token without needing required', function (): void {
    fakeVerification(true);

    $validator = Validator::make(
        ['h-captcha-response' => ''],
        ['h-captcha-response' => 'hcaptcha'],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('h-captcha-response'))
        ->toBe(trans('hcaptcha::hcaptcha.missing'));

    Http::assertNothingSent();
});

it('fires the string rule when the field is absent entirely', function (): void {
    fakeVerification(true);

    $validator = Validator::make([], ['h-captcha-response' => 'hcaptcha']);

    expect($validator->fails())->toBeTrue();

    Http::assertNothingSent();
});

it('supports the captcha alias for people migrating from buzz/laravel-h-captcha', function (): void {
    fakeVerification(true);

    $validator = Validator::make(
        ['h-captcha-response' => TestCase::TEST_TOKEN],
        ['h-captcha-response' => 'captcha'],
    );

    expect($validator->passes())->toBeTrue();
});

/*
 * A route guarded by both the middleware and the rule is the realistic
 * worst case for a single-use token. It must still cost exactly one call.
 */
/*
 * The string rule must share the rule object's and the middleware's scope, or
 * stacking any two of them on one field double-spends a single-use token and
 * the visitor sees a failure they cannot act on.
 */
it('spends the token once when the middleware and the string rule both run', function (): void {
    fakeVerification(true);

    Route::post('/string-rule-guarded', function (): string {
        request()->validate(['h-captcha-response' => 'hcaptcha']);

        return 'ok';
    })->middleware(['hcaptcha']);

    $this->post('/string-rule-guarded', ['h-captcha-response' => TestCase::TEST_TOKEN])
        ->assertOk();

    Http::assertSentCount(1);
});

it('spends the token once when the middleware and the rule both run', function (): void {
    fakeVerification(true);

    Route::post('/double-guarded', function (): string {
        request()->validate([
            'h-captcha-response' => ['required', new HCaptcha],
        ]);

        return 'ok';
    })->middleware(['hcaptcha']);

    $this->post('/double-guarded', ['h-captcha-response' => TestCase::TEST_TOKEN])
        ->assertOk();

    Http::assertSentCount(1);
});

/*
 * Livewire handles up to 200 components in one HTTP request, and the verifier
 * is request-scoped, so two components validating the same property name
 * share one memo. Without the action in the scope, the second component rode
 * on the first one's solved captcha. With it, the second pays a real request
 * and hCaptcha rejects the spent token.
 */
it('does not let a second Livewire component reuse a verdict for the same property name', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::sequence()
            ->push(['success' => true, 'hostname' => 'localhost'])
            ->push(['success' => false, 'error-codes' => ['already-seen-response']]),
    ]);

    Livewire::test(CaptchaGuardedComponent::class)
        ->set('captcha', TestCase::TEST_TOKEN)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    Livewire::test(CaptchaGuardedComponent::class)
        ->set('captcha', TestCase::TEST_TOKEN)
        ->call('submit')
        ->assertHasErrors(['captcha'])
        ->assertSet('submitted', false);

    Http::assertSentCount(2);
});

it('still spends the token once when one Livewire component validates the same field twice', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'localhost']),
    ]);

    Livewire::test(CaptchaGuardedComponent::class)
        ->set('captcha', TestCase::TEST_TOKEN)
        ->call('submitTwice')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    Http::assertSentCount(1);
});

it('tells the browser to reset the widget after a token was verified inside Livewire', function (): void {
    Http::fake(['api.hcaptcha.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    Livewire::test(CaptchaGuardedComponent::class)
        ->set('captcha', 'spent-token')
        ->call('submit')
        ->assertHasErrors('captcha')
        ->assertDispatched(HCaptchaManager::RESET_EVENT, field: 'captcha');
});

it('also resets after a successful verification, because the token is spent either way', function (): void {
    Http::fake(['api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'localhost'])]);
    config()->set('hcaptcha.hostnames', 'localhost');

    Livewire::test(CaptchaGuardedComponent::class)
        ->set('captcha', 'good-token')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched(HCaptchaManager::RESET_EVENT, field: 'captcha');
});

it('does not dispatch a reset when no token was submitted', function (): void {
    Livewire::test(CaptchaGuardedComponent::class)
        ->call('submit')
        ->assertHasErrors('captcha')
        ->assertNotDispatched(HCaptchaManager::RESET_EVENT);
});

it('sends an explicit expected sitekey from the rule object', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'localhost']),
    ]);

    validator(
        ['h-captcha-response' => TestCase::TEST_TOKEN],
        ['h-captcha-response' => [new HCaptcha(sitekey: '20000000-ffff-ffff-ffff-000000000002')]],
    )->validate();

    Http::assertSent(fn (Request $request): bool => $request['sitekey'] === '20000000-ffff-ffff-ffff-000000000002');
});
