<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Console;

use Core45\HCaptcha\Contracts\HostnameProvider;
use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Models\HCaptchaVerification;
use Core45\HCaptcha\Support\ConfigHostnameProvider;
use Core45\HCaptcha\Support\Credentials;
use Core45\HCaptcha\Support\HostnameNormalizer;
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
        $provider = app(HostnameProvider::class);

        // Naming the provider matters more than it looks: an application that
        // feeds the allowlist from a database gets a list this command cannot
        // predict from config alone, and an operator reading this output needs
        // to know which source produced the hostnames below.
        $this->components->info('Hostname source: '.$provider::class);

        $hostnames = HostnameNormalizer::normalize($provider->hostnames());
        $fromProvider = $hostnames !== [];

        if (! $fromProvider) {
            $hostnames = HostnameNormalizer::normalize(config('hcaptcha.hostnames'));

            if ($provider::class !== ConfigHostnameProvider::class && $hostnames !== []) {
                $this->components->warn(
                    'The bound provider returned nothing, so hcaptcha.hostnames is being used as the fallback.'
                );
            }
        }

        $required = (bool) config('hcaptcha.hostnames_required', true);

        if ($hostnames === []) {
            if ($required) {
                $this->components->error(
                    'Hostname allowlist resolves to nothing and hcaptcha.hostnames_required is on, '
                    .'so every token is being rejected. Bind a HostnameProvider, set HCAPTCHA_HOSTNAMES, '
                    .'or set an APP_URL that includes a scheme.'
                );

                // Unlike the opt-out below, this is not a policy choice: the
                // site is rejecting its own visitors right now.
                return 1;
            }

            $this->components->warn(
                'Hostname check inactive: the allowlist resolves to nothing and '
                .'hcaptcha.hostnames_required is off, so a token solved on any hostname is accepted. '
                .'The dashboard domain allowlist is the authoritative control either way.'
            );

            // A warning, not a problem: switching the check off is a policy an
            // operator can hold deliberately, and the dashboard allowlist is
            // the control that actually binds a sitekey to a domain.
            return 0;
        }

        // The count, not the list: a multi-tenant install can have hundreds,
        // and a wall of domains buries the rest of this report.
        $this->components->info(count($hostnames) > 10
            ? sprintf('Allowed hostnames: %d, including %s', count($hostnames), implode(', ', array_slice($hostnames, 0, 5)))
            : 'Allowed hostnames: '.implode(', ', $hostnames));

        // Said plainly because it has been misread as a coverage check: this
        // command cannot know which domains the application actually serves.
        $this->components->info('This lists what the allowlist resolves to now; it cannot tell whether every domain you serve is in it.');

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
