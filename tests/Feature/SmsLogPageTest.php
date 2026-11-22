<?php

namespace Tests\Feature;

use App\Models\MessageLog;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SmsLogPageTest extends TestCase
{
    use RefreshDatabase;

    protected function log(array $attributes = []): SmsLog
    {
        return SmsLog::create([
            'recipient' => '+2348011111111',
            'sender_id' => 'COSMOCLEAN',
            'message' => 'Hello',
            'driver' => 'africastalking',
            'status' => 'sent',
            ...$attributes,
        ]);
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_only_admins_can_see_the_sms_log(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'accountant']))->get('/sms-logs')->assertForbidden();
        $this->actingAs($this->admin())->get('/sms-logs')->assertOk();
    }

    public function test_search_matches_local_numbers_message_text_and_shows_what_it_was_about(): void
    {
        $client = User::factory()->create(['name' => 'ABC Estate', 'role' => 'facility_user', 'phone' => '08011111111']);
        $message = MessageLog::create(['user_id' => $client->id, 'channel' => 'sms', 'type' => 'reminder', 'recipient' => '08011111111', 'message' => 'Pay']);
        $this->log(['message' => 'Invoice INV-1 is overdue', 'related_type' => $message->getMorphClass(), 'related_id' => $message->id]);
        $this->log(['recipient' => '+2348099999999', 'message' => 'Receipt for payment']);

        $admin = $this->admin();

        $this->actingAs($admin)->get('/sms-logs?q=0801 111')->assertInertia(fn (Assert $page) => $page
            ->component('App/SmsLogs')
            ->has('logs.data', 1)
            ->where('logs.data.0.about', 'Reminder · ABC Estate'));

        $this->actingAs($admin)->get('/sms-logs?q=receipt')->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.recipient', '+2348099999999'));
    }

    public function test_status_and_date_filters(): void
    {
        $this->log(['status' => 'failed', 'error' => 'InvalidPhoneNumber']);
        $this->log(['status' => 'delivered']);
        $this->log()->forceFill(['created_at' => now()->subDays(10)])->save();

        $admin = $this->admin();

        $this->actingAs($admin)->get('/sms-logs?status=failed')->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.error', 'InvalidPhoneNumber')
            ->where('counts.failed', 1)
            ->where('counts.sent', 1));

        $this->actingAs($admin)->get('/sms-logs?from='.now()->subDay()->toDateString())->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
    }

    public function test_admin_can_retry_a_failed_sms(): void
    {
        $log = $this->log(['status' => 'failed', 'driver' => 'log', 'error' => 'boom']);

        $this->actingAs($this->admin())->post("/sms-logs/{$log->id}/retry")->assertSessionHas('success');

        $this->assertSame('sent', $log->refresh()->status);
    }
}
