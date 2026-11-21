<?php

namespace Sevaske\Zatca\Commands;

use Illuminate\Console\Command;
use Sevaske\Zatca\Models\EgsUnit;
use Sevaske\Zatca\Models\Models;

class UnitsCommand extends Command
{
    protected $signature = 'zatca:units {--env= : only units of this environment}';

    protected $description = 'List the EGS units, their onboarding state, chain position and CSID expiry.';

    public function handle(): int
    {
        $units = Models::unit()::query()
            ->when($this->option('env'), fn ($query, $env) => $query->where('environment', $env))
            ->withCount(['documents as pending_count' => fn ($query) => $query->where('status', 'pending')])
            ->orderBy('environment')
            ->orderBy('key')
            ->get();

        if ($units->isEmpty()) {
            $this->components->info('No EGS units yet. Create one with `php artisan zatca:onboard` or `php artisan zatca:import`.');

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Environment', 'Name', 'VAT number', 'Types', 'State', 'Last ICV', 'Pending', 'CSID valid until'],
            $units->map(fn (EgsUnit $unit) => [
                $unit->key,
                $unit->environment,
                $unit->name,
                $unit->vat_number,
                $unit->invoice_types,
                match (true) {
                    $unit->isOnboarded() => 'onboarded',
                    $unit->compliance_csid !== null => 'compliance checks',
                    $unit->csr !== null => 'CSR generated',
                    default => 'new',
                },
                $unit->last_icv,
                $unit->getAttribute('pending_count'),
                $unit->certificateExpiresAt()?->toDateString() ?? '-',
            ]),
        );

        return self::SUCCESS;
    }
}
