<?php

declare(strict_types=1);

use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Support\Credentials;
use Illuminate\Support\Facades\Artisan;

/**
 * A profile inherits the global credential for whichever half it does not
 * supply. "Does not supply" has to mean the same thing everywhere: a blank
 * `.env` value is not a credential, and if the resolver kept it while the
 * widget's key lookup discarded it, the page would render one sitekey and the
 * verification would run against another account's secret.
 */
beforeEach(function (): void {
    config()->set('hcaptcha.sitekey', 'GLOBAL-SITEKEY');
    config()->set('hcaptcha.secret', 'GLOBAL-SECRET');
});

it('treats a blank profile sitekey as unset in both the resolver and the widget lookup', function (): void {
    config()->set('hcaptcha.profiles.marketing', [
        'sitekey' => '',
        'secret' => 'MARKETING-SECRET',
    ]);

    $credentials = new Credentials(config());
    $manager = app(HCaptchaManager::class);

    expect($credentials->for('marketing')['sitekey'])->toBe('GLOBAL-SITEKEY')
        ->and($manager->profileSitekey('marketing'))->toBe('GLOBAL-SITEKEY');
});

it('treats a placeholder profile secret as unset', function (): void {
    config()->set('hcaptcha.profiles.marketing', ['secret' => 'default_secret']);

    expect((new Credentials(config()))->for('marketing')['secret'])->toBe('GLOBAL-SECRET');
});

it('knows which half a profile supplies itself', function (): void {
    config()->set('hcaptcha.profiles.marketing', [
        'sitekey' => 'MARKETING-SITEKEY',
        'secret' => '',
    ]);

    $credentials = new Credentials(config());

    expect($credentials->declaresOwn('marketing', 'sitekey'))->toBeTrue()
        ->and($credentials->declaresOwn('marketing', 'secret'))->toBeFalse()
        // No profile means the global pair, which is its own by definition.
        ->and($credentials->declaresOwn(null, 'secret'))->toBeTrue();
});

it('warns in the doctor when a profile overrides one half and inherits the other', function (): void {
    config()->set('hcaptcha.profiles.marketing', ['sitekey' => 'MARKETING-SITEKEY']);

    Artisan::call('hcaptcha:doctor');

    expect(Artisan::output())->toContain('overrides the sitekey but inherits the global secret');
});

it('does not warn when a profile supplies both halves', function (): void {
    config()->set('hcaptcha.profiles.marketing', [
        'sitekey' => 'MARKETING-SITEKEY',
        'secret' => 'MARKETING-SECRET',
    ]);

    Artisan::call('hcaptcha:doctor');

    expect(Artisan::output())->not->toContain('inherits the global');
});
