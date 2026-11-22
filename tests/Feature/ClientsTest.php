<?php

namespace Tests\Feature;

use App\Models\BinType;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\Occupancy;
use App\Models\PaymentAttachment;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\CreatesFacilityUsers;
use Tests\TestCase;

/**
 * Clients with an address, bins and their own amount; every detail but the name optional;
 * enrolled from Excel and updated afterwards.
 */
class ClientsTest extends TestCase
{
    use CreatesFacilityUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 09:00');
        Notification::fake();
    }

    public function test_a_client_can_be_registered_with_just_a_name(): void
    {
        $this->actingAs($this->staff('admin'))->post('/facility-users', ['name' => 'Walk-in Client'])->assertSessionHasNoErrors();

        $occupancy = Occupancy::sole();
        $this->assertSame('Walk-in Client', $occupancy->user->name);
        $this->assertNull($occupancy->user->email);
        $this->assertNull($occupancy->facility_id);
        $this->assertSame('—', $occupancy->placeName());
        $this->assertSame(0, Invoice::count(), 'nothing to bill without an amount');
        $this->assertSame(0, MessageLog::count(), 'no email or phone to send to');
    }

    public function test_waste_client_with_address_and_bins_pays_their_category_amount_on_their_day(): void
    {
        $house = Category::create(['name' => 'House', 'monthly_amount' => 8000]);
        $wheeler = BinType::create(['name' => 'Wheeler bin']);

        $this->actingAs($this->staff('admin'))->post('/facility-users', [
            'name' => 'Okonkwo Residence', 'phone' => '08012345678', 'house_no' => '12', 'street' => 'Allen Avenue', 'district' => 'Ikeja',
            'category_id' => $house->id, 'bin_type_id' => $wheeler->id, 'bins' => 2, 'frequency' => 3, 'billing_day' => 5,
            'start_date' => '2026-03-01',
        ])->assertSessionHasNoErrors();

        $occupancy = Occupancy::sole();
        $this->post("/facility-users/{$occupancy->id}/activate")->assertSessionHasNoErrors();
        $this->assertSame('12 Allen Avenue, Ikeja', $occupancy->placeName());
        $this->assertSame('2 Wheeler bin, 3× a week', $occupancy->serviceSummary());

        $invoice = Invoice::sole();
        $this->assertEquals(8000, $invoice->amount);
        $this->assertSame('2026-03-05', $invoice->issue_date->toDateString(), 'billed on their own day');
        $this->assertStringContainsString('House dues – March 2026 (12 Allen Avenue, Ikeja)', $invoice->description);
        $this->assertTrue(MessageLog::where('channel', 'sms')->where('type', 'invoice')->exists());
    }

    public function test_monthly_billing_waits_for_each_facility_users_billing_day(): void
    {
        $category = $this->category(15000);
        $late = $this->occupancy(category: $category);
        $late->update(['billing_day' => 15]);
        $usual = $this->occupancy(category: $category);
        $billing = app(BillingService::class);

        $this->assertSame([$usual->id], $billing->generateMonthly()->pluck('occupancy_id')->all());
        $this->assertSame([$late->id], $billing->generateMonthly(Carbon::parse('2026-03-15'))->pluck('occupancy_id')->all());
    }

    public function test_changing_a_category_amount_reprices_everyone_in_it_from_their_next_bill(): void
    {
        $category = $this->category(15000);
        $a = $this->occupancy(category: $category);
        $b = $this->occupancy(category: $category);
        $billing = app(BillingService::class);
        $billing->generateMonthly();

        $this->actingAs($this->staff('admin'))->put("/categories/{$category->id}", ['name' => $category->name, 'monthly_amount' => 18000, 'is_active' => true])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 facility user(s) in it pay the new amount'));

        $this->assertSame([18000.0, 18000.0], [$a->fresh()->monthlyAmount(), $b->fresh()->monthlyAmount()]);
        $this->assertEquals([15000, 15000], Invoice::orderBy('id')->pluck('amount')->map(fn ($v) => (float) $v)->all(), 'months already billed keep their amount');

        $april = $billing->generateMonthly(Carbon::parse('2026-04-01'));
        $this->assertEquals([18000, 18000], $april->pluck('amount')->map(fn ($v) => (float) $v)->all());
    }

    public function test_client_details_can_be_updated(): void
    {
        $occupancy = $this->occupancy();
        $school = Category::create(['name' => 'School', 'monthly_amount' => 30000]);

        $this->actingAs($this->staff('admin'))->put("/facility-users/{$occupancy->id}", [
            'name' => 'Renamed', 'email' => null, 'facility_id' => $occupancy->facility_id, 'space_id' => $occupancy->space_id,
            'street' => 'School Road', 'district' => 'Surulere', 'category_id' => $school->id, 'frequency' => 5,
        ])->assertSessionHasNoErrors();

        $occupancy->refresh();
        $this->assertSame('Renamed', $occupancy->user->name);
        $this->assertNull($occupancy->user->email);
        $this->assertSame('School', $occupancy->categoryName());
        $this->assertSame(30000.0, $occupancy->monthlyAmount());
        $this->assertNotNull($occupancy->space_id, 'kept their space');
    }

    public function test_clients_are_enrolled_and_updated_from_an_excel_file(): void
    {
        $existing = $this->occupancy(user: ['email' => 'known@example.com', 'phone' => '08000000000']);

        $file = $this->xlsx([
            ['Full Name', 'Email', 'Phone', 'House No.', 'Street', 'District', 'Category of Facility', 'Type of Bins', 'No. of Bins', 'Frequency', 'Amount', 'Billing Date', 'Account code'],
            ['Bright Future Academy', 'office@bright.example.com', '0803 111 2222', '4', 'School Road', 'Surulere', 'School', 'Mammoth Bin', '3', '5 times', '₦65,000', 10, ''],
            ['No Details Person', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['Bad Email', 'not-an-email', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '08099999999', '', '', '', '', '', '', '', '', '', $existing->code],
            ['', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['Unknown Category', '', '', '', '', '', 'Castle', '', '', '', '', '', ''],
        ]);

        $this->assertSame('FU-'.(1000 + $existing->id), $existing->code, 'every client gets an account code');

        $this->actingAs($this->staff('admin'))->post('/facility-users/import', ['file' => $file])
            ->assertSessionHas('error', '2 facility user(s) added (activate them to start billing), 1 updated. 2 row(s) were skipped: see the list below.')
            ->assertSessionHas('importErrors', fn ($errors) => count($errors) === 2 && str_starts_with($errors[0], 'Row 4:')
                && str_contains($errors[1], 'There is no category called Castle'));

        $school = Occupancy::whereHas('user', fn ($q) => $q->where('email', 'office@bright.example.com'))->sole();
        $this->assertSame('School', $school->category->name, 'new category added with the amount from the sheet');
        $this->assertEquals(65000, $school->category->monthly_amount);
        $this->assertSame('Mammoth Bin', $school->binType->name);
        $this->assertSame([3, 5, 10], [$school->bins, $school->frequency, $school->billing_day]);

        $this->assertTrue(User::where('name', 'No Details Person')->exists());

        $existing->refresh();
        $this->assertSame('08099999999', $existing->user->phone, 'updated by account code');
        $this->assertSame('known@example.com', $existing->user->email, 'blank cells leave details unchanged');
        $this->assertSame(0, MessageLog::count(), 'nothing sent until activated');
        $this->assertFalse($school->billing_active);
    }

    public function test_csv_import_and_billing_dates_become_days(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
        file_put_contents($path, "Full name,Email,Billing date,Amount\nAda Okafor,ada@example.com,2026-03-31,5000\n");

        $this->actingAs($this->staff('admin'))
            ->post('/facility-users/import', ['file' => new UploadedFile($path, 'clients.csv', 'text/csv', null, true), 'activate' => true])
            ->assertSessionHas('success', '1 facility user(s) added and activated, 0 updated.');

        $this->assertSame(28, Occupancy::sole()->billing_day, '31st becomes 28th so every month has it');
        $this->assertTrue(Occupancy::sole()->billing_active, 'admin chose to activate on upload');
        $this->assertSame(1, MessageLog::where('type', 'activation')->count(), 'pay link sent on activation');
    }

    public function test_a_file_without_headings_is_refused(): void
    {
        $this->actingAs($this->staff('admin'))
            ->post('/facility-users/import', ['file' => $this->xlsx([['Ada', 'ada@example.com']])])
            ->assertSessionHasErrors('file');
    }

    public function test_template_and_client_export_download_as_excel(): void
    {
        $this->occupancy();
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get('/facility-users/template')->assertOk()->assertDownload('clients-template.xlsx');
        $this->get('/facility-users/export')->assertOk()->assertDownload('clients-2026-03-10.xlsx');
        $this->actingAs($this->staff('accountant'))->get('/facility-users/export')->assertForbidden();
    }

    public function test_admin_manages_bin_types(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post('/lists/bin-types', ['name' => 'Skip bin'])->assertSessionHasNoErrors();
        $this->post('/lists/bin-types', ['name' => 'Skip bin'])->assertSessionHasErrors('name');
        $type = BinType::sole();
        $this->put("/lists/bin-types/{$type->id}", ['name' => 'Roll-on bin', 'is_active' => false])->assertSessionHasNoErrors();

        $this->assertSame('Roll-on bin', $type->fresh()->name);
        $this->get('/categories')->assertInertia(fn (Assert $page) => $page->has('binTypes', 1)->missing('facilityTypes'));
        $this->post('/lists/facility-types', ['name' => 'x'])->assertNotFound();
    }

    public function test_proof_of_payment_opens_in_the_browser_or_downloads(): void
    {
        Storage::fake('local');
        $occupancy = $this->occupancy();
        $payment = app(BillingService::class)->recordManualPayment($occupancy, ['amount' => 5000, 'method' => 'bank_transfer', 'paid_on' => '2026-03-02'], $occupancy->user);
        Storage::disk('local')->put('payment-proof/1/proof.pdf', '%PDF-1.4 demo');
        $attachment = PaymentAttachment::create(['payment_id' => $payment->id, 'path' => 'payment-proof/1/proof.pdf', 'original_name' => 'proof.pdf', 'mime' => 'application/pdf', 'size' => 13]);

        $this->actingAs($this->staff('accountant'));

        $view = $this->get("/payment-attachments/{$attachment->id}")->assertOk();
        $this->assertStringStartsWith('inline', $view->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $view->headers->get('Content-Security-Policy'));

        $download = $this->get("/payment-attachments/{$attachment->id}?download=1")->assertOk();
        $this->assertStringStartsWith('attachment', $download->headers->get('Content-Disposition'));
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    protected function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'clients.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
