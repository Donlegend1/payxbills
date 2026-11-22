<?php

namespace Tests\Feature;

use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesFacilityUsers;
use Tests\TestCase;

/**
 * Expense memos: several categories, a picture of the memo, and approval, partial approval,
 * KIV or rejection. Only approved amounts count as spend.
 */
class MemoTest extends TestCase
{
    use CreatesFacilityUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 09:00');
        Storage::fake('local');
    }

    public function test_a_memo_has_one_category_by_default_and_can_hold_several_with_a_picture(): void
    {
        $this->actingAs($this->staff('supervisor'))->post('/expenditure', [
            'spent_on' => '2026-03-05', 'description' => 'Uniforms and transport',
            'lines' => [['category' => 'Uniforms', 'amount' => 48000], ['category' => 'Fuel and transport', 'amount' => 12000]],
            'memo' => UploadedFile::fake()->image('memo.jpg'),
        ])->assertSessionHasNoErrors();

        $memo = Expense::sole();
        $this->assertSame('MEM-0001', $memo->memo_number);
        $this->assertEquals(60000, $memo->amount, 'requested amount is the total');
        $this->assertSame('Multiple', $memo->category);
        $this->assertCount(2, $memo->lines);
        $this->assertSame('pending', $memo->status);
        Storage::disk('local')->assertExists($memo->memo_path);

        $view = $this->get("/expenditure/{$memo->id}/memo")->assertOk();
        $this->assertStringStartsWith('inline', $view->headers->get('Content-Disposition'));

        $this->post('/expenditure', ['spent_on' => '2026-03-05', 'description' => 'Twice', 'lines' => [['category' => 'Staff', 'amount' => 1], ['category' => 'Staff', 'amount' => 2]]])
            ->assertSessionHasErrors('lines.1.category');
    }

    public function test_partial_approval_records_only_the_approved_categories(): void
    {
        $memo = $this->memo([['Uniforms', 48000], ['Supplies', 12000]]);
        [$uniforms, $supplies] = $memo->lines;

        $this->actingAs($this->staff('management'))->post("/expenditure/{$memo->id}/review", [
            'decision' => 'partially_approved',
            'lines' => [['id' => $uniforms->id, 'approved_amount' => 40000], ['id' => $supplies->id, 'approved_amount' => null]],
        ])->assertSessionHasNoErrors();

        $memo->refresh();
        $this->assertSame('partially_approved', $memo->status);
        $this->assertEquals(60000, $memo->amount);
        $this->assertEquals(40000, $memo->approved_amount);
        $this->assertNull($supplies->fresh()->approved_amount);

        $this->get('/expenditure')->assertInertia(fn (Assert $page) => $page
            ->where('byCategory', [['Uniforms', 40000]])
            ->where('kpis.approved', 40000)
            ->where('memos.data.0.status', 'Partially approved'));
    }

    public function test_approving_more_than_requested_or_nothing_is_refused(): void
    {
        $memo = $this->memo([['Supplies', 10000]]);
        $line = $memo->lines->first();
        $this->actingAs($this->staff('management'));

        $this->post("/expenditure/{$memo->id}/review", ['decision' => 'partially_approved', 'lines' => [['id' => $line->id, 'approved_amount' => 15000]]])->assertSessionHasErrors('lines');
        $this->post("/expenditure/{$memo->id}/review", ['decision' => 'partially_approved', 'lines' => [['id' => $line->id, 'approved_amount' => null]]])->assertSessionHasErrors('lines');
        $this->assertSame('pending', $memo->fresh()->status);

        // A single-category memo approved for less is a partial approval
        $this->post("/expenditure/{$memo->id}/review", ['decision' => 'partially_approved', 'lines' => [['id' => $line->id, 'approved_amount' => 8000]]]);
        $this->assertSame('partially_approved', $memo->fresh()->status);
        $this->assertEquals(8000, $memo->fresh()->approved_amount);
    }

    public function test_kiv_needs_a_reason_and_stays_open_for_a_later_decision(): void
    {
        $memo = $this->memo([['Equipment', 96000]]);
        $this->actingAs($this->staff('management'));

        $this->post("/expenditure/{$memo->id}/review", ['decision' => 'kiv'])->assertSessionHasErrors('note');
        $this->post("/expenditure/{$memo->id}/review", ['decision' => 'kiv', 'note' => 'Get a second quote'])->assertSessionHasNoErrors();

        $memo->refresh();
        $this->assertSame('kiv', $memo->status);
        $this->assertSame('Get a second quote', $memo->review_note);
        $this->assertNull($memo->approved_amount, 'KIV is not spend');

        $this->post("/expenditure/{$memo->id}/review", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $memo->fresh()->status);
        $this->assertEquals(96000, $memo->fresh()->approved_amount);

        $this->post("/expenditure/{$memo->id}/review", ['decision' => 'rejected', 'note' => 'Too late'])->assertSessionHas('error', 'This memo has already been decided.');
    }

    public function test_rejected_memos_record_nothing_and_only_managers_decide(): void
    {
        $memo = $this->memo([['Staff', 60000]]);

        $this->actingAs($this->staff('accountant'))->post("/expenditure/{$memo->id}/review", ['decision' => 'approved'])->assertForbidden();

        $this->actingAs($this->staff('management'))->post("/expenditure/{$memo->id}/review", ['decision' => 'rejected', 'note' => 'Not budgeted'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $memo->fresh()->status);
        $this->get('/expenditure')->assertInertia(fn (Assert $page) => $page->where('kpis.approved', 0)->where('byCategory', []));
    }

    /**
     * @param  list<array{0: string, 1: float}>  $lines
     */
    protected function memo(array $lines): Expense
    {
        $memo = Expense::create([
            'spent_on' => '2026-03-05', 'description' => 'Test memo', 'category' => count($lines) > 1 ? 'Multiple' : $lines[0][0],
            'amount' => array_sum(array_column($lines, 1)), 'status' => 'pending', 'requested_by' => $this->staff('supervisor')->id,
        ]);

        foreach ($lines as [$category, $amount]) {
            $memo->lines()->create(['category' => $category, 'amount' => $amount]);
        }

        return $memo->load('lines');
    }
}
