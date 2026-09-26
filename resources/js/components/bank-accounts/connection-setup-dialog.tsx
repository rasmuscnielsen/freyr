import { router, useForm } from '@inertiajs/react';
import { CornerDownRight, Landmark } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent } from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { formatRelativeDateTime } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { update as updateAccount } from '@/routes/bank-accounts';
import { map as mapAccounts } from '@/routes/bank-connections/accounts';

export type SetupConnectionAccount = {
    id: number;
    status: string;
    name: string;
    currency: string;
    product: string | null;
    account_number_last_four: string | null;
    last_synced_at: string | null;
    bank_account: { id: number; name: string; is_active: boolean } | null;
};

export type SetupConnection = {
    id: number;
    aspsp_name: string;
    accounts: SetupConnectionAccount[];
};

export type LinkableAccount = {
    id: number;
    name: string;
    bank_name: string | null;
    currency: string;
};

type RowSource = 'create' | 'existing';

type HistoryOption = '1y' | '2y' | 'max';

type RowState = {
    enabled: boolean;
    source: RowSource;
    bankAccountId: string;
    history: HistoryOption;
    touched: boolean;
};

/**
 * A remote account starts enabled when it still needs a local home. When
 * exactly one linkable local account shares its currency, that account is the
 * obvious link target and is preselected.
 */
function defaultRow(
    account: SetupConnectionAccount,
    linkableAccounts: LinkableAccount[],
): RowState {
    const currencyMatches = linkableAccounts.filter(
        (local) => local.currency === account.currency,
    );
    const singleMatch =
        currencyMatches.length === 1 ? currencyMatches[0] : null;

    return {
        enabled: account.status === 'unlinked',
        source: singleMatch !== null ? 'existing' : 'create',
        bankAccountId: singleMatch !== null ? String(singleMatch.id) : '',
        history: '2y',
        touched: false,
    };
}

export function ConnectionSetupDialog({
    connection,
    linkableAccounts,
    isSetup,
    onClose,
}: {
    connection: SetupConnection | null;
    linkableAccounts: LinkableAccount[];
    isSetup: boolean;
    onClose: () => void;
}) {
    const [rows, setRows] = useState<Record<number, RowState>>(() =>
        Object.fromEntries(
            (connection?.accounts ?? [])
                .filter(
                    (account) =>
                        account.status === 'unlinked' ||
                        account.status === 'ignored',
                )
                .map((account) => [
                    account.id,
                    defaultRow(account, linkableAccounts),
                ]),
        ),
    );
    const form = useForm<{
        accounts: Array<{
            id: number;
            mode: string;
            name: string | null;
            bank_account_id: number | null;
            history: string | null;
        }>;
    }>({ accounts: [] });

    const accounts = connection?.accounts ?? [];
    const pendingAccounts = accounts.filter(
        (account) =>
            account.status === 'unlinked' || account.status === 'ignored',
    );

    const rowState = (account: SetupConnectionAccount): RowState =>
        rows[account.id] ?? defaultRow(account, linkableAccounts);

    const setRow = (id: number, patch: Partial<RowState>) => {
        setRows((current) => ({
            ...current,
            [id]: {
                ...(current[id] ?? {
                    enabled: false,
                    source: 'create' as RowSource,
                    bankAccountId: '',
                    history: '2y' as HistoryOption,
                    touched: false,
                }),
                ...patch,
            },
        }));
    };

    // Local accounts already picked by another row cannot be picked twice.
    const chosenAccountIds = new Set(
        pendingAccounts
            .map((account) => rowState(account))
            .filter(
                (row) =>
                    row.enabled &&
                    row.source === 'existing' &&
                    row.bankAccountId !== '',
            )
            .map((row) => row.bankAccountId),
    );

    const isRowValid = (account: SetupConnectionAccount): boolean => {
        const row = rowState(account);

        if (
            row.enabled &&
            row.source === 'existing' &&
            row.bankAccountId === ''
        ) {
            return false;
        }

        return true;
    };

    // Switched-on rows are posted as imports; rows the user explicitly
    // switched off are posted as ignores. Untouched off rows keep whatever
    // status they already had.
    const submittableAccounts = pendingAccounts.filter((account) => {
        const row = rowState(account);

        return row.enabled || row.touched;
    });

    const syncingCount = pendingAccounts.filter(
        (account) => rowState(account).enabled,
    ).length;

    const canSubmit =
        !form.processing &&
        connection !== null &&
        syncingCount > 0 &&
        submittableAccounts.every(isRowValid);

    const submit = () => {
        if (connection === null || !canSubmit) {
            return;
        }

        form.transform(() => ({
            accounts: submittableAccounts.map((account) => {
                const row = rowState(account);

                return {
                    id: account.id,
                    mode: row.enabled ? row.source : 'ignore',
                    name:
                        row.enabled && row.source === 'create'
                            ? account.name
                            : null,
                    bank_account_id:
                        row.enabled && row.source === 'existing'
                            ? Number(row.bankAccountId)
                            : null,
                    history: row.enabled ? row.history : null,
                };
            }),
        }));

        form.post(mapAccounts.url(connection.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog
            open={connection !== null}
            onOpenChange={(nextOpen) => !nextOpen && onClose()}
        >
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {isSetup
                            ? `Set up ${connection?.aspsp_name ?? 'bank'} accounts`
                            : `Manage ${connection?.aspsp_name ?? 'bank'} accounts`}
                    </DialogTitle>
                    <DialogDescription>
                        {isSetup
                            ? 'Switch on the accounts you want to sync. You can change this later.'
                            : 'Switch syncing on or off for each account.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-3">
                    {accounts.length === 0 ? (
                        <Alert>
                            <Landmark className="size-4" />
                            <AlertTitle>No accounts returned</AlertTitle>
                            <AlertDescription>
                                This connection has not returned any accounts
                                yet.
                            </AlertDescription>
                        </Alert>
                    ) : (
                        accounts.map((account) => (
                            <SetupRow
                                key={account.id}
                                account={account}
                                row={rowState(account)}
                                linkableAccounts={linkableAccounts}
                                takenAccountIds={chosenAccountIds}
                                errorPrefix={`accounts.${submittableAccounts.indexOf(account)}`}
                                errors={form.errors}
                                onChange={(patch) => setRow(account.id, patch)}
                            />
                        ))
                    )}
                </div>

                {isSetup && pendingAccounts.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {syncingCount} of {pendingAccounts.length} accounts will
                        start syncing
                    </p>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {isSetup ? 'Cancel' : 'Close'}
                    </Button>
                    {pendingAccounts.length > 0 && (
                        <Button onClick={submit} disabled={!canSubmit}>
                            {isSetup ? 'Finish setup' : 'Start import'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function SetupRow({
    account,
    row,
    linkableAccounts,
    takenAccountIds,
    errorPrefix,
    errors,
    onChange,
}: {
    account: SetupConnectionAccount;
    row: RowState;
    linkableAccounts: LinkableAccount[];
    takenAccountIds: Set<string>;
    errorPrefix: string;
    errors: Record<string, string | undefined>;
    onChange: (patch: Partial<RowState>) => void;
}) {
    const isLinked =
        account.status === 'linked' && account.bank_account !== null;
    const isArchived = isLinked && account.bank_account?.is_active === false;
    const isRenamed = isLinked && account.bank_account?.name !== account.name;
    const enabled = isLinked ? !isArchived : row.enabled;
    const [archiving, setArchiving] = useState(false);

    // Linked rows apply instantly: the switch archives or restores the local
    // account, with an optimistic flip so the UI never waits on the request.
    const setLinkedActive = (active: boolean) => {
        if (account.bank_account === null) {
            return;
        }

        setArchiving(true);
        router.patch(
            updateAccount.url(account.bank_account.id),
            { is_active: active },
            {
                preserveScroll: true,
                optimistic: (props) => ({
                    connections: (
                        props.connections as SetupConnection[] | undefined
                    )?.map((connection) => ({
                        ...connection,
                        accounts: connection.accounts.map((remote) =>
                            remote.id === account.id &&
                            remote.bank_account !== null
                                ? {
                                      ...remote,
                                      bank_account: {
                                          ...remote.bank_account,
                                          is_active: active,
                                      },
                                  }
                                : remote,
                        ),
                    })),
                }),
                onFinish: () => setArchiving(false),
            },
        );
    };

    const toggle = (next: boolean) => {
        if (isLinked) {
            setLinkedActive(next);

            return;
        }

        onChange({ enabled: next, touched: true });
    };

    const firstAvailableAccount = linkableAccounts.find(
        (local) =>
            !takenAccountIds.has(String(local.id)) ||
            row.bankAccountId === String(local.id),
    );

    return (
        <div
            className={cn(
                'rounded-xl border transition-colors',
                enabled && 'border-brand/50',
            )}
        >
            <div className="flex items-center gap-3 p-3 sm:p-4">
                <div
                    className={cn(
                        'flex size-10 shrink-0 items-center justify-center rounded-full transition-colors',
                        enabled
                            ? 'bg-brand/10 text-brand'
                            : 'bg-muted text-muted-foreground',
                    )}
                >
                    <Landmark className="size-4" />
                </div>
                <div
                    className={cn(
                        'min-w-0 flex-1 transition-opacity',
                        !enabled && 'opacity-60',
                    )}
                >
                    <span className="block truncate font-semibold">
                        {account.name}
                    </span>
                    {isRenamed && (
                        <span className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                            <CornerDownRight className="size-3 shrink-0" />
                            <span className="truncate">
                                {account.bank_account?.name}
                            </span>
                        </span>
                    )}
                    <p className="truncate text-sm text-muted-foreground">
                        {account.product ?? 'Account'} · {account.currency}
                        {account.account_number_last_four
                            ? ` · *${account.account_number_last_four}`
                            : ''}
                    </p>
                    <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                        {isLinked ? (
                            isArchived ? (
                                'Archived'
                            ) : (
                                <>
                                    <span
                                        className="size-1.5 shrink-0 rounded-full bg-brand"
                                        aria-hidden
                                    />
                                    <span className="truncate">
                                        Last sync:{' '}
                                        {formatRelativeDateTime(
                                            account.last_synced_at,
                                        )}
                                    </span>
                                </>
                            )
                        ) : row.enabled ? (
                            <>
                                <span
                                    className="size-1.5 shrink-0 rounded-full bg-brand"
                                    aria-hidden
                                />
                                Ready to import
                            </>
                        ) : (
                            'Not yet imported'
                        )}
                    </p>
                </div>
                <Switch
                    checked={enabled}
                    disabled={archiving}
                    onCheckedChange={toggle}
                    aria-label={
                        isLinked
                            ? `Syncing for ${account.name}`
                            : `Import ${account.name}`
                    }
                />
            </div>

            {!isLinked && (
                <Collapsible open={row.enabled}>
                    <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                        <div className="grid gap-4 border-t px-3 pt-3 pb-4 sm:px-4">
                            {linkableAccounts.length > 0 && (
                                <div className="grid gap-1.5">
                                    <span className="text-xs font-medium text-muted-foreground">
                                        Sync into
                                    </span>
                                    <SegmentedControl
                                        label="Sync into"
                                        value={row.source}
                                        onChange={(source) =>
                                            onChange({
                                                source,
                                                bankAccountId:
                                                    source === 'existing' &&
                                                    row.bankAccountId === ''
                                                        ? firstAvailableAccount !==
                                                          undefined
                                                            ? String(
                                                                  firstAvailableAccount.id,
                                                              )
                                                            : ''
                                                        : row.bankAccountId,
                                            })
                                        }
                                        options={[
                                            {
                                                value: 'create',
                                                label: 'New account',
                                            },
                                            {
                                                value: 'existing',
                                                label: 'Existing account',
                                            },
                                        ]}
                                    />
                                </div>
                            )}

                            {row.source === 'existing' &&
                                linkableAccounts.length > 0 && (
                                    <div>
                                        <Select
                                            value={row.bankAccountId}
                                            onValueChange={(value) =>
                                                onChange({
                                                    bankAccountId: value,
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                id={`setup-account-${account.id}`}
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Choose account…" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {linkableAccounts.map(
                                                    (localAccount) => (
                                                        <SelectItem
                                                            key={
                                                                localAccount.id
                                                            }
                                                            value={String(
                                                                localAccount.id,
                                                            )}
                                                            disabled={
                                                                takenAccountIds.has(
                                                                    String(
                                                                        localAccount.id,
                                                                    ),
                                                                ) &&
                                                                row.bankAccountId !==
                                                                    String(
                                                                        localAccount.id,
                                                                    )
                                                            }
                                                        >
                                                            {localAccount.name}{' '}
                                                            ·{' '}
                                                            {
                                                                localAccount.currency
                                                            }
                                                            {localAccount.bank_name
                                                                ? ` · ${localAccount.bank_name}`
                                                                : ''}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={
                                                errors[
                                                    `${errorPrefix}.bank_account_id`
                                                ]
                                            }
                                        />
                                    </div>
                                )}

                            <div className="grid gap-1.5">
                                <span className="text-xs font-medium text-muted-foreground">
                                    Include transaction history
                                </span>
                                <HistorySlider
                                    value={row.history}
                                    onChange={(history) =>
                                        onChange({ history })
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    How far back to import transactions on the
                                    first sync. Some banks cap this.
                                </p>
                            </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            )}
        </div>
    );
}

function SegmentedControl<T extends string>({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: T;
    options: Array<{ value: T; label: string }>;
    onChange: (value: T) => void;
}) {
    return (
        <div
            role="radiogroup"
            aria-label={label}
            className="grid auto-cols-fr grid-flow-col gap-1 rounded-lg bg-muted p-1"
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        value === option.value
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

const HISTORY_STOPS: Array<{ value: HistoryOption; label: string }> = [
    { value: '1y', label: '1 year' },
    { value: '2y', label: '2 years' },
    { value: 'max', label: 'Max available' },
];

function HistorySlider({
    value,
    onChange,
}: {
    value: HistoryOption;
    onChange: (value: HistoryOption) => void;
}) {
    const index = Math.max(
        0,
        HISTORY_STOPS.findIndex((stop) => stop.value === value),
    );
    const fill = (index / (HISTORY_STOPS.length - 1)) * 100;

    return (
        <div className="grid gap-1">
            <input
                type="range"
                min={0}
                max={HISTORY_STOPS.length - 1}
                step={1}
                value={index}
                aria-label="Include transaction history"
                aria-valuetext={HISTORY_STOPS[index].label}
                onChange={(event) =>
                    onChange(HISTORY_STOPS[Number(event.target.value)].value)
                }
                style={{
                    background: `linear-gradient(to right, var(--brand) ${fill}%, var(--muted) ${fill}%)`,
                }}
                className={cn(
                    'my-2 h-1.5 w-full cursor-pointer appearance-none rounded-full outline-none',
                    '[&::-webkit-slider-runnable-track]:appearance-none',
                    '[&::-webkit-slider-thumb]:-mt-[7px] [&::-webkit-slider-thumb]:size-5 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-brand [&::-webkit-slider-thumb]:bg-background [&::-webkit-slider-thumb]:shadow-sm',
                    '[&::-moz-range-track]:bg-transparent',
                    '[&::-moz-range-progress]:h-1.5 [&::-moz-range-progress]:rounded-full [&::-moz-range-progress]:bg-brand',
                    '[&::-moz-range-thumb]:size-5 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-2 [&::-moz-range-thumb]:border-brand [&::-moz-range-thumb]:bg-background [&::-moz-range-thumb]:shadow-sm',
                    'focus-visible:[&::-webkit-slider-thumb]:ring-2 focus-visible:[&::-webkit-slider-thumb]:ring-ring focus-visible:[&::-webkit-slider-thumb]:ring-offset-2 focus-visible:[&::-webkit-slider-thumb]:ring-offset-background',
                    'focus-visible:[&::-moz-range-thumb]:ring-2 focus-visible:[&::-moz-range-thumb]:ring-ring',
                )}
            />
            <div className="grid grid-cols-3">
                {HISTORY_STOPS.map((stop, stopIndex) => (
                    <button
                        key={stop.value}
                        type="button"
                        onClick={() => onChange(stop.value)}
                        className={cn(
                            'text-xs transition-colors',
                            stopIndex === 0 && 'text-left',
                            stopIndex === 1 && 'text-center',
                            stopIndex === 2 && 'text-right',
                            stopIndex === index
                                ? 'font-medium text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {stop.label}
                    </button>
                ))}
            </div>
        </div>
    );
}
