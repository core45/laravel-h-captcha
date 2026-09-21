<?php

declare(strict_types=1);

use Core45\HCaptcha\Contracts\HostnameProvider;
use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Support\ConfigHostnameProvider;
use Core45\HCaptcha\Support\HttpVerifier;
use Core45\HCaptcha\Support\VerificationResult;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * The allowlist can come from somewhere other than config -- a database of
 * tenant domains, typically, so a site added at runtime is valid without an env
 * edit and a deploy.
 *
 * The rule that matters more than the feature: a provider returning nothing
 * must never widen the allowlist to everything. That would switch off the only
 * check standing between a public sitekey and a token solved on an attacker's
 * own page.
 *
 * Helper names here are deliberately prefixed. Pest puts every test file's
 * functions in one global namespace, so a second `fakeSiteverify()` would turn
 * the whole suite into a fatal error.
 */

/**
 * A provider whose answers the test dictates, and which counts its calls.
 *
 * @param  list<string>  $hostnames
 */
function providerTestDouble(array $hostnames): HostnameProvider
{
    return new class($hostnames) implements HostnameProvider
    {
        public int $calls = 0;

        /** @param list<string> $list */
        public function __construct(private readonly array $list) {}

        /** @return list<string> */
        public function hostnames(): array
        {
            $this->calls++;

            return $this->list;
        }
    };
}

function providerTestSiteverify(string $hostname = 'provided.test'): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response([
            'success' => true,
            'hostname' => $hostname,
            'challenge_ts' => now()->toIso8601String(),
        ]),
    ]);
}

function providerTestVerify(string $scope = 'default'): VerificationResult
{
    return app(Verifier::class)->verify(TestCase::TEST_TOKEN, null, $scope);
}

beforeEach(function (): void {
    HttpVerifier::forgetLoggedWarnings();
});

it('binds the config-backed provider by default', function (): void {
    expect(app(HostnameProvider::class))->toBeInstanceOf(ConfigHostnameProvider::class);
});

it('reads the allowlist from a bound provider instead of config', function (): void {
    // Config names a host the token was NOT solved on. If config still won,
    // this would be a hostname-mismatch.
    config()->set('hcaptcha.hostnames', ['config-only.test']);
    app()->instance(HostnameProvider::class, providerTestDouble(['provided.test']));
    providerTestSiteverify('provided.test');

    expect(providerTestVerify()->passed())->toBeTrue();
});

it('normalizes what the provider returns, so a hand-entered domain row still matches', function (): void {
    app()->instance(HostnameProvider::class, providerTestDouble([' Provided.TEST ']));
    providerTestSiteverify('provided.test');

    expect(providerTestVerify()->passed())->toBeTrue();
});

it('still rejects a hostname the provider did not list', function (): void {
    app()->instance(HostnameProvider::class, providerTestDouble(['provided.test']));
    providerTestSiteverify('somewhere-else.test');

    $result = providerTestVerify();

    expect($result->failed())->toBeTrue()
        ->and($result->rejectedBy)->toBe('hostname-mismatch');
});

it('falls back to the configured hostnames when the provider returns nothing', function (): void {
    // A provider outage, or a tenant table briefly empty mid-migration, must
    // not be the end of the search -- config is the safety net beneath it.
    config()->set('hcaptcha.hostnames', ['fallback.test']);
    app()->instance(HostnameProvider::class, providerTestDouble([]));
    providerTestSiteverify('fallback.test');

    expect(providerTestVerify()->passed())->toBeTrue();
});

it('rejects rather than accepting everything when nothing supplies an allowlist', function (): void {
    // The regression test for the whole change. Before this, an empty list
    // skipped the check and accepted a token solved on any hostname at all.
    config()->set('hcaptcha.hostnames', []);
    app()->instance(HostnameProvider::class, providerTestDouble([]));
    providerTestSiteverify('anywhere-at-all.test');

    Log::shouldReceive('error')->atLeast()->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'resolved to nothing usable'));
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $result = providerTestVerify();

    expect($result->failed())->toBeTrue()
        ->and($result->accepted)->toBeFalse()
        ->and($result->rejectedBy)->toBe('hostname-allowlist-empty');
});

it('logs every empty-allowlist rejection, not once per process', function (): void {
    // The once-per-process latch is right for a deliberate opt-out. It is wrong
    // for a misconfiguration that is rejecting live traffic: that has to stay
    // greppable for as long as it lasts.
    config()->set('hcaptcha.hostnames', []);
    app()->instance(HostnameProvider::class, providerTestDouble([]));
    providerTestSiteverify();

    Log::shouldReceive('error')->twice()
        ->withArgs(fn (string $message): bool => str_contains($message, 'resolved to nothing usable'));
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    providerTestVerify('first');
    providerTestVerify('second');
});

it('skips the check instead of rejecting when the application opts out', function (): void {
    config()->set('hcaptcha.hostnames', []);
    config()->set('hcaptcha.hostnames_required', false);
    app()->instance(HostnameProvider::class, providerTestDouble([]));
    providerTestSiteverify('anywhere-at-all.test');

    Log::shouldReceive('error')->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'hostname check is inactive'));
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    expect(providerTestVerify()->passed())->toBeTrue();
});

it('asks the provider again for each verification', function (): void {
    // A provider backed by a database is the point of the contract. A verifier
    // that resolved the list once and held it would leave a queue worker
    // serving a stale allowlist until it restarted.
    $provider = providerTestDouble(['provided.test']);
    app()->instance(HostnameProvider::class, $provider);
    providerTestSiteverify('provided.test');

    providerTestVerify('first');
    providerTestVerify('second');

    expect($provider->calls)->toBe(2);
});
