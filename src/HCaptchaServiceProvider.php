<?php

declare(strict_types=1);

namespace Core45\HCaptcha;

use Core45\HCaptcha\Compat\CaptchaCompat;
use Core45\HCaptcha\Console\DoctorCommand;
use Core45\HCaptcha\Console\PruneVerificationsCommand;
use Core45\HCaptcha\Contracts\HostnameProvider;
use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Http\Middleware\VerifyHCaptcha;
use Core45\HCaptcha\Rules\HCaptcha as HCaptchaRule;
use Core45\HCaptcha\Support\ConfigHostnameProvider;
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

        // Before anything reads hcaptcha.*, let a published config/captcha.php
        // from thinhbuzz/laravel-h-captcha fill in what it does not set.
        $this->adoptLegacyConfig();

        // scoped(), not singleton(): under Octane the memoized verdicts and the
        // captured Request must not survive into the next request.
        $this->app->scoped(VerificationLogger::class);

        // The default allowlist source. An application that keeps its domains
        // in a database rebinds this, so a domain added at runtime is valid
        // immediately instead of waiting on an env edit and a deploy.
        $this->app->scoped(HostnameProvider::class, ConfigHostnameProvider::class);

        $this->app->scoped(Verifier::class, HttpVerifier::class);
        $this->app->scoped(HttpVerifier::class);

        $this->app->scoped(HCaptchaManager::class);

        // thinhbuzz/laravel-h-captcha compatibility: the same binding name and
        // facade accessor, so an existing `Captcha::display()` keeps resolving.
        $this->app->scoped(CaptchaCompat::class);
        $this->app->scoped('captcha', static fn ($app): CaptchaCompat => $app->make(CaptchaCompat::class));
    }

    public function boot(): void
    {
        $this->bootPublishing();
        $this->bootTranslations();
        $this->bootViews();
        $this->bootValidator();
        $this->bootMiddleware();
        $this->bootCommands();
        $this->bootFormMacro();
    }

    /**
     * Adopt a published `config/captcha.php` where `hcaptcha.*` is unset.
     *
     * `hcaptcha.*` always wins, so a project that has migrated its config is
     * never overridden by a file it forgot to delete.
     */
    protected function adoptLegacyConfig(): void
    {
        $legacy = $this->app['config']->get('captcha');

        if (! is_array($legacy) || $legacy === []) {
            return;
        }

        $map = [
            'secret' => 'hcaptcha.secret',
            'sitekey' => 'hcaptcha.sitekey',
            'options.lang' => 'hcaptcha.locale',
            'attributes' => 'hcaptcha.attributes',
        ];

        foreach ($map as $from => $to) {
            $value = data_get($legacy, $from);

            if (in_array($value, [null, '', []], true)) {
                continue;
            }

            if ($this->app['config']->get($to) === null) {
                $this->app['config']->set($to, $value);
            }
        }

        if (data_get($legacy, 'http_client') !== null) {
            // Not honoured, and cannot be: it named a Guzzle-based client, and
            // using Laravel's Http client instead is why this package exists.
            $this->app['log']->warning(
                'captcha.http_client is set but ignored: core45/laravel-h-captcha uses Laravel\'s HTTP client. '
                .'Remove the key, or config/captcha.php entirely, once the migration is complete.'
            );
        }
    }

    /**
     * `Form::captcha()`, for applications using an HTML builder that registers
     * a `form` binding. Skipped entirely when none is installed.
     */
    protected function bootFormMacro(): void
    {
        if (! $this->app->bound('form')) {
            return;
        }

        $form = $this->app->make('form');

        if (! method_exists($form, 'macro')) {
            return;
        }

        $form::macro('captcha', fn (array $attributes = [], array $options = []) => app(CaptchaCompat::class)->display($attributes, $options));
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
                // Same context the rule object builds, so stacking any two of
                // them on one field still costs one HTTP call instead of
                // double-spending the token. The rule object derives the
                // Livewire action too; the string rule delegates to it.
                $result = (new HCaptchaRule)->resultFor($attribute, $value);

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
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            PruneVerificationsCommand::class,
            DoctorCommand::class,
        ]);

        if ($this->shouldLoadMigrations()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * Whether to run the audit migration from inside the package.
     *
     * The audit trail is optional, so installing the package should not add a
     * table to an application that never asked for one. `logging.migrations`
     * decides, and unset follows `logging.enabled`: switch the audit trail on
     * and the table appears on the next `migrate`; leave it off and nothing is
     * created. An installation that already has the table keeps it either way
     * -- nothing here drops anything -- and can set `logging.migrations` to
     * true so `migrate:status` still recognises the migration, or publish it
     * with `--tag=hcaptcha-migrations` and own it outright.
     */
    protected function shouldLoadMigrations(): bool
    {
        $configured = $this->app['config']->get('hcaptcha.logging.migrations');

        if ($configured === null) {
            return (bool) $this->app['config']->get('hcaptcha.logging.enabled', false);
        }

        return (bool) $configured;
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
            HostnameProvider::class,
        ];
    }
}
