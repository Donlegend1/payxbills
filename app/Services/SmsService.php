<?php

namespace App\Services;

use App\Jobs\SendSms;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sends SMS through Africa's Talking and writes one SmsLog row per recipient.
 *
 * The driver comes from services.sms.driver:
 *  - "log": nothing leaves the server, the SMS is written to the app log (default).
 *  - "africastalking": sent through the Africa's Talking messaging API.
 *
 * Sending never throws: a failure is recorded on the SmsLog (status "failed" plus the error),
 * so callers check $log->failed() when they care.
 *
 * Usage:
 *   $sms->send('08012345678', 'Hello');                          // now, returns SmsLog
 *   $sms->sendMany(['0801...', '0802...'], 'Hello', $invoice);   // now, one API call, returns Collection<SmsLog>
 *   $sms->queue('08012345678', 'Hello');                         // on the queue, returns Collection<SmsLog>
 *   $sms->deliver($failedLog);                                   // retry
 */
class SmsService
{
    /** Africa's Talking per-recipient codes that mean the message was accepted. */
    protected const ACCEPTED_CODES = [100, 101, 102];

    /** Recipients per API call. */
    protected const CHUNK = 100;

    public function send(string $to, string $message, ?Model $related = null, ?string $from = null): SmsLog
    {
        return $this->sendMany([$to], $message, $related, $from)->first();
    }

    /**
     * @param  iterable<string>  $to
     * @return Collection<int, SmsLog>
     */
    public function sendMany(iterable $to, string $message, ?Model $related = null, ?string $from = null): Collection
    {
        $logs = $this->createLogs($to, $message, $related, $from);
        $this->deliver($logs);

        return $logs->each->refresh();
    }

    /**
     * Log the SMS as queued now and send it from the queue worker.
     *
     * @param  string|iterable<string>  $to
     * @return Collection<int, SmsLog>
     */
    public function queue(string|iterable $to, string $message, ?Model $related = null, ?string $from = null): Collection
    {
        $logs = $this->createLogs(is_string($to) ? [$to] : $to, $message, $related, $from);

        if ($logs->isNotEmpty()) {
            SendSms::dispatch($logs->pluck('id')->all());
        }

        return $logs;
    }

    /**
     * Send (or resend) logged SMS. Messages sharing the same text and sender go in one API call.
     *
     * @param  SmsLog|Collection<int, SmsLog>  $logs
     */
    public function deliver(SmsLog|Collection $logs): void
    {
        $logs = $logs instanceof SmsLog ? collect([$logs]) : $logs;

        $logs->reject(fn (SmsLog $log) => in_array($log->status, ['sent', 'delivered'], true))
            ->groupBy(fn (SmsLog $log) => $log->sender_id."\0".$log->message)
            ->each(fn (Collection $group) => $group->chunk(self::CHUNK)->each(fn (Collection $chunk) => $this->deliverBatch($chunk->values())));
    }

    /**
     * Apply an Africa's Talking delivery report (POSTed to the callback URL set on their dashboard).
     *
     * @param  array<string, mixed>  $report
     */
    public function handleDeliveryReport(array $report): ?SmsLog
    {
        $log = isset($report['id']) ? SmsLog::where('provider_message_id', $report['id'])->first() : null;

        if (! $log) {
            return null;
        }

        $status = (string) ($report['status'] ?? '');

        $log->update(match ($status) {
            'Success' => ['status' => 'delivered', 'provider_status' => $status, 'delivered_at' => now(), 'error' => null],
            'Failed', 'Rejected' => ['status' => 'failed', 'provider_status' => $status, 'error' => $report['failureReason'] ?? $status],
            default => ['provider_status' => $status], // Sent, Submitted, Buffered: still in flight
        });

        return $log;
    }

    /**
     * Put a phone number in the international format Africa's Talking expects: 08012345678 -> +2348012345678.
     */
    public function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $code = (string) config('services.sms.country_code', '234');

        return match (true) {
            str_starts_with(trim($phone), '+') => '+'.$digits,
            str_starts_with($digits, '00') => '+'.substr($digits, 2),
            str_starts_with($digits, $code) => '+'.$digits,
            str_starts_with($digits, '0') => '+'.$code.substr($digits, 1),
            default => '+'.$code.$digits,
        };
    }

    /**
     * @param  iterable<string>  $to
     * @return Collection<int, SmsLog>
     */
    protected function createLogs(iterable $to, string $message, ?Model $related, ?string $from): Collection
    {
        $from ??= config('services.africastalking.from') ?: Setting::get('sms_sender') ?: null;

        return collect($to)
            ->filter(fn ($phone) => filled($phone))
            ->map(fn (string $phone) => $this->normalize($phone))
            ->unique()
            ->map(fn (string $phone) => SmsLog::create([
                'company_id' => $related?->company_id ?? CurrentCompany::id(),
                'recipient' => $phone,
                'sender_id' => $from,
                'message' => $message,
                'driver' => $this->driver(),
                'status' => 'queued',
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
                'user_id' => Auth::id(),
            ]))
            ->values();
    }

    /**
     * @param  Collection<int, SmsLog>  $logs  same message and sender
     */
    protected function deliverBatch(Collection $logs): void
    {
        $driver = $this->driver();

        foreach ($logs as $log) {
            $log->increment('attempts');
        }

        try {
            $results = match ($driver) {
                'log' => $this->sendViaLog($logs),
                'africastalking' => $this->sendViaAfricasTalking($logs),
                default => throw new RuntimeException("SMS driver [{$driver}] is not supported."),
            };
        } catch (Throwable $e) {
            report($e);
            $error = Str::limit($e->getMessage(), 500);
            $logs->each(fn (SmsLog $log) => $log->update(['driver' => $driver, 'status' => 'failed', 'error' => $error]));

            return;
        }

        foreach ($logs as $log) {
            $result = $results[$log->recipient] ?? null;
            $accepted = $result && in_array($result['statusCode'] ?? null, self::ACCEPTED_CODES, true);

            $log->update([
                'driver' => $driver,
                'status' => $accepted ? 'sent' : 'failed',
                'provider_message_id' => $result['messageId'] ?? null,
                'provider_status' => $result['status'] ?? null,
                'provider_status_code' => $result['statusCode'] ?? null,
                'cost' => $result['cost'] ?? null,
                'error' => $accepted ? null : ($result['status'] ?? 'No result returned for this number.'),
                'sent_at' => $accepted ? now() : null,
            ]);
        }
    }

    /**
     * @param  Collection<int, SmsLog>  $logs
     * @return array<string, array<string, mixed>> results keyed by phone number
     */
    protected function sendViaLog(Collection $logs): array
    {
        $first = $logs->first();
        Log::info('SMS from '.($first->sender_id ?? '(default)').' to '.$logs->pluck('recipient')->join(', ').': '.$first->message);

        return $logs->mapWithKeys(fn (SmsLog $log) => [
            $log->recipient => ['statusCode' => 101, 'status' => 'Logged', 'messageId' => null, 'cost' => null],
        ])->all();
    }

    /**
     * @param  Collection<int, SmsLog>  $logs
     * @return array<string, array<string, mixed>> results keyed by phone number
     */
    protected function sendViaAfricasTalking(Collection $logs): array
    {
        $config = config('services.africastalking');

        if (blank($config['username']) || blank($config['api_key'])) {
            throw new RuntimeException("Africa's Talking is not configured: set AFRICASTALKING_USERNAME and AFRICASTALKING_API_KEY.");
        }

        $sandbox = $config['username'] === 'sandbox';
        $url = $sandbox
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';

        $first = $logs->first();

        $response = Http::asForm()
            ->acceptJson()
            ->withHeaders(['apiKey' => $config['api_key']])
            ->timeout(30)
            ->post($url, array_filter([
                'username' => $config['username'],
                'to' => $logs->pluck('recipient')->join(','),
                'message' => $first->message,
                'from' => $first->sender_id,
            ], fn ($value) => filled($value)));

        if ($response->failed()) {
            throw new RuntimeException("Africa's Talking returned HTTP {$response->status()}: ".Str::limit(trim($response->body()), 300));
        }

        $recipients = $response->json('SMSMessageData.Recipients');

        if (! is_array($recipients)) {
            throw new RuntimeException("Africa's Talking: ".($response->json('SMSMessageData.Message') ?? 'unexpected response '.Str::limit($response->body(), 300)));
        }

        return collect($recipients)->keyBy('number')->all();
    }

    protected function driver(): string
    {
        return (string) config('services.sms.driver', 'log');
    }
}
