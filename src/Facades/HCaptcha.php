<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Facades;

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Testing\FakeVerifier;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Core45\HCaptcha\Support\VerificationResult verify(?string $token, ?string $clientIp = null, string|\Core45\HCaptcha\Support\VerificationContext|null $scope = null)
 * @method static string sitekey(?string $override = null)
 * @method static bool configured(?string $override = null)
 * @method static bool faking()
 * @method static array{sitekey: mixed, secret: mixed} profileCredentials(?string $profile)
 * @method static string|null profileSitekey(?string $profile)
 * @method static list<string> profileNames()
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

    /**
     * Replace the verifier with one that answers without a network call, and
     * make the widget render a fake a browser test can solve.
     *
     *     $hcaptcha = HCaptcha::fake();
     *     $this->post('/contact', [...])->assertRedirect();
     *     $hcaptcha->assertVerifiedFor('h-captcha-response');
     *
     * `HCaptcha::fake(false)` rejects instead, for testing the failure path.
     *
     * Binds as an instance rather than through scoped(), so the fake survives
     * whatever the application does to its container during the test, and
     * forgets the manager so it picks the fake up rather than keeping the
     * verifier it was constructed with.
     */
    public static function fake(bool $passes = true): FakeVerifier
    {
        $fake = new FakeVerifier($passes);

        $app = static::getFacadeApplication();

        $app->instance(Verifier::class, $fake);
        $app->forgetInstance(HCaptchaManager::class);

        static::clearResolvedInstance(HCaptchaManager::class);

        return $fake;
    }
}
