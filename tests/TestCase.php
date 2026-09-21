<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests;

use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\HCaptchaServiceProvider;
use Core45\HCaptcha\Support\HttpVerifier;
use Core45\HCaptcha\Tests\Fixtures\BladeRenderInsideLivewireComponent;
use Core45\HCaptcha\Tests\Fixtures\BrowserGuardedForm;
use Core45\HCaptcha\Tests\Fixtures\BrowserModalComponent;
use Core45\HCaptcha\Tests\Fixtures\HCaptchaFormComponent;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * hCaptcha's documented test pair. Against the real service this keypair
     * always verifies successfully, which makes it a safe default here.
     */
    public const TEST_SITEKEY = '10000000-ffff-ffff-ffff-000000000001';

    public const TEST_SECRET = '0x0000000000000000000000000000000000000000';

    public const TEST_TOKEN = '10000000-aaaa-bbbb-cccc-000000000001';

    /**
     * Unit and Feature tests boot this case as-is and must prove the package
     * works with no Livewire/Filament provider registered at all. Only
     * IntegrationTestCase (and, through it, BrowserTestCase) flips this on.
     */
    protected bool $withIntegrations = false;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'Core45\\HCaptcha\\Tests\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Both managers latch a "logged this once already" flag in a static
        // property so the once-per-process warning survives across requests
        // in production. Reset it before every test so a test that trips the
        // latch (deliberately or not) never leaks state into a later test
        // that depends on the warning firing again.
        HCaptchaManager::forgetLoggedWarnings();
        HttpVerifier::forgetLoggedWarnings();

        if ($this->withIntegrations) {
            // Fixture views and anonymous components shared by Integration
            // and Browser tests: <x-hcaptcha-tests::layouts.app>, hcaptcha-tests::modal, ...
            View::addNamespace('hcaptcha-tests', __DIR__.'/views');
            Blade::anonymousComponentPath(__DIR__.'/views', 'hcaptcha-tests');

            // Fixture components referenced by tag name inside fixture views.
            if (class_exists(Livewire::class)) {
                Livewire::component('hcaptcha-form-component', HCaptchaFormComponent::class);
                Livewire::component('browser-guarded-form', BrowserGuardedForm::class);
                Livewire::component('browser-modal', BrowserModalComponent::class);
                Livewire::component('blade-render-inside-livewire-component', BladeRenderInsideLivewireComponent::class);
            }
        }
    }

    protected function getPackageProviders($app): array
    {
        $providers = [
            HCaptchaServiceProvider::class,
        ];

        if (! $this->withIntegrations) {
            return $providers;
        }

        // Real-app package discovery boots providers in alphabetical order
        // by package name, which puts every filament/* provider before
        // livewire/livewire. Matching that order here is what lets
        // Filament's SupportServiceProvider::boot() bind DataStore before
        // Livewire's own provider runs -- see IntegrationTestCase for why
        // that ordering matters.
        $integrationProviders = [];

        if (class_exists(SupportServiceProvider::class)) {
            $integrationProviders[] = SupportServiceProvider::class;
        }
        if (class_exists(ActionsServiceProvider::class)) {
            $integrationProviders[] = ActionsServiceProvider::class;
        }
        if (class_exists(SchemasServiceProvider::class)) {
            $integrationProviders[] = SchemasServiceProvider::class;
        }
        if (class_exists(NotificationsServiceProvider::class)) {
            $integrationProviders[] = NotificationsServiceProvider::class;
        }
        if (class_exists(InfolistsServiceProvider::class)) {
            $integrationProviders[] = InfolistsServiceProvider::class;
        }
        if (class_exists(TablesServiceProvider::class)) {
            $integrationProviders[] = TablesServiceProvider::class;
        }
        if (class_exists(WidgetsServiceProvider::class)) {
            $integrationProviders[] = WidgetsServiceProvider::class;
        }
        if (class_exists(FormsServiceProvider::class)) {
            $integrationProviders[] = FormsServiceProvider::class;
        }
        if (class_exists(FilamentServiceProvider::class)) {
            $integrationProviders[] = FilamentServiceProvider::class;
        }
        if (class_exists(LivewireServiceProvider::class)) {
            $integrationProviders[] = LivewireServiceProvider::class;
        }

        return array_merge($integrationProviders, $providers);
    }

    public function defineEnvironment($app): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        config()->set('hcaptcha.sitekey', self::TEST_SITEKEY);
        config()->set('hcaptcha.secret', self::TEST_SECRET);

        // A configured allowlist is the normal posture, and these are the two
        // hostnames the siteverify fakes report: `localhost` from
        // fakeSiteverify(), `example.test` from siteverifyBody(). Without this
        // the suite would run with an empty allowlist, which now rejects every
        // token -- tests about something else entirely would fail for that
        // reason. A test that cares about the allowlist overrides it.
        config()->set('hcaptcha.hostnames', ['localhost', 'example.test']);

        // Off by default, so a test that cares about the audit trail says so.
        config()->set('hcaptcha.logging.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
