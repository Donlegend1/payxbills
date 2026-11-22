<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin view of every SMS the app has sent (sms_logs), with search and retry.
 */
class SmsLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:queued,sent,delivered,failed'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = SmsLog::query()
            ->with(['user:id,name', 'related' => fn (MorphTo $morph) => $morph->morphWith([MessageLog::class => ['user:id,name']])])
            ->when($filters['q'] ?? null, fn (Builder $q, string $search) => $this->search($q, $search))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('created_at', '<=', $to))
            ->latest()
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (SmsLog $log) => [
                'id' => $log->id,
                'time' => $log->created_at->format('j M Y H:i'),
                'recipient' => $log->recipient,
                'sender' => $log->sender_id,
                'message' => $log->message,
                'about' => $this->describe($log),
                'sent_by' => $log->user?->name,
                'driver' => $log->driver === 'africastalking' ? "Africa's Talking" : ucfirst($log->driver),
                'status' => ucfirst($log->status),
                'provider_status' => $log->provider_status,
                'provider_message_id' => $log->provider_message_id,
                'cost' => $log->cost,
                'attempts' => $log->attempts,
                'error' => $log->error,
            ]);

        $counts = SmsLog::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('App/SmsLogs', [
            'logs' => $logs,
            'filters' => $filters,
            'counts' => collect(['queued', 'sent', 'delivered', 'failed'])->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
        ]);
    }

    public function retry(SmsLog $smsLog, SmsService $sms): RedirectResponse
    {
        if (in_array($smsLog->status, ['sent', 'delivered'], true)) {
            return back()->with('error', 'This SMS was already sent.');
        }

        $sms->deliver($smsLog);
        $smsLog->refresh();

        return $smsLog->failed()
            ? back()->with('error', 'Still failing: '.$smsLog->error)
            : back()->with('success', 'SMS sent.');
    }

    /**
     * Match the phone number, text, sender ID or provider message ID. "0801 234" finds +234801234...
     */
    protected function search(Builder $query, string $search): void
    {
        $search = trim($search);
        $digits = preg_replace('/\D+/', '', $search);

        $query->where(function (Builder $w) use ($search, $digits) {
            $w->where('recipient', 'like', "%{$search}%")
                ->orWhere('message', 'like', "%{$search}%")
                ->orWhere('sender_id', 'like', "%{$search}%")
                ->orWhere('provider_message_id', 'like', "%{$search}%");

            if (strlen($digits) >= 4) {
                $w->orWhere('recipient', 'like', '%'.ltrim($digits, '0').'%');
            }
        });
    }

    /**
     * A short description of what the SMS was about, e.g. "Reminder · Ada Okafor".
     */
    protected function describe(SmsLog $log): ?string
    {
        return match (true) {
            $log->related instanceof MessageLog => ucfirst($log->related->type).($log->related->user ? ' · '.$log->related->user->name : ''),
            $log->related instanceof Invoice => 'Invoice '.$log->related->number,
            $log->related !== null => class_basename($log->related).' #'.$log->related->getKey(),
            default => null,
        };
    }
}
