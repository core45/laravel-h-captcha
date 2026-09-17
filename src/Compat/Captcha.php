<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Compat;

use Illuminate\Support\Facades\Facade;

/**
 * The `Captcha` facade from `thinhbuzz/laravel-h-captcha`.
 *
 * Registered under the same alias and resolving the same `captcha` binding, so
 * `Captcha::display()` and `Captcha::verify()` in an existing application keep
 * working after the swap. New code should use the `HCaptcha` facade.
 *
 * @method static \Illuminate\Support\HtmlString display(array<string, string|int|float|bool|null> $attributes = [], array<string, mixed> $options = [])
 * @method static \Illuminate\Support\HtmlString displayMultiple(array<string, mixed> $globalOptions = [])
 * @method static \Illuminate\Support\HtmlString displayJs(array<string, mixed> $options = [], list<string> $attributes = ['async', 'defer'])
 * @method static void multiple(bool $multiple = true)
 * @method static void setOptions(array<string, mixed> $options = [])
 * @method static bool verify(?string $response, ?string $clientIp = null, array<string, mixed> $options = [])
 * @method static string getWidgetIdName()
 * @method static string getJsVariableName()
 *
 * @see CaptchaCompat
 */
class Captcha extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'captcha';
    }
}
