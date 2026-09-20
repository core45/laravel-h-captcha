<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Console;

use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Models\HCaptchaVerification;
use Core45\HCaptcha\Support\Credentials;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reports what this installation's hCaptcha configuration will actually do.
 *
 * Most hCaptcha failures are configuration, not code: a missing secret, the
 * public test keys left in production, a hostname policy that matches nothing,
 * an audit table that was never migrated. Each of those is invisible until a
 * visitor cannot submit a form, so this command says it out loud instead.
 *
 * It never prints a secret, and it makes no network call: a diagnostic that
 * spends a token or leaks a credential into CI output would be worse than the
 * problem it reports.
 */
class DoctorCommand extends Command
{
    protected $signature = 'hcaptcha:doctor';

    protected $description = 'Check the hCaptcha configuration and report anything that will fail at runtime.';

    /**
     * hCaptcha's published always-pass pair. Fine locally, a wide-open door in
     * production: the sitekey solves itself and the secret accepts anything.
     *
     * @var list<string>
     */
    private const TEST_CREDENTIALS = [
        '10000000-ffff-ffff-ffff-000000000001',
        '0x0000000000000000000000000000000000000000',
    ];

    public function handle(HCaptchaManager $manager): int
    {
        $problems = 0;

        $problems += $this->checkCredentials($manager);
        $problems += $this->checkProfiles($manager);
        $problems += $this->checkHostnames();
        $problems += $this->checkAudit();

        $this->newLine();

        if ($problems === 0) {
            $this->components->info('hCaptcha configuration looks usable.');

            return self::SUCCESS;
        }

        $this->components->error($problems.' hCaptcha configuration problem(s) found.');

        // A non-zero exit so a deployment pipeline can gate on this.
        return self::FAILURE;
    }

    private function checkCredentials(HCaptchaManager $manager): int
    {
        $problems = 0;

        $sitekey = config('hcaptcha.sitekey');
        $secret = config('hcaptcha.secret');

        if (! HCaptchaManager::isUsableCredential($sitekey)) {
            $this->components->error('hcaptcha.sitekey is not set. Every widget renders nothing and every submission is rejected.');
            $problems++;
        } else {
            // The sitekey is public -- it is already in the page's HTML -- so
            // echoing it is not a disclosure. The secret is never printed.
            $this->components->info('Site key: '.$manager->sitekey());
        }

        if (! HCaptchaManager::isUsableCredential($secret)) {
            $this->components->error('hcaptcha.secret is not set. Verification throws MissingSecretException on the first submission.');
            $problems++;
        } else {
            $this->components->info('Secret: set (not shown).');
        }

        foreach (['sitekey' => $sitekey, 'secret' => $secret] as $name => $value) {
            if (is_string($value) && in_array(trim($value), self::TEST_CREDENTIALS, true) && ! app()->environment('local', 'testing')) {
                $this->components->warn(sprintf(
                    'hcaptcha.%s is hCaptcha\'s public test credential outside a local environment. It accepts every submission.',
                    $name,
                ));
                $problems++;
            }
        }

        return $problems;
    }

    private function checkProfiles(HCaptchaManager $manager): int
    {
        $problems = 0;

        foreach ($manager->profileNames() as $profile) {
            $credentials = $manager->profileCredentials($profile);

            // Counted per profile, not across the loop: a shared counter would
            // silence the confirmation line for every healthy profile after
            // the first broken one.
            $broken = 0;

            foreach (['sitekey', 'secret'] as $half) {
                if (! HCaptchaManager::isUsableCredential($credentials[$half])) {
                    $this->components->error(sprintf(
                        'Profile [%s] resolves to no usable %s, and the global one is unset too.',
                        $profile,
                        $half,
                    ));
                    $broken++;
                }
            }

            if ($broken === 0) {
                $this->components->info('Profile ['.$profile.']: site key and secret both resolve.');
            }

            // Inheriting one half and overriding the other is the quiet way to
            // get `sitekey-secret-mismatch` on every submission: the widget
            // renders the profile's key while the token is checked against a
            // secret from another account.
            $credentialResolver = new Credentials(config());

            foreach (['sitekey', 'secret'] as $half) {
                $other = $half === 'sitekey' ? 'secret' : 'sitekey';

                if (! $credentialResolver->declaresOwn($profile, $half) && $credentialResolver->declaresOwn($profile, $other)) {
                    $this->components->warn(sprintf(
                        'Profile [%s] overrides the %s but inherits the global %s. Both halves must belong to the same hCaptcha account.',
                        $profile,
                        $other,
                        $half,
                    ));
                }
            }

            $problems += $broken;
        }

        return $problems;
    }

    private function checkHostnames(): int
    {
        $configured = config('hcaptcha.hostnames');

        $hostnames = array_values(array_filter(array_map(
            static fn (mixed $hostname): string => mb_strtolower(trim((string) $hostname)),
            is_array($configured) ? $configured : (is_string($configured) ? explode(',', $configured) : []),
        ), static fn (string $hostname): bool => $hostname !== ''));

        if ($hostnames === []) {
            $this->components->warn(
                'Hostname check inactive: hcaptcha.hostnames resolves to nothing. '
                .'Set HCAPTCHA_HOSTNAMES, or an APP_URL that includes a scheme. '
                .'The dashboard domain allowlist is the authoritative control either way.'
            );

            // A warning, not a problem: it is a policy an operator may have
            // switched off deliberately, and the dashboard allowlist is the
            // control that actually binds a sitekey to a domain.
            return 0;
        }

        $this->components->info('Allowed hostnames: '.implode(', ', $hostnames));

        if (config('hcaptcha.hostnames_strict')) {
            $this->components->warn('hostnames_strict is on: hCaptcha responses reporting no hostname are rejected, including during hCaptcha\'s own busy periods.');
        }

        return 0;
    }

    private function checkAudit(): int
    {
        if (! config('hcaptcha.logging.enabled')) {
            $this->components->info('Audit trail: disabled (no table needed).');

            return 0;
        }

        try {
            $table = (new HCaptchaVerification)->getConnection()->getSchemaBuilder();

            if (! $table->hasTable((string) config('hcaptcha.logging.table', 'hcaptcha_verifications'))) {
                $this->components->error(
                    'Audit trail is enabled but its table does not exist. Run `php artisan migrate`, '
                    .'or publish the migration with `php artisan vendor:publish --tag=hcaptcha-migrations`.'
                );

                return 1;
            }
        } catch (Throwable $exception) {
            // A doctor that dies on an unreachable database tells the operator
            // less than one that reports it.
            $this->components->error('Audit trail is enabled but its connection could not be inspected: '.$exception->getMessage());

            return 1;
        }

        $this->components->info('Audit trail: enabled, table present.');

        if (config('hcaptcha.logging.store_ip') || config('hcaptcha.logging.store_user_agent') || config('hcaptcha.logging.store_url')) {
            $this->components->warn('Personal data is being recorded (IP, user agent or URL). Keep a lawful basis and a retention window for it.');
        }

        return 0;
    }
}
