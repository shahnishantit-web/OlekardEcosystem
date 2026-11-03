<?php

declare(strict_types=1);

namespace App\Modules\Events\Console;

use App\Modules\Events\Application\EventAssignmentManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class BootstrapEventAssignmentAuthority extends Command
{
    protected $signature = 'events:bootstrap-assignment-authority
        {organisation_id : Existing organisation UUID}
        {administrator_id : Existing active organisation member UUID}
        {authority_type : organisation or finance}
        {approver_one_id : First independently authorized approver UUID}
        {approver_two_id : Second distinct independently authorized approver UUID}
        {approval_reference : Approved governance evidence reference}
        {operator_id : Active, explicitly authorized bootstrap operator UUID}';

    protected $description = 'Bootstrap one explicitly approved P25 assignment administrator; never seeds a default authority.';

    public function handle(EventAssignmentManagementService $assignments): int
    {
        try {
            $record = $assignments->bootstrapAdministrator(
                (string) $this->argument('organisation_id'),
                (string) $this->argument('administrator_id'),
                (string) $this->argument('authority_type'),
                (string) $this->argument('approver_one_id'),
                (string) $this->argument('approver_two_id'),
                (string) $this->argument('approval_reference'),
                (string) $this->argument('operator_id'),
                (string) Str::uuid(),
            );
        } catch (\Throwable $exception) {
            $this->error('Bootstrap denied: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Assignment authority established for '.$record['organisation_id'].' ('.$record['authority_type'].').');

        return self::SUCCESS;
    }
}
