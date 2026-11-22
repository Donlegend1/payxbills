<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\FacilityUserWelcome;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Emails and texts to facility users: login details, payment due, reminders and receipts.
 * Every message is logged in message_logs (SMS also in sms_logs through SmsService).
 *
 * Email goes through the mailer in services.messaging.mailer ("log" by default, so nothing
 * leaves the server until it is configured).
 */
class Messenger
{
    public function __construct(protected SmsService $sms) {}

    /**
     * Login details for a new facility user. The password is emailed but never written to the log.
     */
    public function welcome(User $user, string $password, ?Occupancy $occupancy = null): MessageLog
    {
        $company = Setting::get('company_name');
        $place = $occupancy ? "\n\nAccount: {$occupancy->code}\nPlace: {$occupancy->placeName()}\nMonthly amount: ".Money::format($occupancy->monthlyAmount()) : '';
        $body = "Dear {$user->name},\n\nAn account has been created for you on the {$company} payment portal.{$place}\n\nLog in here: ".route('login')."\nEmail: {$user->email}\nPassword: %s\n\nYou will be asked to choose your own password when you first log in.\n\n{$company}";
        $subject = FacilityUserWelcome::subject();

        $log = MessageLog::create([
            'user_id' => $user->id,
            'channel' => 'email',
            'type' => 'welcome',
            'recipient' => $user->email,
            'subject' => $subject,
            'message' => sprintf($body, '[hidden]'),
            'status' => 'queued',
            'attempts' => 1,
        ]);

        try {
            $user->notify(new FacilityUserWelcome($password, $occupancy));
            $log->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);
        }

        return $log;
    }

    /**
     * Sent when billing starts: what they will be billed and the pay link. No password: facility users
     * pay from the link and only log in if they want to, after setting a password with "Forgot password".
     */
    public function activated(Occupancy $occupancy): Collection
    {
        $occupancy->loadMissing(['user', 'facility', 'space', 'category']);
        $user = $occupancy->user;
        $company = Setting::get('company_name');
        $amount = Money::format($occupancy->monthlyAmount());
        $url = $occupancy->payUrl();
        $history = $user->email
            ? "\n\nTo see all your payments and receipts online, go to ".route('password.request')." and enter {$user->email} to set a password."
            : '';

        return $this->send(
            $user,
            'activation',
            "Welcome to {$company}",
            "Dear {$user->name},\n\nYou have been added to {$company}'s billing (account {$occupancy->code}, {$occupancy->placeName()}). Your monthly amount is {$amount}.\n\nEach month you will receive your bill with a link to pay online. You can also pay any time here:\n{$url}{$history}\n\nThank you,\n{$company}",
            "{$company}: welcome! Account {$occupancy->code}, {$amount}/month. You will get your bill and pay link monthly. Pay any time: {$url}",
        );
    }

    /**
     * "Payment due" notice with the pay link, sent when a month's invoice is issued.
     */
    public function invoiceIssued(Invoice $invoice): Collection
    {
        $occupancy = $invoice->occupancy->loadMissing(['user', 'facility', 'space']);
        $user = $occupancy->user;
        $company = Setting::get('company_name');
        $amount = Money::format($invoice->balance());
        $due = $invoice->due_date->format('j M Y');
        $month = $invoice->periodLabel();
        $url = $occupancy->payUrl();

        $logs = $this->send(
            $user,
            'invoice',
            "{$month} payment due – {$company}",
            "Dear {$user->name},\n\nYour {$month} payment of {$amount} for {$occupancy->placeName()} is due on {$due} (invoice {$invoice->number}).\n\nPay online here – you can pay for as many months as you like:\n{$url}\n\nThank you,\n{$company}",
            "{$company}: {$month} payment of {$amount} for {$occupancy->placeName()} is due {$due}. Pay: {$url}",
            invoice: $invoice,
        );

        $invoice->update(['sent_at' => now()]);

        return $logs;
    }

    public function reminder(Invoice $invoice): Collection
    {
        $occupancy = $invoice->occupancy->loadMissing(['user', 'facility', 'space']);
        $user = $occupancy->user;
        $company = Setting::get('company_name');
        $balance = Money::format($invoice->balance());
        $days = $invoice->daysOverdue();
        $month = $invoice->periodLabel();
        $url = $occupancy->payUrl();
        $late = $days > 0 ? "is {$days} days overdue" : 'is due today';

        return $this->send(
            $user,
            'reminder',
            "Reminder: {$month} payment {$late}",
            "Dear {$user->name},\n\nYour {$month} payment of {$balance} for {$occupancy->placeName()} {$late}.\n\nPay here:\n{$url}\n\nIf you have already paid, please ignore this message.\n\n{$company}",
            "{$company}: {$month} payment of {$balance} for {$occupancy->placeName()} {$late}. Pay: {$url}",
            invoice: $invoice,
        );
    }

    /**
     * One reminder covering everything a facility user owes, sent by staff from the Facility users page.
     */
    public function occupancyReminder(Occupancy $occupancy): Collection
    {
        $open = $occupancy->invoices()->open()->orderBy('period')->get();

        if ($open->isEmpty()) {
            return collect();
        }

        $occupancy->loadMissing(['user', 'facility', 'space']);
        $user = $occupancy->user;
        $company = Setting::get('company_name');
        $total = Money::format($open->sum(fn (Invoice $i) => $i->balance()));
        $months = $open->map(fn (Invoice $i) => $i->periodLabel())->join(', ', ' and ');
        $url = $occupancy->payUrl();

        return $this->send(
            $user,
            'reminder',
            "Payment reminder from {$company}",
            "Dear {$user->name},\n\nYou have {$total} outstanding for {$occupancy->placeName()} ({$months}).\n\nPay here:\n{$url}\n\nIf you have already paid, please ignore this message.\n\n{$company}",
            "{$company}: you have {$total} outstanding for {$occupancy->placeName()}. Pay: {$url}",
            invoice: $open->first(),
        );
    }

    /**
     * Receipt with the facility details and the months the payment covered.
     */
    public function receipt(Payment $payment): Collection
    {
        $payment->loadMissing(['occupancy.user', 'occupancy.facility', 'occupancy.space', 'occupancy.category', 'allocations.invoice']);
        $occupancy = $payment->occupancy;
        $user = $occupancy->user;
        $facility = $occupancy->facility;
        $company = Setting::get('company_name');
        $amount = Money::format($payment->amount);
        $months = $payment->allocations->map(fn ($a) => $a->invoice->periodLabel())->unique()->join(', ', ' and ') ?: 'credit on account';
        $url = $payment->receiptUrl();
        $facilityLines = collect([$facility?->name, $facility?->address, $facility?->phone ? 'Tel: '.$facility->phone : null, $facility?->email])->filter()->join("\n");

        return $this->send(
            $user,
            'receipt',
            "Receipt {$payment->receipt_number} for {$amount}",
            "Dear {$user->name},\n\nWe have received your payment of {$amount} on {$payment->paid_on->format('j M Y')}.\n\nReceipt number: {$payment->receipt_number}\nAccount: {$occupancy->code}\nPlace: {$occupancy->placeName()}\nCategory: {$occupancy->categoryName()}\nMonths covered: {$months}\n\n{$facilityLines}\n\nView or print your receipt:\n{$url}\n\nThank you,\n{$company}",
            "{$company}: payment of {$amount} received for {$occupancy->placeName()} ({$months}). Receipt {$payment->receipt_number}: {$url}",
            payment: $payment,
        );
    }

    /**
     * Send a queued message, or try a failed one again.
     */
    public function deliver(MessageLog $log): void
    {
        $log->increment('attempts');

        try {
            if ($log->type === 'welcome') {
                throw new RuntimeException('Login details cannot be resent from the log. Use "Resend login details" on the Facility users page.');
            }

            if ($log->channel === 'email') {
                Mail::mailer(config('services.messaging.mailer'))->raw(
                    $log->message,
                    fn ($m) => $m->to($log->recipient)->subject($log->subject),
                );
            } else {
                $this->sendSms($log);
            }

            $log->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);
        }
    }

    protected function send(User $user, string $type, string $subject, string $email, string $sms, ?Invoice $invoice = null, ?Payment $payment = null): Collection
    {
        $base = ['user_id' => $user->id, 'invoice_id' => $invoice?->id, 'payment_id' => $payment?->id, 'type' => $type, 'status' => 'queued'];
        $logs = collect();

        if ($user->email) {
            $logs->push(MessageLog::create([...$base, 'channel' => 'email', 'recipient' => $user->email, 'subject' => $subject, 'message' => $email]));
        }

        if ($user->phone) {
            $logs->push(MessageLog::create([...$base, 'channel' => 'sms', 'recipient' => $user->phone, 'message' => $sms]));
        }

        $logs->each(fn (MessageLog $log) => $this->deliver($log));

        return $logs;
    }

    protected function sendSms(MessageLog $log): void
    {
        $sms = $this->sms->send($log->recipient, $log->message, related: $log);

        if ($sms->failed()) {
            throw new RuntimeException($sms->error ?? 'SMS failed.');
        }
    }
}
