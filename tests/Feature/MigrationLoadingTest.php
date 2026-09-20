<?php

declare(strict_types=1);

use Core45\HCaptcha\HCaptchaServiceProvider;

/**
 * The audit table is optional, so the package's migration only registers
 * itself when the audit trail is on -- or when an operator says otherwise.
 *
 * The decision is made in boot(), which has already happened by the time a
 * test body runs, so the four config states are checked against the decision
 * itself. One end-to-end case then proves the decision is what Laravel's
 * migrator actually sees, by booting a whole application in its own process.
 */
final class MigrationLoadingProbe extends HCaptchaServiceProvider
{
    public function decidesToLoadMigrations(): bool
    {
        return $this->shouldLoadMigrations();
    }
}

/**
 * @param  bool|null  $migrations  The `hcaptcha.logging.migrations` value.
 */
function decidesToLoadMigrations(bool $enabled, ?bool $migrations): bool
{
    config()->set('hcaptcha.logging.enabled', $enabled);
    config()->set('hcaptcha.logging.migrations', $migrations);

    return (new MigrationLoadingProbe(app()))->decidesToLoadMigrations();
}

it('does not register the migration when the audit trail is off', function (): void {
    expect(decidesToLoadMigrations(enabled: false, migrations: null))->toBeFalse();
});

it('registers the migration when the audit trail is on', function (): void {
    expect(decidesToLoadMigrations(enabled: true, migrations: null))->toBeTrue();
});

it('lets an explicit false keep the migration out even with the audit trail on', function (): void {
    expect(decidesToLoadMigrations(enabled: true, migrations: false))->toBeFalse();
});

it('lets an explicit true keep the migration registered with the audit trail off', function (): void {
    // The state an installation that already has the table wants: nothing is
    // dropped, and `migrate:status` keeps recognising the migration.
    expect(decidesToLoadMigrations(enabled: false, migrations: true))->toBeTrue();
});

it('keeps the package migration out of a real application that never enabled the audit trail', function (): void {
    // End to end, in its own process: boot() has already run here, and
    // building a second Testbench application in this one leaves static
    // registration state behind that breaks whichever test runs next.
    // The switch travels as an env var, not as a config override: the
    // package's config file reads env() while it is merged, which happens in
    // register() -- before any resolving callback could set a value.
    // Every path the child process needs travels in the environment. Deriving
    // them from the probe's own __DIR__ ties the test to where a vendor
    // directory happens to sit, which is not the same in a local checkout and
    // in a fresh CI install.
    $probe = <<<'PHP'
        <?php

        require getenv('PROBE_AUTOLOAD');

        $_ENV['HCAPTCHA_LOGGING'] = $_SERVER['HCAPTCHA_LOGGING'] = getenv('PROBE_ENABLED');

        $app = Orchestra\Testbench\Foundation\Application::create(
            basePath: getenv('PROBE_BASE_PATH'),
            options: ['extra' => ['providers' => [Core45\HCaptcha\HCaptchaServiceProvider::class]]],
        );

        echo json_encode(array_map('realpath', $app['migrator']->paths()));
        PHP;

    $packageRoot = realpath(__DIR__.'/../..');
    $autoload = realpath($packageRoot.'/vendor/autoload.php');
    $testbenchBase = realpath($packageRoot.'/vendor/orchestra/testbench-core/laravel');

    expect($autoload)->toBeString()
        ->and($testbenchBase)->toBeString();

    $file = $packageRoot.'/hcaptcha-migration-probe.php';
    file_put_contents($file, $probe);

    try {
        $packagePath = realpath($packageRoot.'/database/migrations');

        $run = static function (string $enabled) use ($file, $autoload, $testbenchBase): array|string {
            $output = (string) shell_exec(sprintf(
                'PROBE_ENABLED=%s PROBE_AUTOLOAD=%s PROBE_BASE_PATH=%s php %s 2>&1',
                escapeshellarg($enabled),
                escapeshellarg($autoload),
                escapeshellarg($testbenchBase),
                escapeshellarg($file),
            ));

            $decoded = json_decode($output, true);

            // Handing back the raw output when the child produced no JSON puts
            // the child's own error in the failure message, instead of leaving
            // a bare "null is not of type array" to diagnose.
            return is_array($decoded) ? $decoded : $output;
        };

        $off = $run('false');
        $on = $run('true');

        expect($off)->toBeArray()
            ->and($on)->toBeArray()
            ->and($off)->not->toContain($packagePath)
            ->and($on)->toContain($packagePath);
    } finally {
        @unlink($file);
    }
});
