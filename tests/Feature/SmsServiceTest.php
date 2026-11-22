<?php

namespace Tests\Feature;

use App\Jobs\SendSms;
use App\Models\MessageLog;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Messenger;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function useAfricasTalking(): void
    {
        config([
            'services.sms.driver' => 'africastalking',
            'services.africastalking' => ['username' => 'cosmo', 'api_key' => 'secret', 'from' => null],
        ]);
    }

    protected function fakeResponse(array $recipients): void
    {
        Http::fake(['api.africastalking.com/*' => Http::response(['SMSMessageData' => ['Message' => 'Sent', 'Recipients' => $recipients]], 201)]);
    }

    public function test_numbers_are_normalized_to_international_format(): void
    {
        $sms = app(SmsService::class);

        $this->assertSame('+2348012345678', $sms->normalize('08012345678'));
        $this->assertSame('+2348012345678', $sms->normalize('2348012345678'));
        $this->assertSame('+2348012345678', $sms->normalize('+234 801 234 5678'));
        $this->assertSame('+254712345678', $sms->normalize('00254712345678'));
    }

    public function test_log_driver_records_the_sms_without_calling_the_api(): void
    {
        Http::fake();

        $log = app(SmsService::class)->send('08012345678', 'Hello');

        Http::assertNothingSent();
        $this->assertSame('sent', $log->status);
        $this->assertSame('log', $log->driver);
        $this->assertSame('+2348012345678', $log->recipient);
        $this->assertSame('PAYXBILLS', $log->sender_id, 'falls back to the sms_sender setting');
        $this->assertSame(1, $log->attempts);
    }

    public function test_africastalking_sends_one_request_and_logs_each_recipient(): void
    {
        $this->useAfricasTalking();
        $this->fakeResponse([
            ['statusCode' => 101, 'number' => '+2348011111111', 'status' => 'Success', 'cost' => 'NGN 2.20', 'messageId' => 'ATXid_1'],
            ['statusCode' => 403, 'number' => '+2348022222222', 'status' => 'InvalidPhoneNumber', 'cost' => '0', 'messageId' => 'None'],
        ]);

        $logs = app(SmsService::class)->sendMany(['08011111111', '08022222222'], 'Hello');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.africastalking.com/version1/messaging'
            && $r->hasHeader('apiKey', 'secret')
            && $r['username'] === 'cosmo'
            && $r['to'] === '+2348011111111,+2348022222222'
            && $r['from'] === 'PAYXBILLS');

        [$ok, $bad] = $logs->all();
        $this->assertSame('sent', $ok->status);
        $this->assertSame('ATXid_1', $ok->provider_message_id);
        $this->assertSame('NGN 2.20', $ok->cost);
        $this->assertNotNull($ok->sent_at);
        $this->assertSame('failed', $bad->status);
        $this->assertSame('InvalidPhoneNumber', $bad->error);
        $this->assertSame(403, $bad->provider_status_code);
    }

    public function test_http_errors_are_logged_as_failures_and_can_be_retried(): void
    {
        $this->useAfricasTalking();
        Http::fakeSequence('api.africastalking.com/*')
            ->push('The supplied authentication is invalid', 401)
            ->push(['SMSMessageData' => ['Message' => 'Sent', 'Recipients' => [
                ['statusCode' => 101, 'number' => '+2348011111111', 'status' => 'Success', 'cost' => 'NGN 2.20', 'messageId' => 'ATXid_9'],
            ]]], 201);

        $sms = app(SmsService::class);
        $log = $sms->send('08011111111', 'Hello');

        $this->assertTrue($log->failed());
        $this->assertStringContainsString('HTTP 401', $log->error);

        $sms->deliver($log);
        $log->refresh();

        $this->assertSame('sent', $log->status);
        $this->assertSame(2, $log->attempts);
        $this->assertNull($log->error);
        $this->assertSame(1, SmsLog::count(), 'retry reuses the same row');
    }

    public function test_sandbox_username_uses_the_sandbox_api(): void
    {
        $this->useAfricasTalking();
        config(['services.africastalking.username' => 'sandbox']);
        Http::fake(['api.sandbox.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => [
            ['statusCode' => 101, 'number' => '+2348011111111', 'status' => 'Success', 'cost' => 'NGN 0', 'messageId' => 'ATXid_s'],
        ]]], 201)]);

        $this->assertSame('sent', app(SmsService::class)->send('08011111111', 'Hi')->status);
    }

    public function test_queue_logs_now_and_sends_from_the_job(): void
    {
        Queue::fake();

        $logs = app(SmsService::class)->queue(['08011111111', '08011111111', ''], 'Hello');

        $this->assertCount(1, $logs, 'duplicates and blanks dropped');
        $this->assertSame('queued', $logs->first()->status);
        Queue::assertPushed(SendSms::class, fn (SendSms $job) => $job->smsLogIds === [$logs->first()->id]);

        (new SendSms([$logs->first()->id]))->handle(app(SmsService::class));
        $this->assertSame('sent', $logs->first()->refresh()->status);
    }

    public function test_delivery_report_updates_the_log(): void
    {
        $this->useAfricasTalking();
        $this->fakeResponse([['statusCode' => 101, 'number' => '+2348011111111', 'status' => 'Success', 'cost' => 'NGN 2.20', 'messageId' => 'ATXid_7']]);
        $log = app(SmsService::class)->send('08011111111', 'Hello');

        $this->post('/webhooks/africastalking/sms-delivery', ['id' => 'ATXid_7', 'status' => 'Success', 'phoneNumber' => '+2348011111111'])->assertOk();

        $log->refresh();
        $this->assertSame('delivered', $log->status);
        $this->assertNotNull($log->delivered_at);
    }

    public function test_messenger_sms_goes_through_the_service_and_is_linked_to_the_message_log(): void
    {
        $this->useAfricasTalking();
        $this->fakeResponse([['statusCode' => 102, 'number' => '+2348000000000', 'status' => 'Success', 'cost' => 'NGN 2.20', 'messageId' => 'ATXid_m']]);
        $user = User::factory()->create(['role' => 'facility_user', 'phone' => '08000000000']);

        $message = MessageLog::create(['user_id' => $user->id, 'channel' => 'sms', 'type' => 'reminder', 'recipient' => $user->phone, 'message' => 'Pay up']);
        app(Messenger::class)->deliver($message);

        $this->assertSame('sent', $message->refresh()->status);
        $sms = SmsLog::sole();
        $this->assertTrue($sms->related->is($message));
    }
}
