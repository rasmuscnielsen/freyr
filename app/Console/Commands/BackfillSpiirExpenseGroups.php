<?php

namespace App\Console\Commands;

use App\Enums\CategoryType;
use App\Models\Subcategory;
use App\Services\Spiir\ExpenseGroupMapping;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillSpiirExpenseGroups extends Command
{
    protected $signature = 'spiir:backfill-expense-groups {--apply : Apply the previewed assignments}';

    protected $description = 'Preview or fill unassigned expense groups using the known Spiir taxonomy';

    public function handle(): int
    {
        $proposals = [];
        $unmapped = [];
        $cleared = [];
        $changed = 0;

        foreach (Subcategory::query()->with('category')->orderBy('id')->get() as $subcategory) {
            if ($subcategory->category->type !== CategoryType::Expense) {
                if ($subcategory->expense_group !== null) {
                    $cleared[] = [$subcategory->legacy_id, $subcategory->name, 'Unassigned (non-expense)'];
                    if ($this->option('apply')) {
                        $changed += Subcategory::query()->whereKey($subcategory->id)
                            ->whereHas('category', fn (Builder $query): Builder => $query->where('type', '!=', CategoryType::Expense->value))
                            ->whereNotNull('expense_group')->update(['expense_group' => null]);
                    }
                }

                continue;
            }

            if ($subcategory->expense_group !== null) {
                continue;
            }

            $group = ExpenseGroupMapping::forSubcategory(
                $subcategory->legacy_id, $subcategory->name, $subcategory->category->legacy_id,
            );

            if ($group === null) {
                $unmapped[] = [$subcategory->legacy_id, $subcategory->name, $subcategory->category->legacy_id];

                continue;
            }

            $proposals[] = [$subcategory->legacy_id, $subcategory->name, $group->value];
            if ($this->option('apply')) {
                $changed += Subcategory::query()->whereKey($subcategory->id)
                    ->whereHas('category', fn (Builder $query): Builder => $query->where('type', CategoryType::Expense->value))
                    ->whereNull('expense_group')->update(['expense_group' => $group->value]);
            }
        }

        $this->info($this->option('apply') ? 'Applied expense group mapping.' : 'Preview only; use --apply to write these changes.');
        $this->table(['Spiir ID', 'Subcategory', 'Expense group'], [...$proposals, ...$cleared]);
        $this->info(count($proposals).' assignments; '.count($cleared).' non-expense clearings; '.count($unmapped).' unmapped.');
        if ($unmapped !== []) {
            $this->table(['Spiir ID', 'Unmapped subcategory', 'Parent Spiir ID'], $unmapped);
        }
        if ($this->option('apply')) {
            $this->info($changed.' rows changed.');
        }

        return self::SUCCESS;
    }
}
