<?php

namespace App\Http\Controllers;

use App\Models\ExpenseLine;
use App\Models\Invoice;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Services\Metrics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public const REPORTS = [
        'invoices' => ['Monthly dues report', 'Every invoice for the month with amount, paid, balance and status'],
        'occupancy' => ['Facility occupancy', 'Every facility user by facility and space, with category, paid-up-to month and balance (as of today)'],
        'aged-receivables' => ['Aged receivables', 'Who owes, how much and for how long (as of today)'],
        'payments' => ['Payments register', 'All payments with method, source and who cleared them'],
        'statement' => ['Facility user statement', 'Invoices and payments for one facility user'],
        'expenditure' => ['Expenditure by facility', 'Approved amounts by facility and category (partially approved memos: approved categories only)'],
        'collection' => ['Collection performance', 'Billed against collected by month, facility and category'],
    ];

    public function index(Metrics $metrics): Response
    {
        return Inertia::render('App/Reports', [
            'reports' => collect(self::REPORTS)->map(fn ($r, $key) => ['key' => $key, 'title' => $r[0], 'description' => $r[1]])->values(),
            'months' => $metrics->monthOptions(),
            'occupancies' => Occupancy::with(['user:id,name', 'facility:id,name', 'space:id,number,label'])->get()
                ->sortBy(fn (Occupancy $o) => $o->user->name)
                ->map(fn (Occupancy $o) => ['id' => $o->id, 'name' => "{$o->user->name} – {$o->placeName()}".($o->isActive() ? '' : ' (removed)')])
                ->values(),
            'summary' => $metrics->trend(today()->startOfMonth()),
        ]);
    }

    public function download(Request $request, string $report, Metrics $metrics): StreamedResponse
    {
        abort_unless(isset(self::REPORTS[$report]), 404);

        $month = $metrics->month($request->query('month'));
        $range = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];

        [$header, $rows] = match ($report) {
            'invoices' => $this->invoices($month),
            'occupancy' => $this->occupancy(),
            'aged-receivables' => $this->agedReceivables($metrics),
            'payments' => $this->payments($range),
            'statement' => $this->statement(Occupancy::with('user')->findOrFail($request->integer('occupancy'))),
            'expenditure' => $this->expenditure($range),
            'collection' => $this->collection($metrics, $month),
        };

        $asOfToday = in_array($report, ['aged-receivables', 'occupancy'], true);
        $filename = $report.'-'.($asOfToday ? today()->toDateString() : $month->format('Y-m')).'.csv';

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ₦ and accents correctly
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function invoices(Carbon $month): array
    {
        $rows = Invoice::with(['occupancy.user:id,name', 'occupancy.facility:id,name', 'occupancy.space:id,number,label'])
            ->where('period', $month->format('Y-m'))->orderBy('number')->get()
            ->map(fn (Invoice $i) => [$i->number, $i->occupancy->code, $i->occupancy->user->name, $i->occupancy->facility?->name ?? '', $i->occupancy->placeName(), $i->period, $i->issue_date->toDateString(), $i->due_date->toDateString(), $i->amount, $i->amount_paid, $i->balance(), $i->displayStatus()]);

        return [['Invoice', 'Account', 'Facility user', 'Facility', 'Space', 'Month', 'Issued', 'Due', 'Amount', 'Paid', 'Balance', 'Status'], $rows];
    }

    protected function occupancy(): array
    {
        $rows = Occupancy::active()->with(['user:id,name,email,phone', 'facility:id,name', 'space:id,number,label', 'category:id,name,monthly_amount', 'binType:id,name'])->get()
            ->sortBy(fn (Occupancy $o) => [$o->facility?->name ?? $o->district ?? '', $o->space?->number ?? $o->street ?? ''])
            ->map(fn (Occupancy $o) => [$o->facility?->name ?? '', $o->placeName(), $o->code, $o->user->name, $o->user->email, $o->user->phone, $o->categoryName() ?? '', $o->monthlyAmount(), $o->start_date->toDateString(), $o->paidThrough()?->format('Y-m') ?? '', $o->outstanding()]);

        return [['Facility', 'Space', 'Account', 'Facility user', 'Email', 'Phone', 'Category', 'Monthly amount', 'Since', 'Paid up to', 'Outstanding'], $rows];
    }

    protected function agedReceivables(Metrics $metrics): array
    {
        $rows = $metrics->agingByOccupancy()->map(fn ($r) => [$r['name'], $r['place'], ...$r['bands'], $r['total'], $r['days_overdue']]);

        return [['Facility user', 'Facility · space', '0-30 days', '31-60 days', '61-90 days', '91-180 days', 'Over 180 days', 'Total', 'Oldest days overdue'], $rows];
    }

    protected function payments(array $range): array
    {
        $rows = Payment::with(['occupancy.user:id,name', 'occupancy.facility:id,name', 'recorder:id,name,role', 'reviewer:id,name'])
            ->where('status', '!=', 'pending')
            ->whereBetween('paid_on', $range)->orderBy('paid_on')->get()
            ->map(fn (Payment $p) => [$p->paid_on->toDateString(), $p->receipt_number, $p->occupancy->code, $p->occupancy->user->name, $p->occupancy->facility?->name ?? '', $p->amount, $p->months, Payment::METHODS[$p->method], $p->sourceLabel(), $p->reference ?? $p->gateway_reference, Payment::STATUSES[$p->status], $p->reviewer?->name]);

        return [['Date', 'Receipt', 'Account', 'Facility user', 'Facility', 'Amount', 'Months', 'Method', 'Recorded by', 'Reference', 'Status', 'Cleared by'], $rows];
    }

    protected function statement(Occupancy $occupancy): array
    {
        $lines = $occupancy->invoices()->where('status', '!=', 'cancelled')->get()
            ->map(fn (Invoice $i) => ['date' => $i->issue_date, 'ref' => $i->number, 'text' => $i->description, 'debit' => (float) $i->amount, 'credit' => 0.0])
            ->concat($occupancy->payments()->confirmed()->get()
                ->map(fn (Payment $p) => ['date' => $p->paid_on, 'ref' => $p->receipt_number, 'text' => 'Payment – '.Payment::METHODS[$p->method], 'debit' => 0.0, 'credit' => (float) $p->amount]))
            ->sortBy(fn ($l) => $l['date']->timestamp)
            ->values();

        $balance = 0;
        $name = $occupancy->user->name;
        $rows = $lines->map(function ($l) use (&$balance, $name) {
            $balance = round($balance + $l['debit'] - $l['credit'], 2);

            return [$name, $l['date']->toDateString(), $l['ref'], $l['text'], $l['debit'] ?: '', $l['credit'] ?: '', $balance];
        });

        return [['Facility user', 'Date', 'Reference', 'Details', 'Billed', 'Paid', 'Balance'], $rows];
    }

    protected function expenditure(array $range): array
    {
        // Only approved categories of approved or partially approved memos, at their approved amounts
        $rows = ExpenseLine::recorded()->with('expense.facility:id,name')
            ->whereHas('expense', fn ($q) => $q->whereBetween('spent_on', $range))->get()
            ->sortBy(fn (ExpenseLine $l) => [$l->expense->facility?->name ?? 'ZZZ', $l->category])
            ->map(fn (ExpenseLine $l) => [$l->expense->facility?->name ?? 'Not charged', $l->category, $l->expense->memo_number, $l->expense->spent_on->toDateString(), $l->expense->description, $l->expense->statusLabel(), $l->amount, $l->approved_amount]);

        return [['Facility', 'Category', 'Memo', 'Date', 'Description', 'Status', 'Requested', 'Approved'], $rows];
    }

    protected function collection(Metrics $metrics, Carbon $month): array
    {
        $rows = collect($metrics->trend($month, 12))
            ->map(fn ($t) => [$t['month'], 'All facilities', $t['invoices'], $t['billed'], $t['collected']])
            ->concat(collect($metrics->collectedByFacility($month))->map(fn ($e) => [$month->format('Y-m'), 'Facility: '.$e[0], '', '', $e[1]]))
            ->concat(collect($metrics->collectedByCategory($month))->map(fn ($e) => [$month->format('Y-m'), 'Category: '.$e[0], '', '', $e[1]]));

        return [['Month', 'Group', 'Invoices', 'Billed', 'Collected'], $rows];
    }
}
