<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Management = 'management';
    case Accountant = 'accountant';
    case Supervisor = 'supervisor';
    case FacilityUser = 'facility_user';

    /**
     * Every ability the application checks, with the roles that hold it.
     * Gates are registered from this list in AppServiceProvider.
     */
    public const ABILITIES = [
        'view-dashboard' => ['admin', 'management', 'accountant'],
        'view-facilities' => ['admin', 'management', 'accountant', 'supervisor'],
        'manage-facilities' => ['admin'],
        'manage-categories' => ['admin'],
        'view-facility-users' => ['admin', 'management', 'accountant', 'supervisor'],
        'register-facility-users' => ['admin', 'supervisor'],
        'request-removals' => ['admin', 'supervisor'],
        'approve-removals' => ['admin'],
        'view-invoices' => ['admin', 'management', 'accountant'],
        'manage-invoices' => ['admin', 'accountant'],
        'send-reminders' => ['admin', 'management', 'accountant', 'supervisor'],
        'view-payments' => ['admin', 'management', 'accountant', 'supervisor'],
        'record-payments' => ['admin', 'accountant', 'supervisor'],
        // Manual payments only count once an accountant clears them with a reference and proof
        'clear-payments' => ['accountant'],
        'view-receivables' => ['admin', 'management', 'accountant'],
        'view-expenditure' => ['admin', 'management', 'accountant', 'supervisor'],
        'create-memos' => ['admin', 'management', 'accountant', 'supervisor'],
        'approve-memos' => ['admin', 'management'],
        'view-reports' => ['admin', 'management', 'accountant'],
        'view-analytics' => ['admin', 'management', 'accountant'],
        'view-notifications' => ['admin', 'management', 'accountant'],
        'view-sms-logs' => ['admin'],
        'manage-settings' => ['admin'],
        // Staff accounts: list, add, edit and log in as them
        'manage-users' => ['admin'],
        // The facility user's own portal: dues, payments, receipts
        'use-portal' => ['facility_user'],
    ];

    /** Roles that work in the staff area (everyone except facility users). */
    public const STAFF = ['admin', 'management', 'accountant', 'supervisor'];

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Company admin',
            self::Management => 'Management',
            self::Accountant => 'Accountant',
            self::Supervisor => 'Supervisor',
            self::FacilityUser => 'Facility user',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full access to the company: facilities, categories, settings, staff accounts; approves removals',
            self::Management => 'Dashboard, reports and analytics; approves expense memos',
            self::Accountant => 'Invoices and payments; clears manual payments',
            self::Supervisor => 'Registers facility users in their facilities, records payments, requests removals',
            self::FacilityUser => 'Sees their own dues, pays online and downloads receipts',
        };
    }

    public function isStaff(): bool
    {
        return $this !== self::FacilityUser;
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return array_keys(array_filter(self::ABILITIES, fn (array $roles) => in_array($this->value, $roles, true)));
    }

    public function can(string $ability): bool
    {
        return in_array($this->value, self::ABILITIES[$ability] ?? [], true);
    }

    /**
     * Staff roles, for the Settings page.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_values(array_map(
            fn (self $r) => ['value' => $r->value, 'label' => $r->label(), 'description' => $r->description()],
            array_filter(self::cases(), fn (self $r) => $r->isStaff()),
        ));
    }
}
