<?php

declare(strict_types=1);

use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

it('exits successfully with a usable sitekey and secret configured', function (): void {
    $this->artisan('hcaptcha:doctor')->assertSuccessful();
});

it('exits non-zero when the secret is unset, naming the problem', function (): void {
    config()->set('hcaptcha.secret', null);

    Artisan::call('hcaptcha:doctor');

    expect(Artisan::output())
        ->toContain('hcaptcha.secret is not set. Verification throws MissingSecretException on the first submission.');

    $this->artisan('hcaptcha:doctor')->assertFailed();
});

it('never prints the secret value', function (): void {
    Artisan::call('hcaptcha:doctor');

    expect(Artisan::output())->not->toContain(TestCase::TEST_SECRET);
});

it('warns when a profile resolves to no usable secret', function (): void {
    config()->set('hcaptcha.secret', null);
    config()->set('hcaptcha.profiles.marketing', ['sitekey' => '20000000-ffff-ffff-ffff-000000000002']);

    Artisan::call('hcaptcha:doctor');

    expect(Artisan::output())
        ->toContain('Profile [marketing] resolves to no usable secret, and the global one is unset too.');
});

it('reports the audit table missing when logging is enabled and the table does not exist', function (): void {
    // Point the check at a table name that was never migrated, rather than
    // dropping the real one: the audit migration reads hcaptcha.logging.table
    // again on rollback between tests, so leaving this override in place
    // would have it drop the wrong table's index at teardown.
    config()->set('hcaptcha.logging.table', 'hcaptcha_verifications_missing');
    config()->set('hcaptcha.logging.enabled', true);

    try {
        Artisan::call('hcaptcha:doctor');

        expect(Artisan::output())
            ->toContain('Audit trail is enabled but its table does not exist.');

        $this->artisan('hcaptcha:doctor')->assertFailed();
    } finally {
        config()->set('hcaptcha.logging.table', 'hcaptcha_verifications');
    }
});
