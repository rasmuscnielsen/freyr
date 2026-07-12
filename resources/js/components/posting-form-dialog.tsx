import { useForm } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';
import { CategoryCombobox } from '@/components/category-combobox';
import type { CategoryComboboxOption } from '@/components/category-combobox';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { formatDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

export type PostingFormValues = {
    date: string;
    description: string;
    amount: string;
    categorized_subcategory_id: number | null;
    comment: string;
    is_extraordinary: boolean;
};

type PostingFormDialogProps = {
    open: boolean;
    onClose: () => void;
    title: string;
    description: string;
    currency: string;
    categoryOptions: CategoryComboboxOption[];
    initial: PostingFormValues;
    /**
     * Whether date, description, and amount can be edited. Synced postings are
     * owned by the bank feed, so only category, comment, and the extraordinary
     * flag stay editable — and only those fields are submitted.
     */
    coreFieldsEditable: boolean;
    action: { method: 'post' | 'patch'; url: string };
};

export function PostingFormDialog({
    open,
    onClose,
    title,
    description,
    currency,
    categoryOptions,
    initial,
    coreFieldsEditable,
    action,
}: PostingFormDialogProps) {
    const form = useForm<PostingFormValues>(initial);
    const [isPickingCategory, setIsPickingCategory] = useState(false);
    const selectedCategoryLabel =
        categoryOptions.find(
            (option) => option.id === form.data.categorized_subcategory_id,
        )?.label ?? null;

    const submit = () => {
        form.transform((data) => {
            const payload = {
                ...data,
                comment: data.comment.trim() === '' ? null : data.comment,
            };

            if (!coreFieldsEditable) {
                return {
                    categorized_subcategory_id:
                        payload.categorized_subcategory_id,
                    comment: payload.comment,
                    is_extraordinary: payload.is_extraordinary,
                };
            }

            return payload;
        });

        const options = { preserveScroll: true, onSuccess: onClose };

        if (action.method === 'post') {
            form.post(action.url, options);

            return;
        }

        form.patch(action.url, options);
    };

    return (
        <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    {coreFieldsEditable ? (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="posting-date">Date</Label>
                                    <Input
                                        id="posting-date"
                                        type="date"
                                        value={form.data.date}
                                        onChange={(event) =>
                                            form.setData(
                                                'date',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors.date} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="posting-amount">
                                        Amount ({currency})
                                    </Label>
                                    <Input
                                        id="posting-amount"
                                        type="number"
                                        step="0.01"
                                        placeholder="-125.00"
                                        value={form.data.amount}
                                        onChange={(event) =>
                                            form.setData(
                                                'amount',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors.amount} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="posting-description">
                                    Description
                                </Label>
                                <Input
                                    id="posting-description"
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.description} />
                            </div>
                        </>
                    ) : (
                        <div className="grid gap-1 rounded-md border bg-muted/50 p-3 text-sm">
                            <div className="flex items-baseline justify-between gap-6">
                                <span className="min-w-0 truncate font-medium">
                                    {form.data.description}
                                </span>
                                <span className="shrink-0 font-medium tabular-nums">
                                    {formatMoney(
                                        Number(form.data.amount),
                                        currency,
                                    )}
                                </span>
                            </div>
                            <span className="text-muted-foreground">
                                {formatDate(form.data.date)}
                            </span>
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label>Category</Label>
                        <div className="relative">
                            <Button
                                type="button"
                                variant="outline"
                                aria-haspopup="listbox"
                                className={cn(
                                    'w-full justify-between font-normal',
                                    selectedCategoryLabel === null &&
                                        'text-muted-foreground',
                                )}
                                onClick={() => setIsPickingCategory(true)}
                            >
                                {selectedCategoryLabel ?? 'Uncategorized'}
                                <ChevronsUpDown className="size-4 opacity-50" />
                            </Button>
                            {isPickingCategory && (
                                <CategoryCombobox
                                    options={categoryOptions}
                                    onSelect={(subcategoryId) => {
                                        form.setData(
                                            'categorized_subcategory_id',
                                            subcategoryId,
                                        );
                                        setIsPickingCategory(false);
                                    }}
                                    onClose={() => setIsPickingCategory(false)}
                                />
                            )}
                        </div>
                        <InputError
                            message={form.errors.categorized_subcategory_id}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="posting-comment">Comment</Label>
                        <Input
                            id="posting-comment"
                            placeholder="Optional note"
                            value={form.data.comment}
                            onChange={(event) =>
                                form.setData('comment', event.target.value)
                            }
                        />
                        <InputError message={form.errors.comment} />
                    </div>

                    <div className="flex items-start gap-3 rounded-md border p-3">
                        <Checkbox
                            id="posting-extraordinary"
                            checked={form.data.is_extraordinary}
                            onCheckedChange={(value) =>
                                form.setData('is_extraordinary', value === true)
                            }
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="posting-extraordinary">
                                Extraordinary
                            </Label>
                            <p className="text-sm text-muted-foreground">
                                Kept out of the overview so one-off amounts
                                don't skew your numbers.
                            </p>
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {form.processing ? 'Saving...' : 'Save posting'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
