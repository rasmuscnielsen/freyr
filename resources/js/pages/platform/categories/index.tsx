import { Head, useForm } from '@inertiajs/react';
import { CornerDownRight, Pencil, Plus, Tags, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import {
    destroy as destroyCategory,
    index as categoriesIndex,
    store as storeCategory,
    update as updateCategory,
} from '@/routes/platform/categories';
import {
    destroy as destroySubcategory,
    store as storeSubcategory,
    update as updateSubcategory,
} from '@/routes/platform/subcategories';

type Subcategory = {
    id: number;
    name: string;
    expense_group: string | null;
    postings_count: number;
};

type Category = {
    id: number;
    name: string;
    type: string;
    subcategories: Subcategory[];
};

type ExpenseGroup = {
    value: string;
    label: string;
};

type CategoriesProps = {
    categories: Category[];
    categoryTypes: string[];
    expenseGroups: ExpenseGroup[];
};

const EXPENSE_TYPE = 'Expense';

const typeGroupOrder = ['Income', 'Expense', 'Investment', 'Saving', 'Exclude'];

/**
 * Soft-tinted badge styles per category type, aligned with the app's bucket
 * color language. Colors come from the chart-* tokens at low opacity so the
 * taxonomy stays quick to scan without saturated fills.
 */
const typeBadgeClasses: Record<string, string> = {
    Income: 'border-transparent bg-chart-income/12 text-chart-income',
    Expense: 'border-transparent bg-chart-bills/12 text-chart-bills',
    Investment:
        'border-transparent bg-chart-investments/12 text-chart-investments',
    Saving: 'border-transparent bg-chart-savings/15 text-chart-savings',
    Exclude: 'border-transparent bg-muted text-muted-foreground',
};

/**
 * Expense-group badges use the corresponding dashboard bucket colors.
 */
const expenseGroupBadgeClasses: Record<string, string> = {
    Bills: 'border-transparent bg-chart-bills/12 text-chart-bills',
    LivingCosts:
        'border-transparent bg-chart-living-costs/12 text-chart-living-costs',
    Lifestyle: 'border-transparent bg-chart-lifestyle/12 text-chart-lifestyle',
    Other: 'border-transparent bg-chart-other/12 text-chart-other',
};

function TypeBadge({ type }: { type: string }) {
    return (
        <Badge
            variant="outline"
            className={cn(
                typeBadgeClasses[type] ??
                    'border-transparent bg-muted text-muted-foreground',
            )}
        >
            {type}
        </Badge>
    );
}

function orderedTypes(categoryTypes: string[]): string[] {
    return [
        ...typeGroupOrder.filter((type) => categoryTypes.includes(type)),
        ...categoryTypes.filter((type) => !typeGroupOrder.includes(type)),
    ];
}

function categorizedPostingsCount(subcategories: Subcategory[]): number {
    return subcategories.reduce(
        (total, subcategory) => total + subcategory.postings_count,
        0,
    );
}

Categories.layout = {
    breadcrumbs: [
        {
            title: 'Categories',
            href: categoriesIndex(),
        },
    ],
};

export default function Categories({
    categories,
    categoryTypes,
    expenseGroups,
}: CategoriesProps) {
    const [editingCategory, setEditingCategory] = useState<Category | null>(
        null,
    );
    const [isCreatingCategory, setIsCreatingCategory] = useState(false);
    const [deletingCategory, setDeletingCategory] = useState<Category | null>(
        null,
    );
    const [creatingSubcategoryFor, setCreatingSubcategoryFor] =
        useState<Category | null>(null);
    const [editingSubcategory, setEditingSubcategory] = useState<{
        category: Category;
        subcategory: Subcategory;
    } | null>(null);
    const [deletingSubcategory, setDeletingSubcategory] = useState<{
        category: Category;
        subcategory: Subcategory;
    } | null>(null);

    const expenseGroupLabels = useMemo(
        () =>
            Object.fromEntries(
                expenseGroups.map((group) => [group.value, group.label]),
            ),
        [expenseGroups],
    );

    const orderedCategories = useMemo(() => {
        const order = orderedTypes(categoryTypes);
        const typeRank = (type: string) => {
            const index = order.indexOf(type);

            return index === -1 ? order.length : index;
        };

        return [...categories].sort((a, b) => {
            const rankDiff = typeRank(a.type) - typeRank(b.type);

            if (rankDiff !== 0) {
                return rankDiff;
            }

            return a.name.localeCompare(b.name);
        });
    }, [categories, categoryTypes]);

    return (
        <>
            <Head title="Categories" />
            <div className="flex flex-col gap-4">
                <div className="flex items-end justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Categories"
                        description="Manage the shared category taxonomy that buckets every posting across the platform."
                    />
                    <Button onClick={() => setIsCreatingCategory(true)}>
                        <Plus className="size-4" />
                        New category
                    </Button>
                </div>

                {categories.length === 0 ? (
                    <div className="flex flex-col items-center gap-4 rounded-xl border border-dashed p-10 text-center">
                        <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                            <Tags className="size-6 text-muted-foreground" />
                        </div>
                        <div className="space-y-1">
                            <h2 className="text-lg font-semibold">
                                No categories yet
                            </h2>
                            <p className="mx-auto max-w-md text-sm text-muted-foreground">
                                Create your first category to start bucketing
                                postings in the overview.
                            </p>
                        </div>
                        <Button onClick={() => setIsCreatingCategory(true)}>
                            <Plus className="size-4" />
                            New category
                        </Button>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border bg-card shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[640px] text-sm">
                                <thead className="text-left">
                                    <tr>
                                        <th className="px-3 py-2.5 text-xs font-medium text-muted-foreground">
                                            Name
                                        </th>
                                        <th className="px-3 py-2.5 text-xs font-medium text-muted-foreground">
                                            Type
                                        </th>
                                        <th className="px-3 py-2.5 text-right text-xs font-medium text-muted-foreground">
                                            Postings
                                        </th>
                                        <th className="px-3 py-2.5 text-right text-xs font-medium text-muted-foreground">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orderedCategories.map((category) => (
                                        <CategoryRows
                                            key={category.id}
                                            category={category}
                                            expenseGroupLabels={
                                                expenseGroupLabels
                                            }
                                            onEditCategory={() =>
                                                setEditingCategory(category)
                                            }
                                            onDeleteCategory={() =>
                                                setDeletingCategory(category)
                                            }
                                            onAddSubcategory={() =>
                                                setCreatingSubcategoryFor(
                                                    category,
                                                )
                                            }
                                            onEditSubcategory={(subcategory) =>
                                                setEditingSubcategory({
                                                    category,
                                                    subcategory,
                                                })
                                            }
                                            onDeleteSubcategory={(
                                                subcategory,
                                            ) =>
                                                setDeletingSubcategory({
                                                    category,
                                                    subcategory,
                                                })
                                            }
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>

            <CategoryDialog
                key={
                    editingCategory === null
                        ? `category-dialog-${isCreatingCategory ? 'create' : 'closed'}`
                        : `category-dialog-${editingCategory.id}`
                }
                category={editingCategory}
                categoryTypes={categoryTypes}
                open={editingCategory !== null || isCreatingCategory}
                onClose={() => {
                    setEditingCategory(null);
                    setIsCreatingCategory(false);
                }}
            />

            <SubcategoryDialog
                categories={categories}
                key={
                    editingSubcategory === null
                        ? `subcategory-dialog-${creatingSubcategoryFor?.id ?? 'closed'}`
                        : `subcategory-dialog-${editingSubcategory.subcategory.id}`
                }
                category={
                    editingSubcategory?.category ?? creatingSubcategoryFor
                }
                subcategory={editingSubcategory?.subcategory ?? null}
                expenseGroups={expenseGroups}
                open={
                    editingSubcategory !== null ||
                    creatingSubcategoryFor !== null
                }
                onClose={() => {
                    setEditingSubcategory(null);
                    setCreatingSubcategoryFor(null);
                }}
            />

            <DeleteCategoryDialog
                category={deletingCategory}
                onClose={() => setDeletingCategory(null)}
            />

            <DeleteSubcategoryDialog
                subcategory={deletingSubcategory?.subcategory ?? null}
                onClose={() => setDeletingSubcategory(null)}
            />
        </>
    );
}

function CategoryRows({
    category,
    expenseGroupLabels,
    onEditCategory,
    onDeleteCategory,
    onAddSubcategory,
    onEditSubcategory,
    onDeleteSubcategory,
}: {
    category: Category;
    expenseGroupLabels: Record<string, string>;
    onEditCategory: () => void;
    onDeleteCategory: () => void;
    onAddSubcategory: () => void;
    onEditSubcategory: (subcategory: Subcategory) => void;
    onDeleteSubcategory: (subcategory: Subcategory) => void;
}) {
    const totalPostings = categorizedPostingsCount(category.subcategories);
    const subcategories = useMemo(
        () =>
            [...category.subcategories].sort((a, b) =>
                a.name.localeCompare(b.name),
            ),
        [category.subcategories],
    );

    return (
        <>
            <tr className="border-t transition-colors hover:bg-muted/40">
                <td className="px-3 py-2">
                    <span className="font-medium">{category.name}</span>
                </td>
                <td className="px-3 py-2">
                    <TypeBadge type={category.type} />
                </td>
                <td className="px-3 py-2 text-right text-muted-foreground tabular-nums">
                    {totalPostings}
                </td>
                <td className="px-3 py-2">
                    <div className="flex items-center justify-end gap-0.5">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            onClick={onAddSubcategory}
                            aria-label={`Add subcategory to ${category.name}`}
                            title="Add subcategory"
                        >
                            <Plus className="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            onClick={onEditCategory}
                            aria-label={`Edit ${category.name}`}
                            title="Edit category"
                        >
                            <Pencil className="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8 text-muted-foreground hover:text-destructive"
                            onClick={onDeleteCategory}
                            aria-label={`Delete ${category.name}`}
                            title="Delete category"
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                </td>
            </tr>

            {subcategories.map((subcategory) => (
                <tr
                    key={subcategory.id}
                    className="border-t border-border/50 transition-colors hover:bg-muted/40"
                >
                    <td className="py-1.5 pr-3 pl-3">
                        <div className="flex items-center gap-1.5 text-muted-foreground">
                            <CornerDownRight className="ml-4 size-3.5 shrink-0 text-muted-foreground/50" />
                            <span className="text-foreground">
                                {subcategory.name}
                            </span>
                        </div>
                    </td>
                    <td className="py-1.5 pr-3 pl-3">
                        {category.type === EXPENSE_TYPE && (
                            <Badge
                                variant="outline"
                                className={cn(
                                    expenseGroupBadgeClasses[
                                        subcategory.expense_group ?? ''
                                    ] ?? 'text-muted-foreground',
                                )}
                            >
                                {subcategory.expense_group === null
                                    ? 'Unassigned'
                                    : (expenseGroupLabels[
                                          subcategory.expense_group
                                      ] ?? subcategory.expense_group)}
                            </Badge>
                        )}
                    </td>
                    <td className="py-1.5 pr-3 pl-3 text-right text-muted-foreground tabular-nums">
                        {subcategory.postings_count}
                    </td>
                    <td className="py-1.5 pr-3 pl-3">
                        <div className="flex items-center justify-end gap-0.5">
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                onClick={() => onEditSubcategory(subcategory)}
                                aria-label={`Edit ${subcategory.name}`}
                                title="Edit subcategory"
                            >
                                <Pencil className="size-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 text-muted-foreground hover:text-destructive"
                                onClick={() => onDeleteSubcategory(subcategory)}
                                aria-label={`Delete ${subcategory.name}`}
                                title="Delete subcategory"
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    </td>
                </tr>
            ))}
        </>
    );
}

type CategoryForm = {
    name: string;
    type: string;
};

function CategoryDialog({
    category,
    categoryTypes,
    open,
    onClose,
}: {
    category: Category | null;
    categoryTypes: string[];
    open: boolean;
    onClose: () => void;
}) {
    const isEditing = category !== null;
    const form = useForm<CategoryForm>({
        name: category?.name ?? '',
        type: category?.type ?? categoryTypes[0] ?? EXPENSE_TYPE,
    });

    const submit = () => {
        if (isEditing) {
            form.patch(updateCategory.url(category.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        form.post(storeCategory.url(), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {isEditing ? 'Edit category' : 'Add category'}
                    </DialogTitle>
                    <DialogDescription>
                        The category type determines how its postings are
                        bucketed in the overview.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="category-name">Name</Label>
                        <Input
                            id="category-name"
                            value={form.data.name}
                            maxLength={100}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Type</Label>
                        <Select
                            value={form.data.type}
                            onValueChange={(value) =>
                                form.setData('type', value)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {categoryTypes.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {type}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.type} />
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {isEditing ? 'Save changes' : 'Create category'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

type SubcategoryForm = {
    category_id: number;
    name: string;
    expense_group: string | null;
};

function SubcategoryDialog({
    categories,
    category,
    subcategory,
    expenseGroups,
    open,
    onClose,
}: {
    categories: Category[];
    category: Category | null;
    subcategory: Subcategory | null;
    expenseGroups: ExpenseGroup[];
    open: boolean;
    onClose: () => void;
}) {
    const isEditing = subcategory !== null;
    const form = useForm<SubcategoryForm>({
        category_id: category?.id ?? 0,
        name: subcategory?.name ?? '',
        expense_group:
            category?.type === EXPENSE_TYPE
                ? (subcategory?.expense_group ?? null)
                : null,
    });
    const isExpense =
        categories.find((option) => option.id === form.data.category_id)
            ?.type === EXPENSE_TYPE;

    const submit = () => {
        form.transform((data) => ({
            ...data,
            expense_group: isExpense ? data.expense_group : null,
        }));

        if (isEditing) {
            form.patch(updateSubcategory.url(subcategory.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        form.post(storeSubcategory.url(), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {isEditing ? 'Edit subcategory' : 'Add subcategory'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEditing
                            ? 'Moving a subcategory also moves its postings for all users.'
                            : 'Choose the category for this subcategory.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="subcategory-category">Category</Label>
                        <Select
                            value={String(form.data.category_id)}
                            onValueChange={(value) => {
                                const categoryId = Number(value);
                                form.setData({
                                    ...form.data,
                                    category_id: categoryId,
                                    expense_group:
                                        categories.find(
                                            (option) =>
                                                option.id === categoryId,
                                        )?.type === EXPENSE_TYPE
                                            ? form.data.expense_group
                                            : null,
                                });
                            }}
                        >
                            <SelectTrigger
                                id="subcategory-category"
                                className="w-full"
                            >
                                <SelectValue placeholder="Select a category" />
                            </SelectTrigger>
                            <SelectContent>
                                {categories.map((option) => (
                                    <SelectItem
                                        key={option.id}
                                        value={String(option.id)}
                                    >
                                        {option.name} ({option.type})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.category_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="subcategory-name">Name</Label>
                        <Input
                            id="subcategory-name"
                            value={form.data.name}
                            maxLength={100}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    {isExpense && (
                        <div className="grid gap-2">
                            <Label>Expense group</Label>
                            <Select
                                value={form.data.expense_group ?? 'unassigned'}
                                onValueChange={(value) =>
                                    form.setData(
                                        'expense_group',
                                        value === 'unassigned' ? null : value,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select a group" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="unassigned">
                                        Unassigned
                                    </SelectItem>
                                    {expenseGroups.map((group) => (
                                        <SelectItem
                                            key={group.value}
                                            value={group.value}
                                        >
                                            {group.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.expense_group} />
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {isEditing ? 'Save changes' : 'Create subcategory'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DeleteCategoryDialog({
    category,
    onClose,
}: {
    category: Category | null;
    onClose: () => void;
}) {
    const form = useForm();
    const affectedPostings = category
        ? categorizedPostingsCount(category.subcategories)
        : 0;

    const submit = () => {
        if (category === null) {
            return;
        }

        form.delete(destroyCategory.url(category.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog
            open={category !== null}
            onOpenChange={(nextOpen) => !nextOpen && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete category</DialogTitle>
                    <DialogDescription>
                        {category
                            ? `Deleting “${category.name}” removes its ${category.subcategories.length} ${category.subcategories.length === 1 ? 'subcategory' : 'subcategories'} and uncategorizes ${affectedPostings} ${affectedPostings === 1 ? 'posting' : 'postings'}. This cannot be undone.`
                            : null}
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={submit}
                        disabled={form.processing}
                    >
                        Delete category
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DeleteSubcategoryDialog({
    subcategory,
    onClose,
}: {
    subcategory: Subcategory | null;
    onClose: () => void;
}) {
    const form = useForm();

    const submit = () => {
        if (subcategory === null) {
            return;
        }

        form.delete(destroySubcategory.url(subcategory.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog
            open={subcategory !== null}
            onOpenChange={(nextOpen) => !nextOpen && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete subcategory</DialogTitle>
                    <DialogDescription>
                        {subcategory
                            ? `Deleting “${subcategory.name}” uncategorizes ${subcategory.postings_count} ${subcategory.postings_count === 1 ? 'posting' : 'postings'}. This cannot be undone.`
                            : null}
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={submit}
                        disabled={form.processing}
                    >
                        Delete subcategory
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
