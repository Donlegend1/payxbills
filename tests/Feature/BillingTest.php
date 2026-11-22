<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\CreatesFacilityUsers;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use CreatesFacilityUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 09:00');
    }

    public function test_monthly_billing_issues_one_invoice_per_facility_user_per_month_at_the_category_amount(): void
    {
        $occupancy = $this->occupancy(category: $this->category(20000));
        Setting::put(['billing_day' => 5]);
        $billing = app(BillingService::class);

        $this->assertCount(0, $billing->generateMonthly(Carbon::parse('2026-03-04')), 'billing day not reached');
        $this->assertCount(1, $billing->generateMonthly(Carbon::parse('2026-03-05')));
        $this->assertCount(0, $billing->generateMonthly(Carbon::parse('2026-03-20')), 'already billed');

        $invoice = $occupancy->invoices()->first();
        $this->assertSame('2026-03', $invoice->period);
        $this->assertEquals(20000, $invoice->amount);
        $this->assertSame('2026-03-19', $invoice->due_date->toDateString(), 'due 14 days after the billing day');
        $this->assertStringContainsString('Green Valley Hostel', $invoice->description);
    }

    public function test_removed_facility_users_are_not_billed(): void
    {
        $occupancy = $this->occupancy();
        $occupancy->update(['status' => 'ended']);

        $this->assertCount(0, app(BillingService::class)->generateMonthly());
    }

    public function test_paying_several_months_pays_unpaid_months_first_then_months_ahead(): void
    {
        $occupancy = $this->occupancy(start: '2026-02-01');
        $billing = app(BillingService::class);
        $billing->issueForPeriod($occupancy, Carbon::parse('2026-02-01'));
        $billing->issueForPeriod($occupancy, Carbon::parse('2026-03-01'));

        $plan = $billing->plan($occupancy, 4);
        $this->assertSame(['2026-02', '2026-03', '2026-04', '2026-05'], $plan->pluck('period')->all());
        $this->assertEquals(60000, $billing->amountFor($occupancy, 4));

        $payment = $billing->startOnlinePayment($occupancy, 4);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(2, $occupancy->invoices()->count(), 'nothing issued until the payment is confirmed');

        $billing->confirmPayment($payment);

        $this->assertSame(['paid', 'paid', 'paid', 'paid'], $occupancy->invoices()->orderBy('period')->pluck('status')->all());
        $this->assertSame('2026-05', $occupancy->paidThrough()->format('Y-m'));
        $this->assertSame('RCT-'.str_pad($payment->id, 6, '0', STR_PAD_LEFT), $payment->receipt_number);

        // The scheduler does not bill April again
        $this->assertCount(0, $billing->generateMonthly(Carbon::parse('2026-04-02')));
    }

    public function test_confirming_twice_does_nothing_the_second_time(): void
    {
        $occupancy = $this->occupancy(start: '2026-03-01');
        $billing = app(BillingService::class);
        $payment = $billing->startOnlinePayment($occupancy, 2);

        $this->assertTrue($billing->confirmPayment($payment));
        $this->assertFalse($billing->confirmPayment($payment));
        $this->assertEquals(30000, $payment->allocations()->sum('amount'));
    }

    public function test_overpayment_is_used_against_the_next_month(): void
    {
        $occupancy = $this->occupancy(start: '2026-03-01');
        $billing = app(BillingService::class);
        $billing->issueForPeriod($occupancy, Carbon::parse('2026-03-01'));

        $payment = $billing->recordManualPayment($occupancy, ['amount' => 20000, 'method' => 'cash', 'paid_on' => '2026-03-02'], $this->staff('supervisor'));
        $billing->confirmPayment($payment);
        $this->assertEquals(5000, $billing->credit($occupancy));

        $april = $billing->issueForPeriod($occupancy, Carbon::parse('2026-04-01'));

        $this->assertEquals(5000, $april->amount_paid);
        $this->assertEquals(0, $billing->credit($occupancy));
        $this->assertEquals(10000, $billing->amountFor($occupancy, 1), 'credit taken off what is owed');
    }

    public function test_pay_options_run_from_one_to_max_months(): void
    {
        $occupancy = $this->occupancy(start: '2026-03-01');
        Setting::put(['max_months' => 6]);
        app(BillingService::class)->issueForPeriod($occupancy, Carbon::parse('2026-03-01'));

        $options = app(BillingService::class)->payOptions($occupancy);

        $this->assertCount(6, $options);
        $this->assertSame(['March 2026', 15000.0], [$options[0]['through'], $options[0]['amount']]);
        $this->assertSame(['August 2026', 90000.0], [$options[5]['through'], $options[5]['amount']]);
    }

    public function test_invoice_is_overdue_after_due_date(): void
    {
        $occupancy = $this->occupancy(start: '2026-01-01');
        app(BillingService::class)->issueForPeriod($occupancy, Carbon::parse('2026-02-01'));

        $this->assertSame('Overdue', Invoice::first()->displayStatus());
        $this->assertSame(1, Invoice::overdue()->count());
    }

    public function test_generate_command_issues_and_sends_the_pay_link(): void
    {
        $this->occupancy(start: '2026-01-01');

        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::count());
        $this->assertNotNull(Invoice::first()->sent_at);
        $messages = MessageLog::where('type', 'invoice')->where('status', 'sent')->get();
        $this->assertCount(2, $messages, 'one email and one SMS');
        $this->assertStringContainsString('/pay/', $messages->firstWhere('channel', 'sms')->message);
    }

    public function test_reminders_go_out_on_the_configured_days_only(): void
    {
        Setting::put(['reminder_days' => '0, 3']);
        $occupancy = $this->occupancy(start: '2026-01-01');
        $billing = app(BillingService::class);
        $dueToday = $billing->issueForPeriod($occupancy, Carbon::parse('2026-02-24')->startOfMonth());
        $dueToday->update(['due_date' => '2026-03-10']);
        $threeDays = $billing->issueForPeriod($occupancy, Carbon::parse('2026-01-01'));
        $threeDays->update(['due_date' => '2026-03-07']);
        $notToday = $billing->issueForPeriod($occupancy, Carbon::parse('2026-03-01'));
        $notToday->update(['due_date' => '2026-03-05']);

        $this->artisan('billing:send-reminders')->assertSuccessful();
        $this->artisan('billing:send-reminders')->assertSuccessful();

        $reminded = MessageLog::where('type', 'reminder')->where('channel', 'email')->pluck('invoice_id')->all();
        $this->assertEqualsCanonicalizing([$dueToday->id, $threeDays->id], $reminded, 'once each, on day 0 and day 3');
    }

    public function test_rejected_payment_does_not_touch_invoices(): void
    {
        $occupancy = $this->occupancy(start: '2026-03-01');
        app(BillingService::class)->issueForPeriod($occupancy, Carbon::parse('2026-03-01'));
        $payment = Payment::create(['occupancy_id' => $occupancy->id, 'amount' => 15000, 'method' => 'cheque', 'source' => 'manual', 'paid_on' => '2026-03-05']);

        $this->actingAs($this->staff('accountant'))
            ->post("/payments/{$payment->id}/reject", ['reason' => 'Bounced cheque'])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame('unpaid', Invoice::first()->status);
    }
}
