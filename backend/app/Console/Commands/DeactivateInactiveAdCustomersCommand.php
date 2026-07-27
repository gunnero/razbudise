<?php

namespace App\Console\Commands;

use App\Services\Ads\AdCustomerLifecycleService;
use Illuminate\Console\Command;

class DeactivateInactiveAdCustomersCommand extends Command
{
    protected $signature = 'ads:deactivate-inactive-customers {--dry-run}';

    protected $description = 'Deactivate managed-ad customer dashboards that have been inactive for three months.';

    public function handle(AdCustomerLifecycleService $lifecycle): int
    {
        $cutoff = now()->subMonths(3);

        if ((bool) $this->option('dry-run')) {
            $eligible = $lifecycle->countInactiveCustomers($cutoff);

            $this->info('Dry run: no ad customers were changed.');
            $this->line("Cutoff: {$cutoff->toDateTimeString()}");
            $this->line("Eligible inactive customers: {$eligible}");

            return self::SUCCESS;
        }

        $deactivated = $lifecycle->deactivateInactiveCustomers($cutoff);

        $this->info("Deactivated inactive ad customers: {$deactivated}");
        $this->line("Cutoff: {$cutoff->toDateTimeString()}");

        return self::SUCCESS;
    }
}
