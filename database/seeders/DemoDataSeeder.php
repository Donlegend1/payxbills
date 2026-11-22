<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\PaymentAttachment;
use App\Models\RemovalRequest;
use App\Models\Space;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Facilities, rooms, categories and eight months of facility users paying (or not), created through
 * BillingService so invoices, payments and allocations follow the same rules as the live system.
 * Emails use example.com, so nothing can reach a real inbox. No messages are sent while seeding.
 *
 * Demo facility user login: tenant@cosmocleaners.ng / password
 */
class DemoDataSeeder extends Seeder
{
    protected const MONTHS = 8;

    public function run(BillingService $billing): void
    {
        mt_srand(2026);

        $supervisor = User::where('role', Role::Supervisor)->firstOrFail();
        $accountant = User::where('role', Role::Accountant)->firstOrFail();
        $management = User::where('role', Role::Management)->firstOrFail();
        $admin = User::where('role', Role::Admin)->firstOrFail();
        $supervisor2 = User::updateOrCreate(['email' => 'supervisor2@cosmocleaners.ng'], [
            'name' => 'Grace Eze', 'role' => Role::Supervisor, 'password' => 'password', 'email_verified_at' => now(),
        ]);

        $categories = collect([
            'room' => ['Standard room', 15000, 'Single room, shared facilities'],
            'exec' => ['Executive room', 25000, 'Self-contained room'],
            'shop' => ['Shop', 40000, 'Lock-up shop'],
            'stall' => ['Market stall', 8000, 'Open stall'],
        ])->map(fn ($c) => Category::create(['name' => $c[0], 'monthly_amount' => $c[1], 'description' => $c[2]]));

        // [name, address, supervisor, space type label, prefix, from, to, categories used]
        $layout = [
            ['Green Valley Hostel', 'Plot 12, Lekki Phase 1, Lagos', $supervisor, 'Room', '', 101, 116, ['room', 'exec']],
            ['Sunrise Court', '4 Allen Avenue, Ikeja, Lagos', $supervisor, 'Flat', 'A', 1, 10, ['exec']],
            ['City Market Plaza', 'Garki Area 11, Abuja', $supervisor2, 'Shop', 'S', 1, 14, ['shop', 'stall']],
            ['Lakeview Lodge', '21 Lake Road, Gwarinpa, Abuja', $supervisor2, 'Room', '', 1, 12, ['room']],
        ];

        $facilities = collect($layout)->map(function ($f) {
            $facility = Facility::create([
                'name' => $f[0], 'address' => $f[1], 'supervisor_id' => $f[2]->id,
                'phone' => '+23480'.mt_rand(10000000, 99999999), 'email' => 'office@'.str($f[0])->slug().'.example.com',
            ]);
            foreach (range($f[5], $f[6]) as $n) {
                $facility->spaces()->create(['number' => $f[4].$n, 'label' => $f[3]]);
            }

            return [$facility, $f[7], $f[2]];
        });

        $first = ['Adaeze', 'Tunde', 'Ngozi', 'Ibrahim', 'Funmi', 'Emeka', 'Aisha', 'Segun', 'Chinedu', 'Halima', 'Bayo', 'Kemi'];
        $last = ['Adeyemi', 'Okafor', 'Bello', 'Eze', 'Musa', 'Ibrahim', 'Nwosu', 'Danjuma', 'Ojo', 'Abubakar', 'Chukwu', 'Lawal'];
        $start = today()->startOfMonth()->subMonthsNoOverflow(self::MONTHS);
        $profiles = [];
        $i = 0;

        // Fill most spaces, leaving a few vacant in each facility
        foreach ($facilities as [$facility, $types, $by]) {
            foreach ($facility->spaces()->get() as $space) {
                if (mt_rand(1, 100) <= 15) {
                    continue;
                }

                $email = $i === 0 ? 'tenant@cosmocleaners.ng' : 'user'.($i + 1).'@example.com';
                $user = User::create([
                    'name' => $first[$i % 12].' '.$last[intdiv($i, 12) % 12 === 0 ? ($i * 7) % 12 : ($i + intdiv($i, 12)) % 12],
                    'email' => $email,
                    'phone' => '+23481'.str_pad((string) (42000000 + $i), 8, '0', STR_PAD_LEFT),
                    'role' => Role::FacilityUser,
                    'password' => 'password',
                    'email_verified_at' => now(),
                ]);

                $occupancy = Occupancy::create([
                    'user_id' => $user->id,
                    'facility_id' => $facility->id,
                    'space_id' => $space->id,
                    'category_id' => $categories[$types[$i % count($types)]]->id,
                    'start_date' => $start->copy()->addMonthsNoOverflow(mt_rand(0, 4))->addDays(mt_rand(0, 20)),
                    'registered_by' => $by->id,
                ]);

                $profiles[$occupancy->id] = $i === 0 ? 'good' : $this->pick(['good' => 50, 'ahead' => 12, 'late' => 18, 'partial' => 8, 'stopped' => 12]);
                $i++;
            }
        }

        // Issue each month's dues, as the scheduler would have done
        for ($m = 0; $m <= self::MONTHS; $m++) {
            $month = $start->copy()->addMonthsNoOverflow($m);
            $day = $m === self::MONTHS ? today() : $month->copy()->endOfMonth();
            $billing->generateMonthly($day)->each(fn (Invoice $inv) => $inv->update(['sent_at' => $inv->issue_date->copy()->setTime(8, 0)]));
        }

        // Plan payments from each profile, then record them in date order
        $planned = [];
        $aheadDone = [];
        foreach (Invoice::with('occupancy')->orderBy('period')->get() as $invoice) {
            $profile = $profiles[$invoice->occupancy_id];
            $issued = $invoice->issue_date;

            if ($profile === 'ahead' && ! isset($aheadDone[$invoice->occupancy_id])) {
                // Pays six months in one go, early on
                $aheadDone[$invoice->occupancy_id] = true;
                $planned[] = [$issued->copy()->addDays(mt_rand(1, 6)), $invoice, null, 6];

                continue;
            }

            [$amount, $date] = match ($profile) {
                'good', 'ahead' => [(float) $invoice->amount, $issued->copy()->addDays(mt_rand(1, 12))],
                'late' => [(float) $invoice->amount, $issued->copy()->addDays(mt_rand(35, 50))],
                'partial' => [round((float) $invoice->amount / 2, -2), $issued->copy()->addDays(mt_rand(5, 20))],
                'stopped' => $issued->lt(today()->subMonthsNoOverflow(3)) ? [(float) $invoice->amount, $issued->copy()->addDays(mt_rand(3, 15))] : [0, null],
            };

            if ($amount > 0 && $date->lte(today()->subDay())) {
                $planned[] = [$date, $invoice, $amount, null];
            }
        }

        usort($planned, fn ($a, $b) => $a[0] <=> $b[0]);

        foreach ($planned as [$date, $invoice, $amount, $months]) {
            if ($invoice->refresh()->status === 'paid') {
                continue; // already covered by a months-ahead payment
            }
            $this->pay($billing, $invoice->occupancy, $amount, $months, $date, $accountant);
        }

        $this->pendingManualPayments($billing, $supervisor, $accountant);

        // One removal waiting for the super admin
        $leaving = Occupancy::active()->whereHas('facility', fn ($q) => $q->where('supervisor_id', $supervisor->id))
            ->whereNot('id', Occupancy::min('id'))->inRandomOrder()->first();
        RemovalRequest::create(['occupancy_id' => $leaving->id, 'requested_by' => $supervisor->id, 'reason' => 'Moved out at the end of last month', 'status' => 'pending']);

        $this->memos($facilities->map(fn ($f) => $f[0]), $supervisor, $accountant, $management);
    }

    protected function pay(BillingService $billing, Occupancy $occupancy, ?float $amount, ?int $months, Carbon $date, User $accountant): void
    {
        $online = $months !== null || mt_rand(1, 100) <= 65;
        $method = $online ? 'online' : $this->pick(['cash' => 50, 'bank_transfer' => 35, 'pos' => 15]);

        $payment = Payment::create([
            'occupancy_id' => $occupancy->id,
            'amount' => $amount ?? $billing->amountFor($occupancy, $months),
            'months' => $months,
            'method' => $method,
            'source' => $online ? 'online' : 'manual',
            'reference' => $online ? null : strtoupper(substr($method, 0, 3)).'-'.mt_rand(100000, 999999),
            'gateway_reference' => $online ? 'CC-'.$occupancy->id.'-'.strtoupper(bin2hex(random_bytes(6))) : null,
            'paid_on' => $date,
            'status' => $online ? 'pending' : 'under_review',
            'recorded_by' => $online ? null : $occupancy->facility->supervisor_id,
        ]);

        $billing->confirmPayment($payment, $online ? null : $accountant);
        $payment->update(['reviewed_at' => $date->copy()->addDay()->setTime(10, 0)]);
    }

    /**
     * Manual payments still waiting for the accountant, and one sent back.
     */
    protected function pendingManualPayments(BillingService $billing, User $supervisor, User $accountant): void
    {
        $owing = Occupancy::active()->whereHas('invoices', fn ($q) => $q->open())->with(['invoices' => fn ($q) => $q->open(), 'user'])->inRandomOrder()->take(4)->get();

        foreach ($owing as $i => $occupancy) {
            $byUser = $i % 2 === 1;
            $payment = $billing->recordManualPayment($occupancy, [
                'months' => 1,
                'amount' => $occupancy->invoices->first()->balance(),
                'method' => $byUser ? 'bank_transfer' : 'cash',
                'reference' => $byUser ? 'TRF-'.mt_rand(100000, 999999) : null,
                'paid_on' => today()->subDays($i + 1),
            ], $byUser ? $occupancy->user : $supervisor);

            if ($byUser) {
                $path = "payment-proof/{$payment->id}/transfer-receipt.txt";
                Storage::disk(PaymentAttachment::DISK)->put($path, "Demo proof of payment for {$payment->reference}");
                $payment->attachments()->create(['path' => $path, 'original_name' => 'transfer-receipt.txt', 'mime' => 'text/plain', 'size' => 40, 'uploaded_by' => $occupancy->user_id]);
            }

            if ($i === 3) {
                $billing->rejectPayment($payment, $accountant, 'Transfer not found on the bank statement.');
            }
        }
    }

    protected function memos($facilities, User $supervisor, User $accountant, User $management): void
    {
        $items = [
            ['Cleaning chemicals restock', 'Supplies', 85000], ['Generator repair', 'Equipment', 96000],
            ['Fuel for generator', 'Fuel and transport', 120000], ['Overtime for security', 'Staff', 60000],
            ['Uniforms for cleaners', 'Uniforms', 48000], ['Waste disposal fees', 'Supplies', 34000],
            ['Plumbing repairs', 'Equipment', 55000], ['Mops, brooms and buckets', 'Supplies', 22000],
        ];

        foreach (range(0, 15) as $i) {
            [$text, $category, $amount] = $items[$i % count($items)];
            $date = today()->subDays(mt_rand(0, 55));
            $status = $date->gt(today()->subDays(5)) ? 'pending' : $this->pick(['approved' => 85, 'rejected' => 15]);

            Expense::create([
                'spent_on' => $date,
                'description' => $text,
                'category' => $category,
                'facility_id' => $facilities[$i % $facilities->count()]->id,
                'amount' => $amount + mt_rand(-10, 10) * 1000,
                'status' => $status,
                'requested_by' => $i % 3 ? $supervisor->id : $accountant->id,
                'reviewed_by' => $status === 'pending' ? null : $management->id,
                'reviewed_at' => $status === 'pending' ? null : $date->copy()->addDay(),
            ]);
        }
    }

    /**
     * @param  array<string, int>  $weights
     */
    protected function pick(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }
}
