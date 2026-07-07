import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ComposedChart,
    LabelList,
    Line,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMonth } from '@/lib/dates';
import { formatMoney as formatSharedMoney, formatNumber } from '@/lib/money';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as postingsIndex } from '@/routes/postings';

type Period = {
    selected: string;
    months: number;
    start: string;
    end: string;
    options: string[];
};

type Summary = {
    in: number;
    out: number;
    result: number;
    income: number;
    bills: number;
    consumption: number;
    investments: number;
    savings: number;
    uncategorized: number;
};

type MonthlyPoint = {
    month: string;
    label: string;
    income: number;
    bills: number;
    consumption: number;
    investments: number;
    savings: number;
    uncategorized: number;
    result: number;
};

type TopSubcategory = {
    name: string;
    amount: number;
};

type TopPosting = {
    description: string;
    date: string;
    amount: number;
};

type BreakdownCategory = {
    id: number | null;
    name: string;
    type: 'income' | 'expense';
    total: number;
    topSubcategories: TopSubcategory[];
    topPostings: TopPosting[];
};

type Breakdown = {
    month: string | null;
    months: number;
    categories: BreakdownCategory[];
};

type DashboardProps = {
    period: Period;
    summary: Summary;
    monthly: MonthlyPoint[];
    breakdown?: Breakdown;
    balance: number | null;
    primaryCurrency: string | null;
};

type BucketKey =
    | 'bills'
    | 'consumption'
    | 'investments'
    | 'savings'
    | 'uncategorized';

type BucketMeta = {
    key: BucketKey;
    label: string;
    colorClass: string;
    cssVar: string;
};

/**
 * The five outgoing buckets, in the order they stack and appear in the legend.
 * Colours map to the `--color-chart-*` tokens defined in app.css.
 */
const BUCKETS: BucketMeta[] = [
    {
        key: 'bills',
        label: 'Bills',
        colorClass: 'bg-chart-bills',
        cssVar: 'var(--color-chart-bills)',
    },
    {
        key: 'consumption',
        label: 'Consumption',
        colorClass: 'bg-chart-consumption',
        cssVar: 'var(--color-chart-consumption)',
    },
    {
        key: 'investments',
        label: 'Investments',
        colorClass: 'bg-chart-investments',
        cssVar: 'var(--color-chart-investments)',
    },
    {
        key: 'savings',
        label: 'Savings',
        colorClass: 'bg-chart-savings',
        cssVar: 'var(--color-chart-savings)',
    },
    {
        key: 'uncategorized',
        label: 'Uncategorized',
        colorClass: 'bg-chart-uncategorized',
        cssVar: 'var(--color-chart-uncategorized)',
    },
];

function formatMoney(value: number, currency: string | null): string {
    return formatSharedMoney(value, currency, { maximumFractionDigits: 0 });
}

/**
 * Formats a magnitude with an explicit leading sign, e.g. "+ DKK 50.123" or
 * "− DKK 12.000". Uses a true minus sign for typographic balance.
 */
function formatSignedMoney(value: number, currency: string | null): string {
    const rounded = Math.round(value);
    const sign = rounded < 0 ? '−' : '+';

    return `${sign} ${formatMoney(Math.abs(rounded), currency)}`;
}

function formatAxis(value: number): string {
    return formatNumber(value, {
        notation: 'compact',
        maximumFractionDigits: 1,
    });
}

function formatMonthLabel(month: string): string {
    const [year, monthPart] = month.split('-');
    const date = new Date(Number(year), Number(monthPart) - 1, 1);

    return new Intl.DateTimeFormat(undefined, {
        month: 'long',
        year: 'numeric',
    }).format(date);
}

const mutedTick = { fill: 'var(--muted-foreground)', fontSize: 12 };

function periodLabel(option: string): string {
    return option === '12m' ? '12 months' : option;
}

function flowTooltip(props: unknown, currency: string | null) {
    const { active, payload, label } = props as {
        active?: boolean;
        payload?: Array<{ name: string; value: number; color?: string }>;
        label?: string;
    };

    if (!active || !payload?.length) {
        return null;
    }

    return (
        <div className="rounded-lg border bg-popover p-3 text-sm text-popover-foreground shadow-lg">
            <div className="mb-2 font-medium">{label}</div>
            <div className="space-y-1">
                {payload
                    .filter((item) => Number(item.value) !== 0)
                    .map((item) => (
                        <div
                            key={item.name}
                            className="flex items-center justify-between gap-6"
                        >
                            <span style={{ color: item.color }}>
                                {item.name}
                            </span>
                            <span className="font-medium">
                                {formatMoney(
                                    Math.abs(Number(item.value)),
                                    currency,
                                )}
                            </span>
                        </div>
                    ))}
            </div>
        </div>
    );
}

function breakdownTooltip(props: unknown, currency: string | null) {
    const { active, payload } = props as {
        active?: boolean;
        payload?: Array<{ payload?: BreakdownCategory }>;
    };

    const category = active ? payload?.[0]?.payload : undefined;

    if (!category) {
        return null;
    }

    return (
        <div className="min-w-56 rounded-lg border bg-popover p-3 text-sm text-popover-foreground shadow-lg">
            <div className="mb-2 flex items-center justify-between gap-6 font-medium">
                <span>{category.name}</span>
                <span>{formatMoney(category.total, currency)}</span>
            </div>
            {category.topSubcategories.length > 0 && (
                <div className="space-y-1 border-t pt-2 text-muted-foreground">
                    {category.topSubcategories.map((subcategory) => (
                        <div
                            key={subcategory.name}
                            className="flex items-center justify-between gap-6"
                        >
                            <span>{subcategory.name}</span>
                            <span className="font-medium text-foreground">
                                {formatMoney(subcategory.amount, currency)}
                            </span>
                        </div>
                    ))}
                </div>
            )}
            {category.topPostings.length > 0 && (
                <div className="mt-2 border-t pt-2">
                    <div className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Largest postings
                    </div>
                    <div className="space-y-1 text-muted-foreground">
                        {category.topPostings.map((posting) => (
                            <div
                                key={`${posting.date}-${posting.description}-${posting.amount}`}
                                className="flex items-center justify-between gap-6"
                            >
                                <span className="flex min-w-0 items-baseline gap-2">
                                    <span className="max-w-40 truncate">
                                        {posting.description}
                                    </span>
                                    <span className="shrink-0 text-xs">
                                        {posting.date}
                                    </span>
                                </span>
                                <span className="font-medium text-foreground">
                                    {formatMoney(posting.amount, currency)}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

export default function Dashboard({
    period,
    summary,
    monthly,
    breakdown,
    balance,
    primaryCurrency,
}: DashboardProps) {
    const [selectedMonth, setSelectedMonth] = useState<string | null>(
        breakdown?.month ?? null,
    );

    const lastCompletedMonth = period.end.slice(0, 7);

    // Bucket totals arrive as positive magnitudes; outgoing buckets are drawn
    // below the axis.
    const flowData = monthly.map((point) => ({
        ...point,
        billsStacked: -Math.abs(point.bills),
        consumptionStacked: -Math.abs(point.consumption),
        investmentsStacked: -Math.abs(point.investments),
        savingsStacked: -Math.abs(point.savings),
        uncategorizedStacked: -Math.abs(point.uncategorized),
    }));

    const changePeriod = (value: string) => {
        setSelectedMonth(null);
        router.get(
            dashboard.url({ query: { period: value } }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    const reloadBreakdown = (month: string | null) => {
        setSelectedMonth(month);
        router.reload({
            only: ['breakdown'],
            data: { breakdownMonth: month ?? undefined },
        });
    };

    const toggleMonth = (month: string) => {
        if (month > lastCompletedMonth) {
            return;
        }

        reloadBreakdown(selectedMonth === month ? null : month);
    };

    const onFlowBarClick = (entry: { payload?: MonthlyPoint }) => {
        if (entry.payload?.month) {
            toggleMonth(entry.payload.month);
        }
    };

    const openCategory = (category: BreakdownCategory) => {
        const filter: Record<string, string | number | boolean> =
            category.id === null
                ? { uncategorized: true }
                : { category: category.id };

        if (selectedMonth !== null) {
            const [year, monthPart] = selectedMonth.split('-').map(Number);
            const from = new Date(Date.UTC(year, monthPart - 1, 1));
            const to = new Date(Date.UTC(year, monthPart, 0));
            filter.posted_between = [
                from.toISOString().slice(0, 10),
                to.toISOString().slice(0, 10),
            ].join(',');
        }

        router.visit(postingsIndex.url({ query: { filter } }));
    };

    const incomeCategories = (breakdown?.categories ?? []).filter(
        (category) => category.type === 'income',
    );
    const expenseCategories = (breakdown?.categories ?? []).filter(
        (category) => category.type === 'expense',
    );

    const breakdownHeading =
        selectedMonth !== null
            ? formatMonthLabel(selectedMonth)
            : 'Average per month';

    const resultPositive = summary.result >= 0;
    const hasBalance = balance !== null;
    const heroValue = hasBalance ? balance : summary.result;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-3">
                        <div className="flex flex-col gap-1">
                            <span className="text-sm font-medium text-muted-foreground">
                                {hasBalance ? 'Balance' : 'Result'}
                            </span>
                            <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                                <span className="text-4xl font-semibold tracking-tight tabular-nums sm:text-5xl">
                                    {formatMoney(heroValue, primaryCurrency)}
                                </span>
                                <span
                                    className={cn(
                                        'inline-flex items-baseline rounded-full px-2.5 py-0.5 text-sm font-medium tabular-nums',
                                        resultPositive
                                            ? 'bg-chart-income/12 text-chart-income'
                                            : 'bg-destructive/12 text-destructive',
                                    )}
                                >
                                    {formatSignedMoney(
                                        summary.result,
                                        primaryCurrency,
                                    )}
                                </span>
                            </div>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {formatMonth(period.start)} –{' '}
                            {formatMonth(period.end)}
                            {hasBalance && (
                                <>
                                    {' · '}
                                    {resultPositive
                                        ? 'result this period'
                                        : 'shortfall this period'}
                                </>
                            )}
                        </p>
                    </div>
                    <Select
                        value={period.selected}
                        onValueChange={changePeriod}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {period.options.map((option) => (
                                <SelectItem key={option} value={option}>
                                    {periodLabel(option)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <FlowFigure
                        label="In"
                        value={summary.in}
                        currency={primaryCurrency}
                        direction="in"
                    />
                    <FlowFigure
                        label="Out"
                        value={summary.out}
                        currency={primaryCurrency}
                        direction="out"
                    />
                </div>

                <AllocationBar summary={summary} currency={primaryCurrency} />

                <Card>
                    <CardHeader>
                        <CardTitle>Monthly flow</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="h-80">
                            <ResponsiveContainer
                                width="100%"
                                height={320}
                                minWidth={0}
                                minHeight={320}
                            >
                                <ComposedChart
                                    data={flowData}
                                    stackOffset="sign"
                                    barCategoryGap="28%"
                                    onClick={(state: unknown) => {
                                        const label = (
                                            state as {
                                                activePayload?: Array<{
                                                    payload?: MonthlyPoint;
                                                }>;
                                            }
                                        )?.activePayload?.[0]?.payload?.month;

                                        if (label) {
                                            toggleMonth(label);
                                        }
                                    }}
                                    style={{
                                        cursor: 'pointer',
                                        outline: 'none',
                                    }}
                                >
                                    <CartesianGrid
                                        vertical={false}
                                        stroke="var(--border)"
                                    />
                                    <XAxis
                                        dataKey="label"
                                        tabIndex={-1}
                                        tickLine={false}
                                        axisLine={false}
                                        tickMargin={10}
                                        tick={mutedTick}
                                    />
                                    <YAxis
                                        tickFormatter={formatAxis}
                                        tabIndex={-1}
                                        tickLine={false}
                                        axisLine={false}
                                        width={44}
                                        tick={mutedTick}
                                        padding={{ top: 16, bottom: 8 }}
                                    />
                                    <ReferenceLine
                                        y={0}
                                        stroke="var(--muted-foreground)"
                                        strokeOpacity={0.35}
                                    />
                                    <Tooltip
                                        content={(props: unknown) =>
                                            flowTooltip(props, primaryCurrency)
                                        }
                                        cursor={{ fill: 'var(--muted)' }}
                                    />
                                    <Bar
                                        dataKey="income"
                                        name="Income"
                                        fill="var(--color-chart-income)"
                                        stackId="flow"
                                        radius={[4, 4, 0, 0]}
                                        onClick={onFlowBarClick}
                                    >
                                        {flowData.map((entry) => (
                                            <Cell
                                                key={`income-${entry.month}`}
                                                opacity={cellOpacity(
                                                    entry.month,
                                                    lastCompletedMonth,
                                                    selectedMonth,
                                                )}
                                            />
                                        ))}
                                    </Bar>
                                    {BUCKETS.map((bucket, index) => (
                                        <Bar
                                            key={bucket.key}
                                            dataKey={`${bucket.key}Stacked`}
                                            name={bucket.label}
                                            fill={bucket.cssVar}
                                            stackId="flow"
                                            radius={
                                                index === BUCKETS.length - 1
                                                    ? [0, 0, 4, 4]
                                                    : undefined
                                            }
                                            onClick={onFlowBarClick}
                                        >
                                            {flowData.map((entry) => (
                                                <Cell
                                                    key={`${bucket.key}-${entry.month}`}
                                                    opacity={cellOpacity(
                                                        entry.month,
                                                        lastCompletedMonth,
                                                        selectedMonth,
                                                    )}
                                                />
                                            ))}
                                        </Bar>
                                    ))}
                                    <Line
                                        type="monotone"
                                        dataKey="result"
                                        name="Result"
                                        stroke="var(--color-chart-result)"
                                        strokeWidth={2}
                                        dot={{ r: 3 }}
                                        activeDot={{ r: 4 }}
                                    />
                                </ComposedChart>
                            </ResponsiveContainer>
                        </div>
                        <p className="mt-2 text-xs text-muted-foreground">
                            Click a month to break it down below.{' '}
                            {selectedMonth !== null && (
                                <button
                                    type="button"
                                    className="underline underline-offset-2"
                                    onClick={() => reloadBreakdown(null)}
                                >
                                    Show average
                                </button>
                            )}
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-4">
                        <CardTitle>
                            Breakdown &mdash; {breakdownHeading}
                        </CardTitle>
                        {selectedMonth !== null && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => reloadBreakdown(null)}
                            >
                                Back to average
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        {breakdown === undefined ? (
                            <div className="grid gap-6 md:grid-cols-2">
                                <BreakdownSkeleton />
                                <BreakdownSkeleton />
                            </div>
                        ) : (
                            <div className="grid gap-6 md:grid-cols-2">
                                <BreakdownGroup
                                    title="In"
                                    categories={incomeCategories}
                                    color="var(--color-chart-income)"
                                    onSelect={openCategory}
                                    currency={primaryCurrency}
                                />
                                <BreakdownGroup
                                    title="Out"
                                    categories={expenseCategories}
                                    color="var(--color-chart-bills)"
                                    onSelect={openCategory}
                                    currency={primaryCurrency}
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function cellOpacity(
    month: string,
    lastCompletedMonth: string,
    selectedMonth: string | null,
): number {
    if (month > lastCompletedMonth) {
        return 0.45;
    }

    if (selectedMonth !== null && selectedMonth !== month) {
        return 0.35;
    }

    return 1;
}

/**
 * A single headline flow figure. "Out" is shown as a signed negative so the
 * page reads as In minus Out at a glance.
 */
function FlowFigure({
    label,
    value,
    currency,
    direction,
}: {
    label: string;
    value: number;
    currency: string | null;
    direction: 'in' | 'out';
}) {
    const display =
        direction === 'out' && value !== 0
            ? formatSignedMoney(-value, currency)
            : value < 0
              ? formatSignedMoney(value, currency)
              : formatMoney(value, currency);

    return (
        <div className="rounded-xl border bg-card p-4">
            <div className="flex items-center gap-2">
                <span
                    className={cn(
                        'size-2 shrink-0 rounded-full',
                        direction === 'in'
                            ? 'bg-chart-income'
                            : 'bg-muted-foreground',
                    )}
                    aria-hidden
                />
                <span className="text-sm font-medium text-muted-foreground">
                    {label}
                </span>
            </div>
            <div className="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums">
                {display}
            </div>
        </div>
    );
}

function BreakdownGroup({
    title,
    categories,
    color,
    onSelect,
    currency,
}: {
    title: string;
    categories: BreakdownCategory[];
    color: string;
    onSelect: (category: BreakdownCategory) => void;
    currency: string | null;
}) {
    const height = Math.max(160, categories.length * 40 + 24);

    return (
        <div>
            <h3 className="mb-2 text-sm font-medium text-muted-foreground">
                {title}
            </h3>
            {categories.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">
                    No data for this period.
                </p>
            ) : (
                <div style={{ height }}>
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart
                            data={categories}
                            layout="vertical"
                            margin={{ left: 8, right: 72, top: 4, bottom: 4 }}
                        >
                            <XAxis type="number" hide />
                            <YAxis
                                dataKey="name"
                                type="category"
                                width={130}
                                tabIndex={-1}
                                tickLine={false}
                                axisLine={false}
                                tick={mutedTick}
                            />
                            <Tooltip
                                content={(props: unknown) =>
                                    breakdownTooltip(props, currency)
                                }
                                cursor={{ fill: 'var(--muted)' }}
                                position={{ y: 0 }}
                                allowEscapeViewBox={{ x: false, y: true }}
                                wrapperStyle={{ zIndex: 20 }}
                            />
                            <Bar
                                dataKey="total"
                                name={title}
                                fill={color}
                                radius={[0, 4, 4, 0]}
                                barSize={22}
                                cursor="pointer"
                                onClick={(entry: unknown) => {
                                    const payload = (
                                        entry as {
                                            payload?: BreakdownCategory;
                                        }
                                    ).payload;

                                    if (payload) {
                                        onSelect(payload);
                                    }
                                }}
                            >
                                <LabelList
                                    dataKey="total"
                                    position="right"
                                    fill="var(--muted-foreground)"
                                    fontSize={12}
                                    formatter={(value: unknown) =>
                                        formatMoney(
                                            Number(value ?? 0),
                                            currency,
                                        )
                                    }
                                />
                            </Bar>
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}
        </div>
    );
}

function BreakdownSkeleton() {
    return (
        <div className="space-y-3">
            <div className="h-4 w-20 animate-pulse rounded bg-muted" />
            {Array.from({ length: 4 }).map((_, index) => (
                <div
                    key={index}
                    className="h-8 animate-pulse rounded bg-muted"
                    style={{ width: `${90 - index * 15}%` }}
                />
            ))}
        </div>
    );
}

/**
 * Splits total spending (summary.out) into its five outgoing buckets as one
 * proportional bar. Zero buckets are hidden from the legend, and Uncategorized
 * is drawn muted with a dashed edge to flag categorization debt.
 */
function AllocationBar({
    summary,
    currency,
}: {
    summary: Summary;
    currency: string | null;
}) {
    const out = summary.out;

    const segments = BUCKETS.map((bucket) => ({
        ...bucket,
        value: Math.max(summary[bucket.key], 0),
    })).filter((segment) => segment.value > 0);

    if (out <= 0 || segments.length === 0) {
        return (
            <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                Nothing went out this period.
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div className="flex items-center justify-between text-sm">
                <span className="font-medium text-muted-foreground">
                    Where it went
                </span>
                <span className="text-muted-foreground tabular-nums">
                    {formatMoney(out, currency)} out
                </span>
            </div>
            <div className="flex h-3 w-full gap-[3px] overflow-hidden">
                {segments.map((segment) => (
                    <div
                        key={segment.key}
                        className={cn(
                            'h-full rounded-full',
                            segment.colorClass,
                            segment.key === 'uncategorized' &&
                                'opacity-60 ring-1 ring-foreground/20 ring-inset',
                        )}
                        style={{
                            width: `${(segment.value / out) * 100}%`,
                        }}
                        title={`${segment.label} · ${formatMoney(segment.value, currency)}`}
                    />
                ))}
            </div>
            <div className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                {segments.map((segment) => {
                    const isDebt = segment.key === 'uncategorized';

                    return (
                        <span
                            key={segment.key}
                            className={cn(
                                'flex items-center gap-1.5',
                                isDebt && 'rounded-md bg-muted px-2 py-0.5',
                            )}
                        >
                            <span
                                className={cn(
                                    'size-2 shrink-0 rounded-full',
                                    segment.colorClass,
                                    isDebt && 'opacity-60',
                                )}
                                aria-hidden
                            />
                            <span
                                className={cn(
                                    'text-muted-foreground',
                                    isDebt && 'font-medium text-foreground',
                                )}
                            >
                                {segment.label}
                            </span>
                            <span className="font-medium tabular-nums">
                                {formatNumber((segment.value / out) * 100, {
                                    maximumFractionDigits: 1,
                                })}
                                %
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                {formatMoney(segment.value, currency)}
                            </span>
                        </span>
                    );
                })}
            </div>
        </div>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
