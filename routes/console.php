<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Messenger;
use App\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Billing jobs. Run the scheduler with `php artisan schedule:work` locally,
| or a cron entry calling `php artisan schedule:run` every minute in production.
|
| Each job runs once per active company, as that company: its own settings, facility users and messages.
| Pass --company=<id or slug> to run for one company only.
*/

/**
 * Run $callback for each active company (or the one named by --company), as that company.
 */
$forEachCompany = function ($command, callable $callback): void {
    $only = $command->option('company');

    Company::active()
        ->when($only, fn ($q) => ctype_digit((string) $only) ? $q->whereKey((int) $only) : $q->where('slug', $only))
        ->orderBy('id')
        ->each(fn (Company $company) => CurrentCompany::run($company, fn () => $callback($company)));
};

Artisan::command('billing:generate-invoices {--force : Run even when automatic billing is off} {--company= : Company id or slug}', function (BillingService $billing, Messenger $messenger) use ($forEachCompany) {
    $forEachCompany($this, function (Company $company) use ($billing, $messenger) {
        if (! Setting::get('auto_invoicing') && ! $this->option('force')) {
            $this->warn("{$company->name}: automatic monthly billing is off in Settings.");

            return;
        }

        $issued = $billing->generateMonthly();
        $issued->each(fn (Invoice $invoice) => $messenger->invoiceIssued($invoice));

        $this->info("{$company->name}: {$issued->count()} invoices issued.");
    });
})->purpose("Issue this month's dues for every active facility user once the billing day arrives, and send the pay link");

Artisan::command('billing:send-reminders {--company= : Company id or slug}', function (Messenger $messenger) use ($forEachCompany) {
    $forEachCompany($this, function (Company $company) use ($messenger) {
        $days = Setting::reminderDays();
        $sent = 0;

        Invoice::open()
            ->whereDate('due_date', '<=', today())
            ->whereHas('occupancy', fn ($q) => $q->active())
            ->with('occupancy')
            ->each(function (Invoice $invoice) use ($days, $messenger, &$sent) {
                $alreadyToday = MessageLog::where('invoice_id', $invoice->id)->where('type', 'reminder')->whereDate('created_at', today())->exists();

                if (in_array($invoice->daysOverdue(), $days, true) && ! $alreadyToday) {
                    $messenger->reminder($invoice);
                    $sent++;
                }
            });

        $this->info("{$company->name}: {$sent} reminders sent.");
    });
})->purpose('Remind unpaid facility users on the days after the due date set in Settings');

Artisan::command('messages:retry-failed {--company= : Company id or slug}', function (Messenger $messenger) use ($forEachCompany) {
    $forEachCompany($this, function (Company $company) use ($messenger) {
        $messages = MessageLog::whereIn('status', ['queued', 'failed'])->where('type', '!=', 'welcome')->where('attempts', '<', 5)->get();
        $messages->each(fn (MessageLog $m) => $messenger->deliver($m));

        $this->info("{$company->name}: {$messages->count()} messages retried.");
    });
})->purpose('Retry emails and texts that have not gone out yet');

Artisan::command('platform:admin {email} {--name=PayXbills Platform Admin}', function (string $email) {
    $user = User::acrossCompanies()->firstOrNew(['email' => $email]);

    if ($user->exists && $user->company_id) {
        $this->error("{$email} belongs to a company. Platform admins must use an email of their own.");

        return 1;
    }

    $password = $this->secret('Password (at least 8 characters)');

    if (strlen((string) $password) < 8) {
        $this->error('The password must be at least 8 characters.');

        return 1;
    }

    $user->forceFill([
        'name' => $user->name ?? $this->option('name'),
        'role' => Role::Admin,
        'company_id' => null,
        'is_platform_admin' => true,
        'is_active' => true,
        'password' => $password,
        'email_verified_at' => now(),
    ])->save();

    $this->info("{$email} can now log in to the PayXbills platform area.");
})->purpose('Create or update a Fivestar Technologies login for the PayXbills platform area');

Schedule::command('billing:generate-invoices')->dailyAt('07:00');
Schedule::command('billing:send-reminders')->dailyAt('08:00');
Schedule::command('messages:retry-failed')->hourly();
