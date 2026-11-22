<?php

namespace App\Http\Controllers;

use App\Models\BinType;
use App\Models\Category;
use App\Tenancy\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing categories, each with the amount its facility users pay per month. Super admin only.
 */
class CategoryController extends Controller
{
    public function index(): Response
    {
        $list = fn (string $model) => $model::withCount(['occupancies as members' => fn ($q) => $q->active()])
            ->orderBy('name')->get()->map(fn ($t) => [...$t->only('id', 'name', 'is_active'), 'members' => $t->members]);

        return Inertia::render('App/Categories', [
            'binTypes' => $list(BinType::class),
            'categories' => Category::withCount(['occupancies as members' => fn ($q) => $q->active()])
                ->orderBy('name')
                ->get()
                ->map(fn (Category $c) => [
                    ...$c->only('id', 'name', 'description', 'is_active'),
                    'monthly_amount' => (float) $c->monthly_amount,
                    'members' => $c->members,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $category = Category::create($this->validated($request));

        return back()->with('success', "Category {$category->name} created.");
    }

    /**
     * A new amount applies from the next month issued; invoices already issued keep their amount.
     */
    public function update(Request $request, Category $category): RedirectResponse
    {
        $category->update($this->validated($request, $category));

        $members = $category->occupancies()->active()->count();

        return back()->with('success', "Category {$category->name} updated. {$members} facility user(s) in it pay the new amount from their next bill.");
    }

    protected function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', CompanyRules::unique('categories')->ignore($category)],
            'monthly_amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
