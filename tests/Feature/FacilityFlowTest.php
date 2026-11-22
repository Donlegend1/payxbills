<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\RemovalRequest;
use App\Models\Space;
use App\Models\User;
use App\Notifications\FacilityUserWelcome;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesFacilityUsers;
use Tests\TestCase;

class FacilityFlowTest extends TestCase
{
    use CreatesFacilityUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 09:00');
        Storage::fake('local');
    }

    public function test_supervisor_registers_a_facility_user_who_is_billed_once_the_admin_activates_them(): void
    {
        $supervisor = $this->staff('supervisor');
        $facility = $this->facility($supervisor);
        $category = $this->category(20000);
        $space = $facility->spaces()->first();
        Notification::fake();

        $this->actingAs($supervisor)->post('/facility-users', [
            'name' => 'Ada Okafor', 'email' => 'ada@example.com', 'phone' => '08012345678',
            'space_id' => $space->id, 'category_id' => $category->id, 'start_date' => '2026-03-01',
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $occupancy = $user->occupancy;
        $this->assertTrue($user->isFacilityUser());
        $this->assertFalse($user->must_change_password, 'no password is sent: they pay from the link');
        $this->assertSame($space->id, $occupancy->space_id);
        $this->assertSame('pending', $occupancy->billingState());
        $this->assertSame(0, Invoice::count(), 'not billed before activation');
        $this->assertSame(0, MessageLog::count(), 'nothing sent before activation');

        // Only the admin starts billing
        $this->post("/facility-users/{$occupancy->id}/activate")->assertForbidden();
        $this->actingAs($this->staff('admin'))->post("/facility-users/{$occupancy->id}/activate")->assertSessionHasNoErrors();

        $this->assertSame('active', $occupancy->fresh()->billingState());
        $welcome = MessageLog::where('type', 'activation')->where('channel', 'email')->sole();
        $this->assertStringContainsString($occupancy->payUrl(), $welcome->message);
        $this->assertStringContainsString(route('password.request'), $welcome->message, 'how to see their transactions');
        Notification::assertNothingSent();

        $this->assertEquals(20000, Invoice::sole()->amount);
        $this->assertTrue(MessageLog::where('type', 'invoice')->where('channel', 'sms')->exists());

        // Deactivating stops billing
        $this->post("/facility-users/{$occupancy->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertSame('deactivated', $occupancy->fresh()->billingState());
        $this->assertTrue(app(BillingService::class)->generateMonthly(Carbon::parse('2026-04-01'))->isEmpty());
    }

    public function test_a_space_holds_one_facility_user_at_a_time(): void
    {
        $admin = $this->staff('admin');
        $facility = $this->facility(spaces: 1);
        $taken = $this->occupancy($facility);

        $this->actingAs($admin)->post('/facility-users', [
            'name' => 'Second', 'email' => 'second@example.com',
            'space_id' => $taken->space_id, 'category_id' => $taken->category_id, 'start_date' => '2026-03-01',
        ])->assertSessionHasErrors('space_id');

        $this->assertSame(1, Occupancy::count());
    }

    public function test_supervisors_only_see_and_register_in_their_own_facilities(): void
    {
        $supervisor = $this->staff('supervisor');
        $mine = $this->facility($supervisor);
        $other = $this->facility($this->staff('supervisor'));
        $this->occupancy($mine, user: ['name' => 'Mine']);
        $theirs = $this->occupancy($other, user: ['name' => 'Theirs']);

        $this->actingAs($supervisor)->get('/facility-users')->assertInertia(fn (Assert $page) => $page
            ->component('App/FacilityUsers')
            ->has('occupancies.data', 1)
            ->where('occupancies.data.0.name', 'Mine'));

        $this->actingAs($supervisor)->post('/facility-users', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com',
            'space_id' => Space::where('facility_id', $other->id)->vacant()->first()->id,
            'category_id' => $theirs->category_id, 'start_date' => '2026-03-01',
        ])->assertSessionHasErrors('space_id');

        $this->actingAs($supervisor)->post("/facility-users/{$theirs->id}/remind")->assertForbidden();
        $this->actingAs($supervisor)->get('/invoices')->assertForbidden();
    }

    public function test_removal_needs_super_admin_approval(): void
    {
        $supervisor = $this->staff('supervisor');
        $occupancy = $this->occupancy($this->facility($supervisor), start: '2026-03-01');
        app(BillingService::class)->confirmPayment(app(BillingService::class)->startOnlinePayment($occupancy, 1));
        app(BillingService::class)->issueForPeriod($occupancy, Carbon::parse('2026-05-01'));

        $this->actingAs($supervisor)->post("/facility-users/{$occupancy->id}/removal", ['reason' => 'Moved out'])->assertSessionHasNoErrors();

        $request = RemovalRequest::sole();
        $this->assertSame('pending', $request->status);
        $this->assertTrue($occupancy->fresh()->isActive(), 'still there until approved');
        $this->actingAs($supervisor)->post("/removals/{$request->id}/approve")->assertForbidden();

        $this->actingAs($this->staff('admin'))->post("/removals/{$request->id}/approve")->assertSessionHasNoErrors();

        $occupancy->refresh();
        $this->assertSame('ended', $occupancy->status);
        $this->assertFalse($occupancy->user->is_active);
        $this->assertSame(1, Space::vacant()->whereKey($occupancy->space_id)->count(), 'space is free again');
        $this->assertSame('cancelled', Invoice::where('period', '2026-05')->value('status'), 'unpaid month ahead cancelled');
        $this->assertSame('paid', Invoice::where('period', '2026-03')->value('status'));

        $this->post('/logout');
        $this->post('/login', ['email' => $occupancy->user->email, 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_a_removed_facility_user_can_be_registered_again_in_another_facility(): void
    {
        $admin = $this->staff('admin');
        $old = $this->occupancy($this->facility(), start: '2026-03-01', user: ['email' => 'ada@example.com']);
        $this->actingAs($admin)->post("/facility-users/{$old->id}/removal", ['reason' => 'Moved out'])->assertSessionHasNoErrors();
        $this->assertSame('ended', $old->fresh()->status);

        $newFacility = $this->facility();
        $space = $newFacility->spaces()->first();
        $form = ['name' => 'Ada Okafor', 'email' => 'ada@example.com', 'space_id' => $space->id, 'category_id' => $this->category()->id, 'start_date' => '2026-03-10'];

        $this->actingAs($admin)->post('/facility-users', $form)->assertSessionHasNoErrors();

        $user = $old->user->fresh();
        $this->assertSame(1, User::where('email', 'ada@example.com')->count(), 'same account, not a duplicate');
        $this->assertTrue($user->is_active);
        $this->assertSame($space->id, $user->occupancy->space_id);
        $this->assertSame('pending', $user->occupancy->billingState(), 'billed again only once activated');
        $this->assertSame(2, $user->occupancies()->count(), 'earlier occupancy kept on record');

        // While they hold a space, the email cannot be registered again
        $form['space_id'] = $newFacility->spaces()->skip(1)->first()->id;
        $this->post('/facility-users', $form)->assertSessionHasErrors('email');

        // Staff emails are never reused for facility users
        $this->post('/facility-users', [...$form, 'email' => $admin->email])->assertSessionHasErrors('email');
    }

    public function test_facility_user_must_change_the_emailed_password_then_sees_their_portal(): void
    {
        $occupancy = $this->occupancy(start: '2026-03-01', user: ['must_change_password' => true]);
        $user = $occupancy->user;

        $this->actingAs($user)->get('/portal')->assertRedirect(route('profile.edit'));

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password', 'password' => 'n3w-Passw0rd!', 'password_confirmation' => 'n3w-Passw0rd!',
        ])->assertRedirect(route('dashboard'));

        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('portal'));
        $this->actingAs($user->fresh())->get('/portal')->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Home')
            ->where('account.facility.name', 'Green Valley Hostel')
            ->where('account.space', 'Room 101, Green Valley Hostel')
            ->has('options', 12));

        foreach (['/facility-users', '/payments', '/invoices', '/settings'] as $url) {
            $this->actingAs($user->fresh())->get($url)->assertForbidden();
        }
    }

    public function test_paying_months_online_through_paystack(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_x']);
        $occupancy = $this->occupancy(start: '2026-03-01');
        app(BillingService::class)->issueForPeriod($occupancy, Carbon::parse('2026-03-01'));

        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']]),
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'currency' => 'NGN', 'amount' => 4500000]]),
        ]);

        // From the signed pay link in the SMS, no login
        $this->post($this->signedPayStart($occupancy), ['months' => 3], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.paystack.com/abc');

        $payment = Payment::sole();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/initialize') && $r['amount'] === 4500000 && $r['reference'] === $payment->gateway_reference);

        $this->get('/pay/callback?reference='.$payment->gateway_reference)->assertRedirect($payment->fresh()->receiptUrl());

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('2026-05', $occupancy->paidThrough()->format('Y-m'));
        $this->assertSame(2, MessageLog::where('type', 'receipt')->count(), 'email and SMS receipt');

        // The webhook arriving afterwards does not send a second receipt
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $payment->gateway_reference]]);
        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_x')], $body)->assertOk();
        $this->assertSame(2, MessageLog::where('type', 'receipt')->count());
    }

    public function test_webhook_with_a_bad_signature_is_ignored(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_x']);
        $payment = app(BillingService::class)->startOnlinePayment($this->occupancy(start: '2026-03-01'), 1);
        Http::fake();

        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $payment->gateway_reference]]);
        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'forged'], $body)->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_manual_payment_counts_only_once_an_accountant_clears_it_with_reference_and_proof(): void
    {
        $supervisor = $this->staff('supervisor');
        $accountant = $this->staff('accountant');
        $occupancy = $this->occupancy($this->facility($supervisor), start: '2026-03-01');
        app(BillingService::class)->issueForPeriod($occupancy, Carbon::parse('2026-03-01'));

        $this->actingAs($supervisor)->post('/payments', [
            'occupancy_id' => $occupancy->id, 'months' => 1, 'amount' => 15000, 'method' => 'cash', 'paid_on' => '2026-03-09',
        ])->assertSessionHasNoErrors();

        $payment = Payment::sole();
        $this->assertSame('under_review', $payment->status);
        $this->assertSame('unpaid', Invoice::sole()->status);

        $this->actingAs($supervisor)->post("/payments/{$payment->id}/clear", ['reference' => 'TEL-1'])->assertForbidden();
        $this->actingAs($this->staff('admin'))->post("/payments/{$payment->id}/clear", ['reference' => 'TEL-1'])->assertForbidden();
        $this->actingAs($accountant)->post("/payments/{$payment->id}/clear", ['reference' => 'TEL-1'])->assertSessionHasErrors('files');
        $this->assertSame('under_review', $payment->fresh()->status);

        $this->actingAs($accountant)->post("/payments/{$payment->id}/clear", [
            'reference' => 'TEL-1', 'files' => [UploadedFile::fake()->create('teller.pdf', 50, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame('TEL-1', $payment->reference);
        $this->assertSame('paid', Invoice::sole()->status);
        Storage::disk('local')->assertExists($payment->attachments()->first()->path);
        $this->assertTrue(MessageLog::where('type', 'receipt')->where('payment_id', $payment->id)->exists());
    }

    public function test_facility_user_submits_transfer_proof_from_the_portal(): void
    {
        $occupancy = $this->occupancy(start: '2026-03-01');

        $this->actingAs($occupancy->user)->post('/portal/payments', [
            'months' => 2, 'amount' => 30000, 'method' => 'bank_transfer', 'reference' => 'TRF-99', 'paid_on' => '2026-03-09',
            'files' => [UploadedFile::fake()->image('receipt.jpg')],
        ])->assertSessionHasNoErrors();

        $payment = Payment::sole();
        $this->assertSame(['under_review', 2, 'manual'], [$payment->status, $payment->months, $payment->source]);
        $this->assertSame(1, $payment->attachments()->count());

        $attachment = $payment->attachments()->first();
        $this->actingAs($occupancy->user)->get("/payment-attachments/{$attachment->id}")->assertOk();
        $this->actingAs($this->occupancy(user: ['email' => 'other@example.com'])->user)->get("/payment-attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_receipt_shows_the_facility_details_and_months_covered(): void
    {
        $occupancy = $this->occupancy(start: '2026-03-01');
        $billing = app(BillingService::class);
        $payment = $billing->startOnlinePayment($occupancy, 2);
        $billing->confirmPayment($payment);

        $this->get($payment->receiptUrl())->assertInertia(fn (Assert $page) => $page
            ->component('Receipt')
            ->where('receipt.facility.name', 'Green Valley Hostel')
            ->where('receipt.facility.address', 'Plot 12, Lekki')
            ->where('receipt.space', 'Room 101, Green Valley Hostel')
            ->where('receipt.lines.0.month', 'March 2026')
            ->where('receipt.lines.1.month', 'April 2026'));

        $this->get('/r/'.$payment->id)->assertForbidden();
        $this->actingAs($this->occupancy(user: ['email' => 'nosy@example.com'])->user)->get("/receipts/{$payment->id}")->assertForbidden();
        $this->actingAs($occupancy->user)->get("/receipts/{$payment->id}")->assertOk();
    }

    protected function signedPayStart(Occupancy $occupancy): string
    {
        return URL::signedRoute('pay.start', $occupancy);
    }
}
