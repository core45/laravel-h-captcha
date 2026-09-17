<?php

declare(strict_types=1);

use Core45\HCaptcha\Tests\TestCase;

/*
 * The whole point of the drop-in claim: a project swapping out
 * thinhbuzz/laravel-h-captcha keeps its existing .env untouched.
 *
 * The shipped config file is evaluated directly with the env in place, because
 * TestCase pins hcaptcha.sitekey/secret for every other test and would mask
 * whatever the file itself resolves.
 */
function shippedConfig(): array
{
    return require __DIR__.'/../../config/hcaptcha.php';
}

function withEnv(array $vars, callable $callback): mixed
{
    $original = [];

    foreach ($vars as $key => $value) {
        $original[$key] = $_ENV[$key] ?? null;

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            continue;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("$key=$value");
    }

    try {
        return $callback();
    } finally {
        foreach ($original as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }
}

it('reads the legacy CAPTCHA_SITEKEY and CAPTCHA_SECRET', function (): void {
    $config = withEnv([
        'HCAPTCHA_SITEKEY' => null,
        'HCAPTCHA_SECRET' => null,
        'CAPTCHA_SITEKEY' => 'legacy-sitekey',
        'CAPTCHA_SECRET' => 'legacy-secret',
    ], shippedConfig(...));

    expect($config['sitekey'])->toBe('legacy-sitekey')
        ->and($config['secret'])->toBe('legacy-secret');
});

/*
 * A partly-migrated .env must be predictable: the new key wins, so a stale
 * CAPTCHA_* left behind cannot quietly override the one being used.
 */
it('prefers the HCAPTCHA keys when both are present', function (): void {
    $config = withEnv([
        'HCAPTCHA_SITEKEY' => TestCase::TEST_SITEKEY,
        'HCAPTCHA_SECRET' => TestCase::TEST_SECRET,
        'CAPTCHA_SITEKEY' => 'legacy-sitekey',
        'CAPTCHA_SECRET' => 'legacy-secret',
    ], shippedConfig(...));

    expect($config['sitekey'])->toBe(TestCase::TEST_SITEKEY)
        ->and($config['secret'])->toBe(TestCase::TEST_SECRET);
});

it('leaves the credentials null when neither key is set', function (): void {
    $config = withEnv([
        'HCAPTCHA_SITEKEY' => null,
        'HCAPTCHA_SECRET' => null,
        'CAPTCHA_SITEKEY' => null,
        'CAPTCHA_SECRET' => null,
    ], shippedConfig(...));

    expect($config['sitekey'])->toBeNull()
        ->and($config['secret'])->toBeNull();
});
