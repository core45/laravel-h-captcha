<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Console;

use Core45\HCaptcha\Models\HCaptchaVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Trims the verification audit trail.
 *
 * Schedule it, because the table grows once per form submission:
 *
 *     Schedule::command('hcaptcha:prune')->daily();
 */
class PruneVerificationsCommand extends Command
{
    protected $signature = 'hcaptcha:prune
                            {--days= : Override the configured retention window}
                            {--chunk=1000 : Rows deleted per query}';

    protected $description = 'Delete hCaptcha verification audit rows past their retention window';

    public function handle(): int
    {
        $days = $this->retentionDays();

        if ($days <= 0) {
            $this->components->warn('Retention is disabled (hcaptcha.logging.retention_days is 0). Nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days);
        $chunk = max(1, (int) $this->option('chunk'));
        $deleted = 0;

        do {
            $justDeleted = HCaptchaVerification::query()
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $deleted += $justDeleted;
        } while ($justDeleted > 0);

        $this->components->info(sprintf(
            'Pruned %d hCaptcha verification %s older than %s.',
            $deleted,
            $deleted === 1 ? 'row' : 'rows',
            $cutoff->toDateTimeString(),
        ));

        return self::SUCCESS;
    }

    private function retentionDays(): int
    {
        $override = $this->option('days');

        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        return (int) config('hcaptcha.logging.retention_days', 90);
    }
}
