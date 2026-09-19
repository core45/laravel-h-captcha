<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests;

use Core45\HCaptcha\HCaptchaServiceProvider;
use Core45\HCaptcha\Tests\Fixtures\HCaptchaFormComponent;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\Livewire\Partials\DataStoreOverride;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\DataStore;
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

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'Core45\\HCaptcha\\Tests\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Workaround for a filament/support defect (v5.8.2): its boot() calls
        // $this->app->bind(DataStore::class, DataStoreOverride::class) instead
        // of singleton(). Container::bind() drops any previously registered
        // instance, so every subsequent app(DataStore::class) call builds a
        // fresh DataStoreOverride with an empty WeakMap -- Livewire's error
        // bag, form state, and any other per-component data recorded through
        // DataStore is silently lost between calls in the same request. This
        // re-registers it as the singleton Livewire itself expects. Safe to
        // remove once upstream fixes filament/support's binding.
        $this->app->singleton(DataStore::class, DataStoreOverride::class);

        // Fixture views and anonymous components shared by Feature and
        // Browser tests: <x-hcaptcha-tests::layouts.app>, hcaptcha-tests::modal, ...
        View::addNamespace('hcaptcha-tests', __DIR__.'/views');
        Blade::anonymousComponentPath(__DIR__.'/views', 'hcaptcha-tests');

        // Fixture components referenced by tag name inside fixture views.
        Livewire::component('hcaptcha-form-component', HCaptchaFormComponent::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            NotificationsServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FormsServiceProvider::class,
            FilamentServiceProvider::class,
            HCaptchaServiceProvider::class,
        ];
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

        // Off by default, so a test that cares about the audit trail says so.
        config()->set('hcaptcha.logging.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
