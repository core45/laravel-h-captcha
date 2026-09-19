<?php

declare(strict_types=1);

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Models\HCaptchaVerification;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('hcaptcha.logging.enabled', true);
});

/**
 * `created_at` is not fillable, so it has to be forced after the insert.
 */
function verificationAgedDays(int $days): HCaptchaVerification
{
    $row = HCaptchaVerification::query()->create(['success' => true]);

    $row->forceFill(['created_at' => Carbon::now()->subDays($days)])->saveQuietly();

    return $row;
}

function fakeAccepted(array $overrides = []): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(array_merge([
            'success' => true,
            'hostname' => 'example.test',
            'challenge_ts' => '2026-09-17T10:00:00Z',
        ], $overrides)),
    ]);
}

it('records a successful verification', function (): void {
    fakeAccepted();

    app(Verifier::class)->verify(TestCase::TEST_TOKEN, '203.0.113.1');

    $row = HCaptchaVerification::query()->sole();

    expect($row->success)->toBeTrue()
        ->and($row->hostname)->toBe('example.test')
        ->and($row->challenge_ts?->toDateString())->toBe('2026-09-17')
        ->and($row->error_codes)->toBeNull();
});

/*
 * The token is a single-use credential. A stored copy would be useless -- it is
 * already spent -- and a liability. Only its hash is kept, which is still
 * enough to spot a replay.
 */
it('stores a hash of the token and never the token itself', function (): void {
    fakeAccepted();

    app(Verifier::class)->verify(TestCase::TEST_TOKEN);

    $row = HCaptchaVerification::query()->sole();

    expect($row->token_hash)->toBe(hash('sha256', TestCase::TEST_TOKEN))
        ->and($row->token_hash)->not->toBe(TestCase::TEST_TOKEN);

    expect(HCaptchaVerification::query()->forToken(hash('sha256', TestCase::TEST_TOKEN))->count())
        ->toBe(1);
});

it('records a rejection with its error codes', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response([
            'success' => false,
            'error-codes' => ['token-already-used'],
        ]),
    ]);

    app(Verifier::class)->verify('spent-token');

    $row = HCaptchaVerification::query()->failed()->sole();

    expect($row->error_codes)->toBe(['token-already-used']);
});

it('records a locally rejected token with the reason', function (): void {
    config()->set('hcaptcha.hostnames', ['allowed.test']);
    fakeAccepted(['hostname' => 'attacker.test']);

    app(Verifier::class)->verify(TestCase::TEST_TOKEN);

    expect(HCaptchaVerification::query()->sole()->rejected_by)->toBe('hostname-mismatch');
});

/*
 * A tokenless submission needs no HTTP call, so recording it would let anyone
 * fill this table with empty POSTs for free. Opt-in only.
 */
it('does not record a submission that carried no token by default', function (): void {
    fakeAccepted();

    app(Verifier::class)->verify(null);

    expect(HCaptchaVerification::query()->count())->toBe(0);
});

it('records a submission that carried no token when asked to', function (): void {
    config()->set('hcaptcha.logging.log_missing_token', true);
    fakeAccepted();

    app(Verifier::class)->verify(null);

    $row = HCaptchaVerification::query()->sole();

    expect($row->success)->toBeFalse()
        ->and($row->token_hash)->toBeNull()
        ->and($row->error_codes)->toBe(['missing-input-response']);
});

it('does not record an oversized token by default', function (): void {
    config()->set('hcaptcha.max_token_length', 8);
    fakeAccepted();

    app(Verifier::class)->verify(str_repeat('x', 9));

    expect(HCaptchaVerification::query()->count())->toBe(0);
});

it('records an oversized token when asked to, without hashing the body', function (): void {
    config()->set('hcaptcha.max_token_length', 8);
    config()->set('hcaptcha.logging.log_oversized_token', true);
    fakeAccepted();

    app(Verifier::class)->verify(str_repeat('x', 9));

    $row = HCaptchaVerification::query()->sole();

    expect($row->success)->toBeFalse()
        ->and($row->token_hash)->toBeNull()
        ->and($row->error_codes)->toBe(['token-too-long']);
});

it('writes one row per verification, not one per memoized read', function (): void {
    fakeAccepted();

    app(Verifier::class)->verify(TestCase::TEST_TOKEN, null, 'h-captcha-response');
    app(Verifier::class)->verify(TestCase::TEST_TOKEN, null, 'h-captcha-response');

    expect(HCaptchaVerification::query()->count())->toBe(1);
});

it('writes nothing when logging is disabled', function (): void {
    config()->set('hcaptcha.logging.enabled', false);
    fakeAccepted();

    app(Verifier::class)->verify(TestCase::TEST_TOKEN);

    expect(HCaptchaVerification::query()->count())->toBe(0);
});

it('omits the ip unless the pii toggle is on', function (): void {
    fakeAccepted();

    app(Verifier::class)->verify(TestCase::TEST_TOKEN, '203.0.113.1');

    expect(HCaptchaVerification::query()->sole()->ip)->toBeNull();
});

it('stores the ip when the pii toggle is on', function (): void {
    config()->set('hcaptcha.logging.store_ip', true);
    fakeAccepted();

    app(Verifier::class)->verify(TestCase::TEST_TOKEN, '203.0.113.1');

    expect(HCaptchaVerification::query()->sole()->ip)->toBe('203.0.113.1');
});

it('prunes rows past the retention window and keeps the rest', function (): void {
    fakeAccepted();

    verificationAgedDays(120);
    verificationAgedDays(10);

    $this->artisan('hcaptcha:prune')->assertSuccessful();

    expect(HCaptchaVerification::query()->count())->toBe(1);
});

it('prunes nothing when retention is disabled', function (): void {
    config()->set('hcaptcha.logging.retention_days', 0);

    verificationAgedDays(500);

    $this->artisan('hcaptcha:prune')->assertSuccessful();

    expect(HCaptchaVerification::query()->count())->toBe(1);
});

it('honours a retention override on the command line', function (): void {
    verificationAgedDays(10);

    $this->artisan('hcaptcha:prune', ['--days' => 5])->assertSuccessful();

    expect(HCaptchaVerification::query()->count())->toBe(0);
});

it('never fails a verification because the audit row could not be written', function (): void {
    config()->set('hcaptcha.logging.table', 'table_that_does_not_exist');
    fakeAccepted();

    expect(app(Verifier::class)->verify(TestCase::TEST_TOKEN)->passed())->toBeTrue();
});
