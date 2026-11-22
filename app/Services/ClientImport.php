<?php

namespace App\Services;

use App\Models\BinType;
use App\Models\Facility;
use App\Models\Category;
use App\Models\Occupancy;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Enrol clients from an Excel (or CSV) sheet, and export them in the same layout so their
 * details can be edited in Excel and uploaded again.
 *
 * A row whose Account code (or email) matches an existing client updates that client; only the
 * cells that are filled in change. Any other row registers a new client. Unknown facility and bin
 * types are added to the company's lists.
 */
class ClientImport
{
    /** Most rows read from one file. */
    public const MAX_ROWS = 5000;

    /** Column headings, in template order => field. */
    public const COLUMNS = [
        'Account code' => 'code',
        'Full name' => 'name',
        'Email' => 'email',
        'Phone' => 'phone',
        'House no.' => 'house_no',
        'Street' => 'street',
        'District' => 'district',
        'Category' => 'category',
        'Type of bins' => 'bin_type',
        'No. of bins' => 'bins',
        'Frequency (times per week)' => 'frequency',
        'Billing day (1-28)' => 'billing_day',
        // Optional: only for facility users who are in one of your facilities
        'Facility' => 'facility',
        // Only used to create a category that does not exist yet; existing categories keep their amount
        'Monthly amount (new categories only)' => 'amount',
    ];

    /** Other headings people use for the same columns (compared without spaces or punctuation). */
    protected const ALIASES = [
        'code' => ['account', 'accountcode', 'accountno', 'code', 'clientcode'],
        'name' => ['fullname', 'name', 'clientname', 'customername'],
        'email' => ['email', 'emailaddress'],
        'phone' => ['phone', 'phonenumber', 'phoneno', 'mobile', 'telephone'],
        'house_no' => ['houseno', 'housenumber', 'house', 'plotno'],
        'street' => ['street', 'streetname', 'address'],
        'district' => ['district', 'area', 'lga'],
        'category' => ['category', 'categoryoffacility', 'facilitycategory', 'facilitytype', 'typeoffacility', 'billingcategory'],
        'bin_type' => ['typeofbins', 'typeofbin', 'bintype', 'bintypes', 'binstype'],
        'bins' => ['noofbins', 'numberofbins', 'bins', 'binsno'],
        'frequency' => ['frequency', 'frequencytimesperweek', 'timesperweek', 'frequencyperweek'],
        'amount' => ['amount', 'monthlyamount', 'monthlyamountnewcategoriesonly', 'monthlyfee', 'fee'],
        'billing_day' => ['billingday', 'billingdate', 'billingday128'],
        'start_date' => ['startdate', 'billingstarts', 'datejoined'],
        'facility' => ['facility', 'facilityname', 'site', 'building'],
    ];

    public function __construct(protected FacilityUserService $clients) {}

    /**
     * @return array{created: int, updated: int, pending: int, errors: list<string>}
     */
    public function import(string $path, string $extension, User $by, bool $activate = false): array
    {
        $rows = $this->read($path, $extension);
        $header = array_shift($rows) ?? [];
        $map = $this->mapHeader($header);

        if (! in_array('name', $map, true) && ! in_array('code', $map, true)) {
            throw new InvalidArgumentException('The first row must hold the column headings, including "Full name". Download the template to see the layout.');
        }

        $result = ['created' => 0, 'updated' => 0, 'pending' => 0, 'errors' => []];

        foreach ($rows as $i => $cells) {
            $line = $i + 2; // spreadsheet row number: heading is row 1
            $values = $this->values($map, $cells);

            if (array_filter($values, fn ($v) => $v !== null) === []) {
                continue; // empty row
            }

            try {
                $result[$this->importRow($values, $by, $activate)]++;
            } catch (InvalidArgumentException $e) {
                $result['errors'][] = "Row {$line}: {$e->getMessage()}";
            } catch (Throwable $e) {
                report($e);
                $result['errors'][] = "Row {$line}: could not be saved.";
            }
        }

        return $result;
    }

    /**
     * Write the facility users in the import layout, or (with none given) the sample sheet: an empty
     * "Facility users" sheet to fill in, plus an "Example and help" sheet that is never imported.
     *
     * @param  Collection<int, Occupancy>|null  $occupancies
     */
    public function export(string $path, ?Collection $occupancies = null): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Facility users');
        $writer->addRow(Row::fromValues(array_keys(self::COLUMNS)));

        if ($occupancies === null) {
            // Only the first sheet is imported, so the examples cannot become real facility users
            $writer->addNewSheetAndMakeItCurrent()->setName('Example and help');
            $writer->addRow(Row::fromValues(['Fill in the "Facility users" sheet, one facility user per row, then upload it. Only Full name is required. This sheet is not imported.']));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(array_keys(self::COLUMNS)));
            $writer->addRow(Row::fromValues(['', 'Ada Okafor', 'ada@example.com', '08012345678', '12', 'Allen Avenue', 'Ikeja', 'House', 'Wheeler bin', 2, 3, 1, '', '']));
            $writer->addRow(Row::fromValues(['', 'Bright Future Academy', '', '0803 111 2222', '4', 'School Road', 'Surulere', 'School', 'Mammoth bin', 3, 5, 10, '', '']));
            $writer->addRow(Row::fromValues(['', 'Musa Stores', '', '', '27B', 'Market Street', 'Yaba', 'Shop', 'Wheeler bin', 1, 6, '', '', 12000]));
            $writer->addRow(Row::fromValues([]));
            foreach ([
                ['Account code', 'Leave empty for new facility users. Filled in when you download your facility users: keep it to update them.'],
                ['Category', 'One of your categories (e.g. House, School, Shop). The category decides what they pay each month.'],
                ['Monthly amount (new categories only)', 'Only needed for a category you have not created yet: it is added with this amount. Existing categories keep their amount.'],
                ['Type of bins', 'Any name. New ones are added to your list (e.g. Wheeler bin, Mammoth bin).'],
                ['Frequency', 'How many times a week bins are collected.'],
                ['Billing day', 'Day of the month they are billed (1-28). Leave empty to use your company billing day.'],
                ['Facility', 'Optional: the name of one of your facilities.'],
            ] as $help) {
                $writer->addRow(Row::fromValues($help));
            }
        } else {
            foreach ($occupancies as $o) {
                $writer->addRow(Row::fromValues([
                    $o->code, $o->user->name, $o->user->email, $o->user->phone, $o->house_no, $o->street, $o->district,
                    $o->category?->name, $o->binType?->name, $o->bins, $o->frequency,
                    $o->billing_day, $o->facility?->name, null,
                ]));
            }
        }

        $writer->close();
    }

    /**
     * @return string created, updated, or pending (changes waiting for the admin)
     */
    protected function importRow(array $values, User $by, bool $activate): string
    {
        $existing = $this->find($values);
        $data = array_filter($values, fn ($v) => $v !== null);
        unset($data['code']);

        // Validate everything but the lists first, so a bad row adds nothing to them
        $rules = FacilityUserService::rules();
        if ($existing) {
            $rules['name'] = ['sometimes', ...$rules['name']];
        }

        if (isset($data['amount']) && ! is_numeric($data['amount'])) {
            throw new InvalidArgumentException('The monthly amount must be a number.');
        }

        $validator = Validator::make($data, array_intersect_key($rules, $data + ($existing ? [] : ['name' => null])));

        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        if ($problem = FacilityUserService::emailProblem($data['email'] ?? null, $existing?->user_id)) {
            throw new InvalidArgumentException($problem);
        }

        if (isset($data['category'])) {
            $data['category_id'] = $this->category($data['category'], $data['amount'] ?? null)->id;
        }
        if (isset($data['bin_type'])) {
            $data['bin_type_id'] = BinType::findOrAdd($data['bin_type'])->id;
        }
        if (isset($data['facility'])) {
            $data['facility_id'] = Facility::whereRaw('lower(name) = ?', [mb_strtolower($data['facility'])])->value('id')
                ?? throw new InvalidArgumentException("There is no facility called {$data['facility']}.");
        }
        unset($data['category'], $data['amount'], $data['bin_type'], $data['facility']);

        if ($existing) {
            try {
                return $this->clients->requestChange($existing, $data, $by) ? 'pending' : 'updated';
            } catch (InvalidArgumentException $e) {
                if ($e->getMessage() === 'Nothing was changed.') {
                    return 'updated';
                }
                throw $e;
            }
        }

        $this->clients->register($data, $by, $activate);

        return 'created';
    }

    /**
     * The category called $name. A new one can only be added with its monthly amount, because the
     * category decides what everyone in it pays.
     */
    protected function category(string $name, mixed $amount): Category
    {
        $category = Category::whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->first();

        if ($category) {
            return $category;
        }

        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new InvalidArgumentException("There is no category called {$name}. Add it on the Categories page, or fill in its monthly amount in this row to add it.");
        }

        return Category::create(['name' => trim($name), 'monthly_amount' => (float) $amount]);
    }

    /**
     * The facility user a row is about: by account code, else by email.
     */
    protected function find(array $values): ?Occupancy
    {
        if ($values['code'] ?? null) {
            return Occupancy::where('code', $values['code'])->first()
                ?? throw new InvalidArgumentException("No client has account code {$values['code']}. Leave the code empty to add a new client.");
        }

        if ($values['email'] ?? null) {
            return Occupancy::active()->whereHas('user', fn ($q) => $q->where('email', $values['email']))->first();
        }

        return null;
    }

    /**
     * Column index => field, from the heading row.
     *
     * @return array<int, string>
     */
    protected function mapHeader(array $header): array
    {
        $map = [];

        foreach ($header as $i => $heading) {
            $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $heading));

            foreach (self::ALIASES as $field => $aliases) {
                if (in_array($key, $aliases, true) && ! in_array($field, $map, true)) {
                    $map[$i] = $field;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Clean cell values for one row: trimmed text, numbers from "₦15,000" or "3 times", and the
     * day of the month from a billing date.
     *
     * @return array<string, mixed>
     */
    protected function values(array $map, array $cells): array
    {
        $values = [];

        foreach ($map as $i => $field) {
            $raw = $cells[$i] ?? null;
            $text = $raw instanceof DateTimeInterface ? $raw : trim((string) $raw);

            $values[$field] = match (true) {
                $text === '' => null,
                $field === 'billing_day' => $this->day($text),
                $field === 'start_date' => $text instanceof DateTimeInterface ? Carbon::instance($text)->toDateString() : $text,
                in_array($field, ['bins', 'frequency'], true) => preg_match('/\d+/', (string) $text, $m) ? (int) $m[0] : $text,
                $field === 'amount' => is_numeric($n = preg_replace('/[^0-9.]/', '', (string) $text)) ? $n : $text,
                $field === 'email' => strtolower((string) $text),
                $field === 'code' => strtoupper((string) $text),
                default => (string) $text,
            };
        }

        return $values;
    }

    /**
     * Billing day from "5", 5, a date, or "2026-03-05". Days 29-31 become 28 so every month has one.
     */
    protected function day(mixed $value): mixed
    {
        $day = match (true) {
            $value instanceof DateTimeInterface => (int) $value->format('j'),
            is_numeric($value) => (int) $value,
            default => rescue(fn () => Carbon::parse($value)->day, null, false),
        };

        return $day === null ? $value : max(1, min(28, $day));
    }

    /**
     * @return list<array<int, mixed>>
     */
    protected function read(string $path, string $extension): array
    {
        $reader = match (strtolower($extension)) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader,
            default => throw new InvalidArgumentException('Upload an Excel (.xlsx) or CSV file.'),
        };

        $rows = [];
        $reader->open($path);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();

                if (count($rows) > self::MAX_ROWS + 1) {
                    $reader->close();

                    throw new InvalidArgumentException('Upload at most '.self::MAX_ROWS.' clients at a time.');
                }
            }
            break; // first sheet only
        }

        $reader->close();

        return $rows;
    }
}
