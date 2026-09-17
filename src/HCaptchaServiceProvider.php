<?php

declare(strict_types=1);

namespace Core45\HCaptcha;

use Core45\HCaptcha\Console\PruneVerificationsCommand;
use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Http\Middleware\VerifyHCaptcha;
use Core45\HCaptcha\Support\HttpVerifier;
use Core45\HCaptcha\Support\VerificationLogger;
use Core45\HCaptcha\View\Components\HCaptcha as HCaptchaComponent;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Validator as ValidatorInstance;

class HCaptchaServiceProvider extends ServiceProvider
{
    /**
     * Message key chosen for the most recent string-rule failure, per attribute.
     *
     * The `hcaptcha` string rule has no way to hand a specific message to its
     * replacer, so the verdict is stashed here between the two callbacks. The
     * `HCaptcha` rule object does not need this.
     *
     * @var array<string, string>
     */
    protected array $stringRuleMessages = [];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hcaptcha.php', 'hcaptcha');

        // scoped(), not singleton(): under Octane the memoized verdicts and the
        // captured Request must not survive into the next request.
        $this->app->scoped(VerificationLogger::class);

        $this->app->scoped(Verifier::class, HttpVerifier::class);
        $this->app->scoped(HttpVerifier::class);

        $this->app->scoped(HCaptchaManager::class);
    }

    public function boot(): void
    {
        $this->bootPublishing();
        $this->bootTranslations();
        $this->bootViews();
        $this->bootValidator();
        $this->bootMiddleware();
        $this->bootCommands();
    }

    protected function bootPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/hcaptcha.php' => $this->app->configPath('hcaptcha.php'),
        ], 'hcaptcha-config');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/hcaptcha'),
        ], 'hcaptcha-lang');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/hcaptcha'),
        ], 'hcaptcha-views');

        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'hcaptcha-migrations');
    }

    protected function bootTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'hcaptcha');
    }

    protected function bootViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'hcaptcha');

        Blade::component('hcaptcha', HCaptchaComponent::class);
    }

    /**
     * Register the `hcaptcha` string rule, plus a `captcha` alias for anyone
     * migrating from buzz/laravel-h-captcha.
     *
     * The rule object `Core45\HCaptcha\Rules\HCaptcha` is the better entry
     * point -- it reports whether the token was missing, spent, or rejected.
     * The string rule can only report one message, so it reports that one.
     */
    protected function bootValidator(): void
    {
        foreach (['hcaptcha', 'captcha'] as $rule) {
            // extendImplicit, not extend: the rule must fire on an absent or
            // empty token so a form submitted with no captcha reports "please
            // complete the captcha" rather than passing silently. This keeps
            // the string rule consistent with Rules\HCaptcha, which sets
            // $implicit = true, so neither form needs pairing with `required`.
            Validator::extendImplicit($rule, function (string $attribute, mixed $value): bool {
                $result = $this->app->make(Verifier::class)->verify(
                    is_string($value) ? $value : null,
                    $this->app->bound('request') ? $this->app->make('request')->ip() : null,
                    // Same scope the rule object and the middleware use, so
                    // stacking any two of them on one field still costs one
                    // HTTP call instead of double-spending the token.
                    $attribute,
                );

                $this->stringRuleMessages[$attribute] = $result->messageKey();

                return $result->passed();
            });

            Validator::replacer($rule, fn (string $message, string $attribute, string $rule, array $parameters, ?ValidatorInstance $validator = null): string => __($this->stringRuleMessages[$attribute] ?? 'hcaptcha::hcaptcha.failed'));
        }
    }

    protected function bootMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('hcaptcha', VerifyHCaptcha::class);
    }

    protected function bootCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneVerificationsCommand::class,
            ]);

            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            Verifier::class,
            HttpVerifier::class,
            HCaptchaManager::class,
            VerificationLogger::class,
        ];
    }
}
