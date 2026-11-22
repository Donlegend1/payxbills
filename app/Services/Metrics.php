<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the dashboard, receivables, analytics and reports pages.
 */
class Metrics
{
    public const BANDS = ['Current (0–30 days)', '31–60 days', '61–90 days', '91–180 days', 'Above 180 days'];

    public const BAND_COLORS = ['#1f9d6b', '#2457d6', '#e0a21b', '#7a5cc7', '#d64545'];

    /** @var Collection<int, Invoice>|null */
    protected ?Collection $open = null;

    /**
     * The month named by ?month=YYYY-MM, or the current month.
     */
    public function month(?string $value): Carbon
    {
        if ($value && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfDay();
        }

        return today()->startOfMonth();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function monthOptions(int $count = 12): array
    {
        return collect(range(0, $count - 1))
            ->map(fn ($i) => today()->startOfMonth()->subMonthsNoOverflow($i))
            ->map(fn (Carbon $m) => ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')])
            ->all();
    }

    public function billed(Carbon $month): float
    {
        return (float) Invoice::where('period', $month->format('Y-m'))->where('status', '!=', 'cancelled')->sum('amount');
    }

    public function invoiceCount(Carbon $month): int
    {
        return Invoice::where('period', $month->format('Y-m'))->where('status', '!=', 'cancelled')->count();
    }

    public function collected(Carbon $month): float
    {
        return (float) Payment::confirmed()
            ->whereBetween('paid_on', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->sum('amount');
    }

    /**
     * Share of the month's dues that has been paid (months paid ahead count for their own month).
     */
    public function collectionRate(Carbon $month): int
    {
        $billed = $this->billed($month);
        $paid = (float) Invoice::where('period', $month->format('Y-m'))->where('status', '!=', 'cancelled')->sum('amount_paid');

        return $billed > 0 ? (int) round($paid / $billed * 100) : 0;
    }

    /**
     * What active facility users should pay in a month, from their categories.
     */
    public function expected(): float
    {
        return (float) DB::table('occupancies')
            ->join('categories', 'categories.id', '=', 'occupancies.category_id')
            ->where('occupancies.company_id', CurrentCompany::id())
            ->where('occupancies.status', 'active')
            ->sum('categories.monthly_amount');
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function openInvoices(): Collection
    {
        return $this->open ??= Invoice::open()
            ->whereDate('due_date', '<=', today()->addDays(31))
            ->with(['occupancy:id,code,user_id,facility_id,space_id', 'occupancy.user:id,name', 'occupancy.facility:id,name', 'occupancy.space:id,number,label'])
            ->get();
    }

    public function band(Invoice $invoice): int
    {
        $days = $invoice->daysOverdue();

        return match (true) {
            $days <= 30 => 0,
            $days <= 60 => 1,
            $days <= 90 => 2,
            $days <= 180 => 3,
            default => 4,
        };
    }

    /**
     * Unpaid dues for months that have started (months paid ahead and not yet due are left out).
     */
    public function outstanding(): float
    {
        return round($this->openInvoices()->sum(fn (Invoice $i) => $i->balance()), 2);
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function overdueInvoices(): Collection
    {
        return $this->openInvoices()->filter(fn (Invoice $i) => $i->isOverdue());
    }

    /**
     * @return list<array{label: string, amount: float, color: string}>
     */
    public function aging(): array
    {
        $totals = array_fill(0, 5, 0.0);

        foreach ($this->openInvoices() as $invoice) {
            $totals[$this->band($invoice)] += $invoice->balance();
        }

        return array_map(fn ($i) => ['label' => self::BANDS[$i], 'amount' => round($totals[$i], 2), 'color' => self::BAND_COLORS[$i]], range(0, 4));
    }

    /**
     * Outstanding money per facility user, split into age bands, largest first.
     *
     * @return Collection<int, array{occupancy_id: int, name: string, place: string, bands: list<float>, total: float, days_overdue: int}>
     */
    public function agingByOccupancy(): Collection
    {
        return $this->openInvoices()
            ->groupBy('occupancy_id')
            ->map(function (Collection $invoices) {
                $bands = array_fill(0, 5, 0.0);
                foreach ($invoices as $invoice) {
                    $bands[$this->band($invoice)] += $invoice->balance();
                }
                $occupancy = $invoices->first()->occupancy;

                return [
                    'occupancy_id' => $occupancy->id,
                    'name' => $occupancy->user->name,
                    'place' => $occupancy->facility->name.' · '.$occupancy->space->name(),
                    'bands' => array_map(fn ($v) => round($v, 2), $bands),
                    'total' => round(array_sum($bands), 2),
                    'days_overdue' => $invoices->max(fn (Invoice $i) => $i->daysOverdue()),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Billed and collected for the $count months ending with $month.
     *
     * @return list<array{month: string, label: string, billed: float, collected: float, invoices: int}>
     */
    public function trend(Carbon $month, int $count = 6): array
    {
        return collect(range($count - 1, 0))
            ->map(fn ($i) => $month->copy()->subMonthsNoOverflow($i))
            ->map(fn (Carbon $m) => [
                'month' => $m->format('Y-m'),
                'label' => $m->format('M'),
                'billed' => $this->billed($m),
                'collected' => $this->collected($m),
                'invoices' => $this->invoiceCount($m),
            ])
            ->all();
    }

    /**
     * Active facility users by number of overdue months: none, one, or more than one.
     *
     * @return array{green: int, amber: int, red: int, total: int}
     */
    public function occupancyStatus(?Collection $facilityIds = null): array
    {
        $counts = Occupancy::active()
            ->when($facilityIds, fn ($q) => $q->whereIn('facility_id', $facilityIds))
            ->withCount(['invoices as overdue_count' => fn ($q) => $q->overdue()])
            ->pluck('overdue_count');

        return [
            'green' => $counts->filter(fn ($c) => $c == 0)->count(),
            'amber' => $counts->filter(fn ($c) => $c == 1)->count(),
            'red' => $counts->filter(fn ($c) => $c > 1)->count(),
            'total' => $counts->count(),
        ];
    }

    /**
     * Average days from issue to full payment, over invoices paid in the last six months.
     */
    public function averageDaysToPay(): ?int
    {
        $avg = Invoice::where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereDate('paid_at', '>=', today()->subMonths(6))
            ->whereColumn('paid_at', '>=', 'issue_date')
            ->get(['issue_date', 'paid_at'])
            ->avg(fn (Invoice $i) => max(0, $i->issue_date->diffInDays($i->paid_at)));

        return $avg === null ? null : (int) round($avg);
    }

    /**
     * @return list<array{0: string, 1: float}>
     */
    public function collectedByMethod(Carbon $month): array
    {
        return Payment::confirmed()
            ->whereBetween('paid_on', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->selectRaw('method, sum(amount) as total')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [Payment::METHODS[$r->method] ?? $r->method, (float) $r->total])
            ->all();
    }

    /**
     * @return list<array{0: string, 1: float}>
     */
    public function collectedByFacility(Carbon $month): array
    {
        return $this->collectedBy($month, 'facilities', 'facility_id');
    }

    /**
     * @return list<array{0: string, 1: float}>
     */
    public function collectedByCategory(Carbon $month): array
    {
        return $this->collectedBy($month, 'categories', 'category_id');
    }

    /**
     * @return list<array{0: string, 1: float}>
     */
    protected function collectedBy(Carbon $month, string $table, string $column): array
    {
        return DB::table('payments')
            ->join('occupancies', 'occupancies.id', '=', 'payments.occupancy_id')
            ->join($table, "{$table}.id", '=', "occupancies.{$column}")
            ->where('payments.company_id', CurrentCompany::id())
            ->where('payments.status', 'confirmed')
            ->whereBetween('payments.paid_on', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->groupBy("{$table}.name")
            ->orderByDesc('total')
            ->selectRaw("{$table}.name as name, sum(payments.amount) as total")
            ->get()
            ->map(fn ($r) => [$r->name, (float) $r->total])
            ->all();
    }
}
