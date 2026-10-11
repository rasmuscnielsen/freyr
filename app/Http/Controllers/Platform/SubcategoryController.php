<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreSubcategoryRequest;
use App\Http\Requests\Platform\UpdateSubcategoryRequest;
use App\Models\Subcategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class SubcategoryController extends Controller
{
    public function store(StoreSubcategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Subcategory::query()->create([
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'hyphenated_name' => Str::slug($data['name']),
            'expense_group' => $data['expense_group'] ?? null,
            'legacy_id' => null,
        ]);

        return back();
    }

    public function update(UpdateSubcategoryRequest $request, Subcategory $subcategory): RedirectResponse
    {
        $data = $request->validated();

        $subcategory->update([
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'hyphenated_name' => Str::slug($data['name']),
            'expense_group' => $data['expense_group'] ?? null,
        ]);

        return back();
    }

    public function destroy(Subcategory $subcategory): RedirectResponse
    {
        $subcategory->delete();

        return back();
    }
}
