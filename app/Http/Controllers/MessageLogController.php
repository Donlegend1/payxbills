<?php

namespace App\Http\Controllers;

use App\Models\MessageLog;
use App\Services\Messenger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MessageLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'channel');

        $messages = MessageLog::query()
            ->with('user:id,name')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->latest()
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (MessageLog $m) => [
                'id' => $m->id,
                'time' => $m->created_at->format('j M H:i'),
                'client' => $m->user?->name ?? '—',
                'channel' => $m->channel === 'sms' ? 'SMS' : 'Email',
                'recipient' => $m->recipient,
                'type' => ucfirst($m->type),
                'message' => $m->subject ?? $m->message,
                'status' => ucfirst($m->status),
                'error' => $m->error,
            ]);

        $counts = MessageLog::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('App/Notifications', [
            'messages' => $messages,
            'filters' => $filters,
            'counts' => ['sent' => $counts['sent'] ?? 0, 'queued' => $counts['queued'] ?? 0, 'failed' => $counts['failed'] ?? 0],
        ]);
    }

    public function retry(MessageLog $message, Messenger $messenger): RedirectResponse
    {
        if ($message->status === 'sent') {
            return back()->with('error', 'This message was already sent.');
        }

        if ($message->type === 'welcome') {
            return back()->with('error', 'Login details cannot be resent from here. Use "Resend login" on the Facility users page.');
        }

        $messenger->deliver($message);

        return $message->status === 'sent'
            ? back()->with('success', 'Message sent.')
            : back()->with('error', 'Still failing: '.$message->error);
    }
}
