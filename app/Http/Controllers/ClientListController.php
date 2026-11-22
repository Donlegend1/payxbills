<?php

namespace App\Http\Controllers;

use App\Models\BinType;
use App\Tenancy\CompanyRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The company's own lists used on facility user records: types of bin (Wheeler bin, Mammoth bin...).
 * Managed on the Categories page and from the facility user form.
 */
class ClientListController extends Controller
{
    /** URL name => [model, table, label] */
    public const LISTS = [
        'bin-types' => [BinType::class, 'bin_types', 'Bin type'],
    ];

    public function store(Request $request, string $list): RedirectResponse
    {
        [$model, , $label] = $this->list($list);
        $item = $model::create($this->validated($request, $list));

        return back()->with('success', "{$label} {$item->name} added.");
    }

    public function update(Request $request, string $list, int $id): RedirectResponse
    {
        [$model, , $label] = $this->list($list);
        /** @var Model $item */
        $item = $model::findOrFail($id);
        $item->update($this->validated($request, $list, $item));

        return back()->with('success', "{$label} {$item->name} updated.");
    }

    protected function validated(Request $request, string $list, ?Model $item = null): array
    {
        [, $table] = $this->list($list);

        return $request->validate([
            'name' => ['required', 'string', 'max:100', CompanyRules::unique($table, 'name')->ignore($item)],
            'is_active' => ['boolean'],
        ]);
    }

    protected function list(string $list): array
    {
        return self::LISTS[$list] ?? abort(404);
    }
}
