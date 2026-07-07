import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus, Tags, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
    expense_type: string | null;
    postings_count: number;
};

type Category = {
    id: number;
    name: string;
    type: string;
    subcategories: Subcategory[];
};

type CategoriesProps = {
    categories: Category[];
    categoryTypes: string[];
    expenseTypes: string[];
};

const EXPENSE_TYPE = 'Expense';

const typeGroupOrder = ['Income', 'Expense', 'Investment', 'Saving', 'Exclude'];

const typeBadgeVariant: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    Income: 'default',
    Expense: 'secondary',
    Investment: 'outline',
    Saving: 'outline',
    Exclude: 'destructive',
};

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
    expenseTypes,
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

    const groups = useMemo(() => {
        return orderedTypes(categoryTypes)
            .map((type) => ({
                type,
                categories: categories
                    .filter((category) => category.type === type)
                    .sort((a, b) => a.name.localeCompare(b.name)),
            }))
            .filter((group) => group.categories.length > 0);
    }, [categories, categoryTypes]);

    return (
        <>
            <Head title="Categories" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Categories
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Manage the shared category taxonomy that buckets
                            every posting across the platform.
                        </p>
                    </div>
                    <Button onClick={() => setIsCreatingCategory(true)}>
                        <Plus className="size-4" />
                        Add category
                    </Button>
                </div>

                {categories.length === 0 ? (
                    <div className="flex flex-col items-center gap-4 rounded-lg border border-dashed p-10 text-center">
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
                            Add category
                        </Button>
                    </div>
                ) : (
                    <div className="flex flex-col gap-8">
                        {groups.map((group) => (
                            <section
                                key={group.type}
                                className="flex flex-col gap-3"
                            >
                                <div className="flex items-baseline gap-2">
                                    <h2 className="text-sm font-semibold">
                                        {group.type}
                                    </h2>
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {group.categories.length}
                                    </span>
                                </div>
                                <div className="grid gap-4 lg:grid-cols-2">
                                    {group.categories.map((category) => (
                                        <CategoryCard
                                            key={category.id}
                                            category={category}
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
                                </div>
                            </section>
                        ))}
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
                key={
                    editingSubcategory === null
                        ? `subcategory-dialog-${creatingSubcategoryFor?.id ?? 'closed'}`
                        : `subcategory-dialog-${editingSubcategory.subcategory.id}`
                }
                category={
                    editingSubcategory?.category ?? creatingSubcategoryFor
                }
                subcategory={editingSubcategory?.subcategory ?? null}
                expenseTypes={expenseTypes}
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

function CategoryCard({
    category,
    onEditCategory,
    onDeleteCategory,
    onAddSubcategory,
    onEditSubcategory,
    onDeleteSubcategory,
}: {
    category: Category;
    onEditCategory: () => void;
    onDeleteCategory: () => void;
    onAddSubcategory: () => void;
    onEditSubcategory: (subcategory: Subcategory) => void;
    onDeleteSubcategory: (subcategory: Subcategory) => void;
}) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-4 p-4">
                <div className="flex items-start justify-between gap-2">
                    <div className="flex flex-col gap-1">
                        <div className="flex items-center gap-2">
                            <span className="font-medium">{category.name}</span>
                            <Badge
                                variant={
                                    typeBadgeVariant[category.type] ?? 'outline'
                                }
                            >
                                {category.type}
                            </Badge>
                        </div>
                        <span className="text-xs text-muted-foreground">
                            {category.subcategories.length}{' '}
                            {category.subcategories.length === 1
                                ? 'subcategory'
                                : 'subcategories'}
                        </span>
                    </div>
                    <div className="flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={onEditCategory}
                            aria-label="Edit category"
                        >
                            <Pencil className="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={onDeleteCategory}
                            aria-label="Delete category"
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                </div>

                {category.subcategories.length > 0 && (
                    <ul className="flex flex-col divide-y rounded-md border">
                        {category.subcategories.map((subcategory) => (
                            <li
                                key={subcategory.id}
                                className="flex items-center justify-between gap-2 px-3 py-2"
                            >
                                <div className="flex items-center gap-2">
                                    <span className="text-sm">
                                        {subcategory.name}
                                    </span>
                                    {subcategory.expense_type && (
                                        <Badge variant="outline">
                                            {subcategory.expense_type}
                                        </Badge>
                                    )}
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {subcategory.postings_count}{' '}
                                        {subcategory.postings_count === 1
                                            ? 'posting'
                                            : 'postings'}
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() =>
                                            onEditSubcategory(subcategory)
                                        }
                                        aria-label="Edit subcategory"
                                    >
                                        <Pencil className="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() =>
                                            onDeleteSubcategory(subcategory)
                                        }
                                        aria-label="Delete subcategory"
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <Button
                    variant="outline"
                    size="sm"
                    className="self-start"
                    onClick={onAddSubcategory}
                >
                    <Plus className="size-4" />
                    Add subcategory
                </Button>
            </CardContent>
        </Card>
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
    expense_type: string | null;
};

function SubcategoryDialog({
    category,
    subcategory,
    expenseTypes,
    open,
    onClose,
}: {
    category: Category | null;
    subcategory: Subcategory | null;
    expenseTypes: string[];
    open: boolean;
    onClose: () => void;
}) {
    const isEditing = subcategory !== null;
    const isExpense = category?.type === EXPENSE_TYPE;
    const form = useForm<SubcategoryForm>({
        category_id: category?.id ?? 0,
        name: subcategory?.name ?? '',
        expense_type: isExpense
            ? (subcategory?.expense_type ?? expenseTypes[0] ?? null)
            : null,
    });

    const submit = () => {
        form.transform((data) => ({
            ...data,
            expense_type: isExpense ? data.expense_type : null,
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
                        {category
                            ? `Under ${category.name} (${category.type}).`
                            : null}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
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
                            <Label>Expense type</Label>
                            <Select
                                value={form.data.expense_type ?? undefined}
                                onValueChange={(value) =>
                                    form.setData('expense_type', value)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select a type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {expenseTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.expense_type} />
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
