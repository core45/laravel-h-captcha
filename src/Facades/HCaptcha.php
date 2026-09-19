<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Facades;

use Core45\HCaptcha\HCaptchaManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Core45\HCaptcha\Support\VerificationResult verify(?string $token, ?string $clientIp = null, string|\Core45\HCaptcha\Support\VerificationContext|null $scope = null)
 * @method static string sitekey(?string $override = null)
 * @method static bool configured(?string $override = null)
 * @method static string fieldName()
 * @method static string|null locale(?string $override = null)
 * @method static bool scriptEnabled()
 * @method static string scriptUrl(?string $locale = null)
 * @method static array<string, string> attributes(array<string, string|int|float|bool|null> $overrides = [], ?string $sitekey = null)
 * @method static \Illuminate\Support\HtmlString attributeString(array<string, string> $attributes)
 * @method static string widgetId(?string $override = null, ?string $key = null)
 * @method static list<string> renderedWidgets()
 * @method static string callbackName()
 * @method static string namespaceName()
 * @method static \Illuminate\Support\HtmlString bootstrapScript()
 * @method static string|null nonce()
 * @method static \Illuminate\Support\HtmlString nonceAttribute()
 * @method static array<string, list<string>> cspDirectives()
 *
 * @see HCaptchaManager
 */
class HCaptcha extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HCaptchaManager::class;
    }
}
