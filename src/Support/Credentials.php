<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Support;

use Core45\HCaptcha\HCaptchaManager;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Resolves the sitekey/secret pair a verification runs against.
 *
 * One application can serve several sites, each with its own hCaptcha
 * credentials. A named profile under `hcaptcha.profiles` holds such a pair, so
 * the widget and the verification agree on which key is in play without any
 * request mutating global configuration between render and verify.
 *
 * Kept out of both HCaptchaManager and HttpVerifier because each needs it and
 * the manager already depends on the verifier: resolving the manager from
 * inside the verifier would close that loop.
 */
final readonly class Credentials
{
    public function __construct(private Repository $config) {}

    /**
     * The credentials a profile resolves to, falling back to the global pair
     * for whichever half the profile leaves unset.
     *
     * An unknown profile name throws: the name comes from server code, so a
     * typo is a bug to surface, not a visitor's input to tolerate. Falling
     * back to the global credentials instead would check the token against a
     * key it was never minted for and reject every visitor with no clue why.
     *
     * @return array{sitekey: mixed, secret: mixed}
     *
     * @throws InvalidArgumentException
     */
    public function for(?string $profile): array
    {
        if ($profile === null || $profile === '') {
            return [
                'sitekey' => $this->config->get('hcaptcha.sitekey'),
                'secret' => $this->config->get('hcaptcha.secret'),
            ];
        }

        $configured = $this->config->get('hcaptcha.profiles.'.$profile);

        if (! is_array($configured)) {
            throw new InvalidArgumentException(sprintf(
                'hCaptcha credential profile [%s] is not configured. Add it under hcaptcha.profiles, or drop the profile argument.',
                $profile,
            ));
        }

        return [
            'sitekey' => $this->half($configured, 'sitekey'),
            'secret' => $this->half($configured, 'secret'),
        ];
    }

    /**
     * One half of a profile's pair, falling back to the global value.
     *
     * The fallback is on usability, not on null: `HCAPTCHA_X_SITEKEY=` in a
     * `.env` resolves to an empty string, which is not null but is not a
     * credential either. A `??` here would keep that empty string, and the
     * widget -- which asks `sitekeyFor()`, and that method judges usability --
     * would render the global key while verification sent none, so hCaptcha
     * answered `sitekey-secret-mismatch` on every submission.
     *
     * @param  array<mixed>  $configured
     */
    private function half(array $configured, string $key): mixed
    {
        $value = $configured[$key] ?? null;

        return HCaptchaManager::isUsableCredential($value)
            ? $value
            : $this->config->get('hcaptcha.'.$key);
    }

    /**
     * Whether a profile supplies this half itself, rather than inheriting the
     * global one. `hcaptcha:doctor` reports the difference: a profile that
     * silently inherits the global secret while overriding the sitekey gets
     * `sitekey-secret-mismatch` from hCaptcha on every submission.
     */
    public function declaresOwn(?string $profile, string $key): bool
    {
        if ($profile === null || $profile === '') {
            return true;
        }

        $configured = $this->config->get('hcaptcha.profiles.'.$profile);

        return is_array($configured) && HCaptchaManager::isUsableCredential($configured[$key] ?? null);
    }

    /**
     * The sitekey a profile renders with, or null when it resolves to nothing
     * usable -- so a view can degrade rather than take the page down.
     *
     * @throws InvalidArgumentException when the profile itself is unknown.
     */
    public function sitekeyFor(?string $profile): ?string
    {
        $sitekey = $this->for($profile)['sitekey'];

        return HCaptchaManager::isUsableCredential($sitekey) ? trim((string) $sitekey) : null;
    }

    /**
     * Names of the configured profiles.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $profiles = $this->config->get('hcaptcha.profiles', []);

        if (! is_array($profiles)) {
            return [];
        }

        return array_values(array_filter(array_keys($profiles), is_string(...)));
    }
}
