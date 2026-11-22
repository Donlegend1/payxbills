<?php

namespace Tests\Feature;

use App\Models\BinType;
use App\Models\ClientChangeRequest;
use App\Models\Category;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\CreatesFacilityUsers;
use Tests\TestCase;

class ApprovalSearchSupportTest extends TestCase
{
    use CreatesFacilityUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 09:00');
        Notification::fake();
    }

    public function test_supervisor_changes_wait_for_the_admin_and_apply_when_approved(): void
    {
        $supervisor = $this->staff('supervisor');
        $facility = $this->facility($supervisor);
        $occupancy = $this->occupancy($facility, $this->category(15000));
        $better = $this->category(25000);

        $this->actingAs($supervisor)->put("/facility-users/{$occupancy->id}", [
            'name' => $occupancy->user->name, 'facility_id' => $facility->id, 'space_id' => $occupancy->space_id,
            'category_id' => $better->id, 'phone' => '08077777777',
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'take effect once approved'));

        $occupancy->refresh();
        $this->assertNotSame($better->id, $occupancy->category_id, 'not changed yet');
        $request = ClientChangeRequest::sole();
        $this->assertEqualsCanonicalizing(['category_id' => $better->id, 'phone' => '08077777777'], $request->changes);

        // A second edit waits until the first is decided
        $this->put("/facility-users/{$occupancy->id}", ['name' => 'Another', 'facility_id' => $facility->id, 'space_id' => $occupancy->space_id])
            ->assertSessionHasErrors('space_id');
        $this->post("/changes/{$request->id}/approve")->assertForbidden();

        $admin = $this->staff('admin');
        $this->actingAs($admin)->get('/facility-users?status=changes')->assertInertia(fn (Assert $page) => $page
            ->where('pendingChanges', 1)
            ->has('occupancies.data', 1)
            ->where('occupancies.data.0.change.fields', [
                ['Phone', '+2348011111111', '08077777777'],
                ['Category', $occupancy->category->name.' (₦15,000)', $better->name.' (₦25,000)'],
            ]));

        $this->post("/changes/{$request->id}/approve")->assertSessionHasNoErrors();
        $occupancy->refresh();
        $this->assertSame($better->id, $occupancy->category_id);
        $this->assertSame('08077777777', $occupancy->user->phone);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_rejected_changes_change_nothing_and_admin_edits_apply_at_once(): void
    {
        $occupancy = $this->occupancy();
        $request = $occupancy->changeRequests()->create(['changes' => ['bins' => 9], 'status' => 'pending', 'requested_by' => $this->staff('supervisor')->id]);
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post("/changes/{$request->id}/reject", ['note' => 'Wrong count'])->assertSessionHasNoErrors();
        $this->assertNull($occupancy->fresh()->bins);
        $this->assertSame('rejected', $request->fresh()->status);

        $this->put("/facility-users/{$occupancy->id}", ['name' => 'Direct Edit', 'facility_id' => $occupancy->facility_id, 'space_id' => $occupancy->space_id, 'category_id' => $occupancy->category_id])
            ->assertSessionHas('success', 'Direct Edit updated.');
        $this->assertSame('Direct Edit', $occupancy->fresh()->user->name);
        $this->assertSame(1, ClientChangeRequest::count());
    }

    public function test_supervisor_spreadsheet_updates_also_wait_for_approval(): void
    {
        $supervisor = $this->staff('supervisor');
        $occupancy = $this->occupancy($this->facility($supervisor));

        $this->actingAs($supervisor)->post('/facility-users/import', ['file' => $this->xlsx([['Account code', 'Frequency'], [$occupancy->code, 4]])])
            ->assertSessionHas('success', fn ($m) => str_contains($m, "Changes to 1 wait for the admin's approval."));

        $this->assertNull($occupancy->fresh()->frequency);
        $this->assertEquals(['frequency' => 4], ClientChangeRequest::sole()->changes);
    }

    public function test_facility_users_are_found_by_any_category_detail(): void
    {
        $category = Category::create(['name' => 'School', 'monthly_amount' => 65000]);
        $school = $this->occupancy(category: $category);
        $school->update(['bin_type_id' => BinType::create(['name' => 'Mammoth bin'])->id, 'district' => 'Surulere', 'bins' => 7]);
        $other = $this->occupancy();
        $admin = $this->staff('admin');

        foreach (['school', 'Mammoth', 'Surulere', '7', '65000'] as $search) {
            $this->actingAs($admin)->get('/facility-users?q='.urlencode($search))->assertInertia(fn (Assert $page) => $page
                ->has('occupancies.data', 1, fn (Assert $row) => $row->where('id', $school->id)->etc()));
        }

        $this->get('/facility-users?q=Green+Valley')->assertInertia(fn (Assert $page) => $page->has('occupancies.data', 2), 'facility name');
        $this->get('/facility-users?bin='.$school->bin_type_id)->assertInertia(fn (Assert $page) => $page->has('occupancies.data', 1));
        $this->assertNotNull($other);
    }

    public function test_sample_sheet_has_an_empty_upload_sheet_and_a_separate_example(): void
    {
        $response = $this->actingAs($this->staff('supervisor'))->get('/facility-users/template')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent() ?: file_get_contents($response->getFile()->getPathname()));

        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = iterator_to_array((function () use ($sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }
            })(), false);
        }
        $reader->close();

        $this->assertSame(['Facility users', 'Example and help'], array_keys($sheets));
        $this->assertCount(1, $sheets['Facility users'], 'headings only: nothing to import by mistake');
        $this->assertSame('Full name', $sheets['Facility users'][0][1]);
    }

    public function test_supervisors_can_add_bin_types_and_accountants_cannot(): void
    {
        $this->actingAs($this->staff('supervisor'))->post('/lists/bin-types', ['name' => 'Skip bin'])->assertSessionHasNoErrors();
        $this->assertSame('Skip bin', BinType::sole()->name);

        $this->actingAs($this->staff('accountant'))->post('/lists/bin-types', ['name' => 'Other'])->assertForbidden();
    }

    public function test_support_page_and_whatsapp_numbers(): void
    {
        $this->get('/support')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Support')->where('platform.whatsapp', config('app.platform.whatsapp')));

        $admin = $this->staff('admin');
        $this->actingAs($admin)->put('/settings', [...Setting::values(), 'whatsapp_number' => '0803 123 4567'])->assertSessionHasNoErrors();
        $this->get('/support')->assertInertia(fn (Assert $page) => $page->where('currentCompany.whatsapp', '0803 123 4567'));

        // The pay page offers the company's WhatsApp to the facility user
        $occupancy = $this->occupancy();
        auth()->logout();
        $this->get($occupancy->payUrl())->assertInertia(fn (Assert $page) => $page->where('currentCompany.whatsapp', '0803 123 4567'));
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

        return new UploadedFile($path, 'users.xlsx', null, null, true);
    }
}
