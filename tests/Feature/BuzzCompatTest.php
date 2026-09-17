<?php

declare(strict_types=1);

use Core45\HCaptcha\Compat\CaptchaCompat;
use Core45\HCaptcha\Exceptions\MissingSecretException;
use Core45\HCaptcha\Exceptions\MissingSitekeyException;
use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

function captcha(): CaptchaCompat
{
    return app('captcha');
}

function fakeCompatVerification(bool $success = true): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response([
            'success' => $success,
            'hostname' => 'example.test',
        ]),
    ]);
}

it('resolves the legacy captcha binding', function (): void {
    expect(app('captcha'))->toBeInstanceOf(CaptchaCompat::class);
});

/*
 * thinhbuzz/laravel-h-captcha callers write `if (Captcha::verify(...))`, so the
 * return type has to stay a plain bool rather than becoming a truthy object.
 */
it('returns a real bool from verify, not a result object', function (): void {
    fakeCompatVerification();

    $result = captcha()->verify(TestCase::TEST_TOKEN, '203.0.113.1');

    expect($result)->toBeBool()->toBeTrue();
});

it('returns false from verify when hCaptcha rejects the token', function (): void {
    fakeCompatVerification(success: false);

    expect(captcha()->verify('nope'))->toBeBool()->toBeFalse();
});

it('returns false from verify when no token was submitted', function (): void {
    fakeCompatVerification();

    expect(captcha()->verify(null))->toBeFalse();

    Http::assertNothingSent();
});

it('renders a widget through display()', function (): void {
    $html = (string) captcha()->display();

    expect($html)
        ->toContain('data-hcaptcha')
        ->toContain('data-sitekey="'.TestCase::TEST_SITEKEY.'"')
        ->toContain('js.hcaptcha.com');
});

it('honours the add-js pseudo attribute by omitting the script', function (): void {
    $html = (string) captcha()->display(['add-js' => false]);

    expect($html)
        ->toContain('data-hcaptcha')
        ->not->toContain('js.hcaptcha.com')
        ->not->toContain('add-js');
});

it('passes display attributes through as widget data attributes', function (): void {
    $html = (string) captcha()->display(['theme' => 'dark', 'id' => 'legacy-widget']);

    expect($html)
        ->toContain('data-theme="dark"')
        ->toContain('id="legacy-widget"')
        ->toContain('id="legacy-widget-response"');
});

it('takes the language from the options array', function (): void {
    expect((string) captcha()->display([], ['lang' => 'de']))->toContain('hl=de');
});

it('emits only a script tag from displayJs()', function (): void {
    $html = (string) captcha()->displayJs();

    expect($html)
        ->toContain('<script src="https://js.hcaptcha.com/1/api.js')
        ->toContain('async defer')
        ->not->toContain('data-hcaptcha');
});

/*
 * Multiple widgets already work: each renders explicitly and the bootstrap
 * script renders every container. displayMultiple() exists so old calls neither
 * fatal nor double-render.
 */
it('returns nothing from displayMultiple', function (): void {
    expect((string) captcha()->displayMultiple())->toBe('');
});

it('records the multiple flag', function (): void {
    captcha()->multiple();

    expect(config('captcha.options.multiple'))->toBeTrue();
});

/*
 * setOptions() replaces the whole captcha.options array rather than merging
 * into it, which is what the reference did -- so it also clears any flag set
 * by multiple(). Kept faithful deliberately.
 */
it('applies a language set through setOptions and replaces the options array', function (): void {
    captcha()->multiple();
    captcha()->setOptions(['lang' => 'fr']);

    expect((string) captcha()->display())->toContain('hl=fr')
        ->and(config('captcha.options.multiple'))->toBeNull();
});

it('exposes the js helper names', function (): void {
    expect(captcha()->getWidgetIdName())->toBeString()->not->toBe('')
        ->and(captcha()->getJsVariableName())->toBe('hcaptcha');
});

it('keeps the captcha string rule working', function (): void {
    fakeCompatVerification(success: false);

    $validator = Validator::make(
        ['h-captcha-response' => 'nope'],
        ['h-captcha-response' => 'captcha'],
    );

    expect($validator->fails())->toBeTrue();
});

describe('credentials', function (): void {
    /*
     * The old package defaulted to these literals, so a half-configured .env
     * carries them. Accepting them would reject every visitor with no
     * explanation -- which is exactly how that package behaved.
     */
    it('treats the legacy placeholder sitekey as not configured', function (): void {
        config()->set('hcaptcha.sitekey', 'default_sitekey');

        expect(app(HCaptchaManager::class)->configured())->toBeFalse();

        app(HCaptchaManager::class)->sitekey();
    })->throws(MissingSitekeyException::class);

    it('treats the legacy placeholder secret as not configured', function (): void {
        config()->set('hcaptcha.secret', 'default_secret');
        fakeCompatVerification();

        captcha()->verify(TestCase::TEST_TOKEN);
    })->throws(MissingSecretException::class);

    it('accepts a real credential', function (): void {
        expect(HCaptchaManager::isUsableCredential(TestCase::TEST_SITEKEY))->toBeTrue()
            ->and(HCaptchaManager::isUsableCredential('default_secret'))->toBeFalse()
            ->and(HCaptchaManager::isUsableCredential('  '))->toBeFalse()
            ->and(HCaptchaManager::isUsableCredential(null))->toBeFalse();
    });
});
