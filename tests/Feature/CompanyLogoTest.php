<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Payment;
use App\Services\BillingService;
use App\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesFacilityUsers;
use Tests\TestCase;

class CompanyLogoTest extends TestCase
{
    use CreatesFacilityUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 09:00');
        Storage::fake('public');
    }

    public function test_company_admin_uploads_a_logo_that_is_served_publicly(): void
    {
        $this->actingAs($this->staff('admin'))
            ->post('/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 100)])
            ->assertSessionHasNoErrors();

        $company = $this->company->fresh();
        Storage::disk('public')->assertExists($company->logo_path);

        $this->get('/settings')->assertInertia(fn (Assert $page) => $page
            ->where('logo', $company->logoUrl())
            ->where('currentCompany.logo', $company->logoUrl()));

        auth()->logout();
        $this->get($company->logoUrl())->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_receipt_shows_the_paid_companys_name_and_logo(): void
    {
        $this->company->update(['name' => 'Cosmopolitan Cleaners Limited', 'logo_path' => UploadedFile::fake()->image('l.png')->store('logos/1', 'public')]);
        $payment = $this->confirmedPayment();
        CurrentCompany::set(null);

        $this->get($payment->receiptUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Receipt')
            ->where('receipt.company.name', 'Cosmopolitan Cleaners Limited')
            ->where('receipt.company.logo', $this->company->fresh()->logoUrl()));
    }

    public function test_receipt_without_a_logo_shows_the_name_only(): void
    {
        $payment = $this->confirmedPayment();

        $this->get($payment->receiptUrl())->assertInertia(fn (Assert $page) => $page
            ->where('receipt.company.name', 'Test Company')
            ->where('receipt.company.logo', null));
    }

    public function test_each_company_gets_its_own_logo_on_its_receipts(): void
    {
        $this->company->update(['logo_path' => 'logos/1/mine.png']);
        $other = Company::create(['name' => 'Sunrise Estates', 'logo_path' => 'logos/2/sunrise.png']);
        $payment = CurrentCompany::run($other, fn () => $this->confirmedPayment());
        CurrentCompany::set(null);

        $this->get($payment->receiptUrl())->assertInertia(fn (Assert $page) => $page
            ->where('receipt.company.name', 'Sunrise Estates')
            ->where('receipt.company.logo', $other->logoUrl()));
    }

    public function test_replacing_the_logo_deletes_the_old_file_and_removing_clears_it(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin)->post('/settings/logo', ['logo' => UploadedFile::fake()->image('a.png', 100, 100)]);
        $first = $this->company->fresh()->logo_path;

        $this->post('/settings/logo', ['logo' => UploadedFile::fake()->image('b.jpg', 100, 100)]);
        Storage::disk('public')->assertMissing($first);
        $second = $this->company->fresh()->logo_path;

        $this->delete('/settings/logo')->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($second);
        $this->assertNull($this->company->fresh()->logo_path);
    }

    public function test_only_images_are_accepted_and_only_the_admin_can_upload(): void
    {
        $this->actingAs($this->staff('admin'))
            ->post('/settings/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
            ->assertSessionHasErrors('logo');

        $this->actingAs($this->staff('accountant'))
            ->post('/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertForbidden();

        $this->assertNull($this->company->fresh()->logo_path);
    }

    protected function confirmedPayment(): Payment
    {
        $billing = app(BillingService::class);
        $payment = $billing->startOnlinePayment($this->occupancy(), 1);
        $billing->confirmPayment($payment);

        return $payment->fresh();
    }
}
