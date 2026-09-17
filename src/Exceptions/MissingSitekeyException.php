<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Exceptions;

use RuntimeException;

/**
 * Thrown when a widget is asked to render with no site key.
 *
 * `HCaptchaManager::configured()` exists so a view can check first and degrade
 * instead of taking the page down.
 */
class MissingSitekeyException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'No hCaptcha site key configured. Set HCAPTCHA_SITEKEY in your environment, '
            .'or hcaptcha.sitekey in config/hcaptcha.php. '
            .'Keys are issued at https://dashboard.hcaptcha.com/sites'
        );
    }
}
