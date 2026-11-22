<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use App\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value business rules edited on the Settings page, one set per company.
 * Read and written for the current company (see App\Tenancy\CurrentCompany).
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use BelongsToCompany;

    /**
     * Every setting with its default. The default's type decides how the stored value is read.
     * company_name is not stored here: it is the current company's name.
     */
    public const DEFAULTS = [
        'company_name' => 'PayXbills',
        'invoice_prefix' => 'INV',
        // Day of the month each month's dues are issued (1-28)
        'billing_day' => 1,
        'due_days' => 14,
        // Days after the due date to remind unpaid facility users; 0 = on the due date
        'reminder_days' => '0, 3, 7, 14',
        'grace_days' => 0,
        // Most months a facility user can pay for in one go
        'max_months' => 12,
        'sms_sender' => 'PAYXBILLS',
        'bank_details' => '',
        'nrs_enabled' => false,
        'auto_invoicing' => true,
    ];

    public static function get(string $key): mixed
    {
        return static::values()[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function values(): array
    {
        $company = CurrentCompany::get();
        $stored = $company
            ? Cache::rememberForever(static::cacheKey($company->id), fn () => static::query()->pluck('value', 'key')->all())
            : [];

        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $raw = $stored[$key] ?? null;
            $values[$key] = match (true) {
                $raw === null => $default,
                is_bool($default) => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                is_int($default) => (int) $raw,
                default => $raw,
            };
        }

        if ($company) {
            $values['company_name'] = $company->name;
        }

        return $values;
    }

    /**
     * Save settings for the current company. company_name renames the company.
     *
     * @param  array<string, mixed>  $values
     */
    public static function put(array $values): void
    {
        $company = CurrentCompany::get() ?? throw new \LogicException('Settings belong to a company. Set CurrentCompany first.');

        if (filled($values['company_name'] ?? null)) {
            $company->update(['name' => $values['company_name']]);
        }

        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            if ($key === 'company_name') {
                continue;
            }

            static::updateOrCreate(['key' => $key], ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value]);
        }

        Cache::forget(static::cacheKey($company->id));
    }

    /**
     * Reminder days as a sorted list of integers, e.g. "3, 7, 14" → [3, 7, 14].
     *
     * @return list<int>
     */
    public static function reminderDays(): array
    {
        // 0 is allowed: remind on the due date itself
        $parts = array_filter(array_map('trim', explode(',', (string) static::get('reminder_days'))), fn ($d) => $d !== '' && ctype_digit($d));
        $days = array_map('intval', $parts);
        sort($days);

        return array_values(array_unique($days));
    }

    protected static function cacheKey(int $companyId): string
    {
        return "settings.{$companyId}";
    }
}
