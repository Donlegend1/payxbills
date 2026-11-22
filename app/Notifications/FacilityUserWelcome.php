<?php

namespace App\Notifications;

use App\Models\Occupancy;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Login details for a new facility user (also used by "Resend login details").
 *
 * Deliberately not queued: the password would otherwise be stored in the jobs table.
 * Sent through the mailer in services.messaging.mailer, like every other facility-user message.
 */
class FacilityUserWelcome extends Notification
{
    public function __construct(public string $password, public ?Occupancy $occupancy = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function subject(): string
    {
        return 'Your '.Setting::get('company_name').' account';
    }

    public function toMail(User $notifiable): MailMessage
    {
        $company = Setting::get('company_name');

        $mail = (new MailMessage)
            ->mailer(config('services.messaging.mailer'))
            ->subject(self::subject())
            ->greeting("Dear {$notifiable->name},")
            ->line("An account has been created for you on the {$company} payment portal. You can use it to see your dues, pay online and download receipts.");

        if ($o = $this->occupancy) {
            $mail->line("**Facility:** {$o->facility->name}  ")
                ->line("**Space:** {$o->space->name()}  ")
                ->line("**Category:** {$o->category->name} (".Money::format($o->category->monthly_amount).' per month)');
        }

        return $mail
            ->line("**Email:** {$notifiable->email}  ")
            ->line("**Temporary password:** `{$this->password}`")
            ->action('Log in', route('login'))
            ->line('You will be asked to choose your own password when you first log in.')
            ->salutation("Thank you,  \n{$company}");
    }
}
