<?php

namespace App\Http\Controllers;

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
        return Inertia::render('App/Categories', [
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

        return back()->with('success', "Category {$category->name} updated. New amounts apply from the next month billed.");
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
