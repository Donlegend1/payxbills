<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends SMS that SmsService::queue() logged as queued.
 */
class SendSms implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $smsLogIds
     */
    public function __construct(public array $smsLogIds) {}

    public function handle(SmsService $sms): void
    {
        $sms->deliver(SmsLog::whereKey($this->smsLogIds)->where('status', 'queued')->get());
    }
}
