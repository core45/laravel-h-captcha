<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Exceptions;

use RuntimeException;

/**
 * Thrown rather than silently failing every verification.
 *
 * The package this one replaces defaulted the secret to the literal string
 * `default_secret`, so a misconfigured install rejected every visitor with no
 * indication why.
 */
class MissingSecretException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'No hCaptcha secret key configured. Set HCAPTCHA_SECRET in your environment, '
            .'or hcaptcha.secret in config/hcaptcha.php. '
            .'Keys are issued at https://dashboard.hcaptcha.com/sites'
        );
    }
}
