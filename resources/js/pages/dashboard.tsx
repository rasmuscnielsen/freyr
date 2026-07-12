import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import {
    Bar,
    CartesianGrid,
    Cell,
    ComposedChart,
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
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
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
    bucket: 'income' | BucketKey;
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

/**
 * Bar color per overview bucket, so the breakdown rows reuse the color
 * language established by the allocation bar and the monthly flow chart.
 */
const BUCKET_COLORS: Record<'income' | BucketKey, string> = {
    income: 'var(--color-chart-income)',
    ...(Object.fromEntries(
        BUCKETS.map((bucket) => [bucket.key, bucket.cssVar]),
    ) as Record<BucketKey, string>),
};

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

/**
 * Rounds a magnitude up to a "nice" axis bound (1 / 2 / 2.5 / 5 × 10ⁿ), so a
 * hand-picked Y domain still produces round tick values.
 */
function niceCeiling(value: number): number {
    if (value <= 0) {
        return 0;
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));
    const nice = [1, 2, 2.5, 5, 10].find(
        (factor) => value <= factor * magnitude,
    );

    return (nice ?? 10) * magnitude;
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

/**
 * Tooltip rows mirror the allocation legend: color dot, muted label, bold
 * share of the month's outgoing total (buckets only), muted amount.
 */
function flowTooltip(props: unknown, currency: string | null) {
    const { active, payload, label } = props as {
        active?: boolean;
        payload?: Array<{
            name: string;
            value: number;
            color?: string;
            dataKey?: string;
        }>;
        label?: string;
    };

    if (!active || !payload?.length) {
        return null;
    }

    const entries = payload.filter((item) => Number(item.value) !== 0);
    const isOutgoing = (item: { dataKey?: string }) =>
        String(item.dataKey).endsWith('Stacked');
    const outTotal = entries
        .filter(isOutgoing)
        .reduce((sum, item) => sum + Math.abs(Number(item.value)), 0);

    return (
        <div className="rounded-lg border bg-popover p-3 text-sm text-popover-foreground shadow-lg">
            <div className="mb-2 font-medium">{label}</div>
            <div className="grid grid-cols-[1fr_auto_auto] items-center gap-x-4 gap-y-1">
                {entries.map((item) => {
                    const outgoing = isOutgoing(item);
                    const share =
                        outgoing && outTotal > 0
                            ? (Math.abs(Number(item.value)) / outTotal) * 100
                            : null;

                    return (
                        <Fragment key={item.name}>
                            <span className="flex items-center gap-1.5">
                                <span
                                    className="size-2 shrink-0 rounded-full"
                                    style={{
                                        backgroundColor:
                                            item.color ??
                                            'var(--muted-foreground)',
                                    }}
                                    aria-hidden
                                />
                                <span className="text-muted-foreground">
                                    {item.name}
                                </span>
                            </span>
                            <span className="text-right font-medium tabular-nums">
                                {share !== null && (
                                    <>
                                        {formatNumber(share, {
                                            maximumFractionDigits: 1,
                                        })}
                                        %
                                    </>
                                )}
                            </span>
                            <span className="text-right text-muted-foreground tabular-nums">
                                {outgoing
                                    ? formatMoney(
                                          Math.abs(Number(item.value)),
                                          currency,
                                      )
                                    : formatMoney(Number(item.value), currency)}
                            </span>
                        </Fragment>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * The detail card shown when hovering a breakdown row. Mirrors the old recharts
 * tooltip (category header + total, top subcategories, largest postings) but is
 * itself hoverable so the footer "View postings" action stays clickable.
 */
function BreakdownDetail({
    category,
    currency,
    onSelect,
}: {
    category: BreakdownCategory;
    currency: string | null;
    onSelect: (category: BreakdownCategory) => void;
}) {
    return (
        <div className="text-sm">
            <div className="mb-2 flex items-center justify-between gap-6 font-medium">
                <span>{category.name}</span>
                <span className="tabular-nums">
                    {formatMoney(category.total, currency)}
                </span>
            </div>
            {category.topSubcategories.length > 0 && (
                <div className="space-y-1 border-t pt-2 text-muted-foreground">
                    {category.topSubcategories.map((subcategory) => (
                        <div
                            key={subcategory.name}
                            className="flex items-center justify-between gap-6"
                        >
                            <span>{subcategory.name}</span>
                            <span className="font-medium text-foreground tabular-nums">
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
                                <span className="max-w-44 min-w-0 truncate">
                                    {posting.description}
                                </span>
                                <span className="font-medium text-foreground tabular-nums">
                                    {formatMoney(posting.amount, currency)}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            <button
                type="button"
                onClick={() => onSelect(category)}
                className="mt-3 w-full border-t pt-2 text-left text-sm font-medium text-brand hover:underline"
            >
                View postings →
            </button>
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

    // The Y scale fits the completed months only. The incomplete current
    // month is a preview (dimmed, unclickable) and a single early-month
    // outlier would otherwise flatten a whole year of real data; its bars
    // clip at the top of the chart instead.
    const completedFlow = flowData.filter(
        (point) => point.month <= lastCompletedMonth,
    );
    const domainSource = completedFlow.length > 0 ? completedFlow : flowData;
    const flowMax = Math.max(
        ...domainSource.map((point) => Math.max(point.income, point.result)),
        0,
    );
    const flowMin = Math.min(
        ...domainSource.map((point) =>
            Math.min(
                point.billsStacked +
                    point.consumptionStacked +
                    point.investmentsStacked +
                    point.savingsStacked +
                    point.uncategorizedStacked,
                point.result,
            ),
        ),
        0,
    );
    const flowDomain: [number, number] = [
        -niceCeiling(-flowMin),
        niceCeiling(flowMax),
    ];

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
            // A single month is selected: window to that month's range.
            const [year, monthPart] = selectedMonth.split('-').map(Number);
            const from = new Date(Date.UTC(year, monthPart - 1, 1));
            const to = new Date(Date.UTC(year, monthPart, 0));
            filter.posted_between = [
                from.toISOString().slice(0, 10),
                to.toISOString().slice(0, 10),
            ].join(',');
        } else {
            // Averaging across the period: carry the full period range so the
            // postings list matches the categories that fed the average.
            filter.posted_between = [period.start, period.end].join(',');
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
    // The headline metrics read as per-month averages; the hero delta stays
    // the period total.
    const monthsInPeriod = Math.max(period.months, 1);

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
                            {formatMonth(period.end)} averages
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

                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                        <FlowStat
                            label="In"
                            value={summary.in / monthsInPeriod}
                            currency={primaryCurrency}
                            direction="in"
                        />
                        <FlowStat
                            label="Out"
                            value={summary.out / monthsInPeriod}
                            currency={primaryCurrency}
                            direction="out"
                        />
                    </div>

                    <AllocationBar
                        summary={summary}
                        months={monthsInPeriod}
                        currency={primaryCurrency}
                    />
                </div>

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
                                        domain={flowDomain}
                                        allowDataOverflow
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
                                            // Stacked values are negative, which flips
                                            // recharts' radius corners — [4,4,0,0] rounds
                                            // the visual bottom here.
                                            radius={
                                                index === BUCKETS.length - 1
                                                    ? [4, 4, 0, 0]
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
                            <div className="flex flex-col gap-8">
                                <BreakdownSkeleton />
                                <BreakdownSkeleton />
                            </div>
                        ) : (
                            <div className="flex flex-col gap-8">
                                <BreakdownGroup
                                    title="In"
                                    categories={incomeCategories}
                                    onSelect={openCategory}
                                    currency={primaryCurrency}
                                />
                                <BreakdownGroup
                                    title="Out"
                                    categories={expenseCategories}
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
 * A single inline flow stat: colour dot, muted label, and the value. "Out" is
 * shown as a signed negative so the row reads as In minus Out at a glance.
 */
function FlowStat({
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
        <span className="flex items-center gap-2">
            <span
                className={cn(
                    'size-2 shrink-0 rounded-full',
                    direction === 'in'
                        ? 'bg-chart-income'
                        : 'bg-muted-foreground',
                )}
                aria-hidden
            />
            <span className="text-muted-foreground">{label}</span>
            <span className="font-medium tabular-nums">{display}</span>
        </span>
    );
}

function BreakdownGroup({
    title,
    categories,
    onSelect,
    currency,
}: {
    title: string;
    categories: BreakdownCategory[];
    onSelect: (category: BreakdownCategory) => void;
    currency: string | null;
}) {
    // Bars scale to the group's largest category. The longest bar fills nearly
    // the whole track, leaving room for its trailing amount to sit flush.
    const maxTotal = categories.reduce(
        (max, category) => Math.max(max, category.total),
        0,
    );
    const groupTotal = categories.reduce(
        (sum, category) => sum + category.total,
        0,
    );

    return (
        <div>
            <div className="mb-3 flex items-center justify-between">
                <h3 className="text-sm font-medium text-muted-foreground">
                    {title}
                </h3>
                {categories.length > 0 && (
                    <span className="text-sm font-medium tabular-nums">
                        {formatMoney(groupTotal, currency)}
                    </span>
                )}
            </div>
            {categories.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">
                    No data for this period.
                </p>
            ) : (
                <div className="flex flex-col gap-3">
                    {categories.map((category) => (
                        <BreakdownRow
                            key={category.id ?? `uncategorized-${title}`}
                            category={category}
                            pct={
                                maxTotal > 0
                                    ? (category.total / maxTotal) * 100
                                    : 0
                            }
                            onSelect={onSelect}
                            currency={currency}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

/**
 * One Spiir-style breakdown row: the category label sits above the bar, the
 * amount trails the bar's end. The whole row clicks through to the filtered
 * postings list; hovering the bar reveals a detail card anchored near the
 * cursor with subcategories and largest postings.
 */
function BreakdownRow({
    category,
    pct,
    onSelect,
    currency,
}: {
    category: BreakdownCategory;
    pct: number;
    onSelect: (category: BreakdownCategory) => void;
    currency: string | null;
}) {
    const barColor =
        BUCKET_COLORS[category.bucket] ?? 'var(--color-chart-uncategorized)';
    // Where the pointer entered the row, so the detail card opens near the
    // cursor instead of pinned to the row's left edge covering the bars.
    const [anchorX, setAnchorX] = useState(0);

    return (
        <div
            className="group flex w-full cursor-pointer flex-col gap-1 py-1 text-left"
            onClick={() => onSelect(category)}
        >
            <button
                type="button"
                className="flex cursor-pointer items-center gap-1 self-start text-sm font-medium underline-offset-2 group-hover:underline"
                onClick={(event) => {
                    // The row's own handler covers pointer clicks; this button
                    // exists for keyboard and screen-reader access.
                    event.stopPropagation();
                    onSelect(category);
                }}
            >
                {category.name}
                <span
                    className="text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                    aria-hidden
                >
                    →
                </span>
            </button>
            <span className="flex items-center gap-3">
                <HoverCard openDelay={100} closeDelay={80}>
                    <HoverCardTrigger asChild>
                        <span
                            onMouseEnter={(event) =>
                                setAnchorX(
                                    event.clientX -
                                        event.currentTarget.getBoundingClientRect()
                                            .left,
                                )
                            }
                            className="h-5 min-w-0 shrink rounded-full transition-opacity hover:opacity-90"
                            style={{
                                width: `${Math.max(pct, 2)}%`,
                                backgroundColor: barColor,
                            }}
                        />
                    </HoverCardTrigger>
                    <HoverCardContent
                        align="start"
                        alignOffset={anchorX}
                        className="w-72"
                    >
                        <BreakdownDetail
                            category={category}
                            currency={currency}
                            onSelect={onSelect}
                        />
                    </HoverCardContent>
                </HoverCard>
                <span className="shrink-0 text-sm text-muted-foreground tabular-nums">
                    {formatMoney(category.total, currency)}
                </span>
            </span>
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
 * slim proportional bar with a wrapping legend. Legend amounts are per-month
 * averages (shares stay ratios of the period totals). Zero buckets are
 * hidden; the Uncategorized segment relies on its muted gray token to read
 * as categorization debt.
 */
function AllocationBar({
    summary,
    months,
    currency,
}: {
    summary: Summary;
    months: number;
    currency: string | null;
}) {
    const out = summary.out;

    const segments = BUCKETS.map((bucket) => ({
        ...bucket,
        value: Math.max(summary[bucket.key], 0),
    })).filter((segment) => segment.value > 0);

    if (out <= 0 || segments.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Nothing went out this period.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="flex h-2.5 w-full gap-[3px] overflow-hidden">
                {segments.map((segment) => (
                    <div
                        key={segment.key}
                        className={cn(
                            'h-full rounded-full',
                            segment.colorClass,
                        )}
                        style={{
                            width: `${(segment.value / out) * 100}%`,
                        }}
                        title={`${segment.label} · ${formatMoney(segment.value / months, currency)}`}
                    />
                ))}
            </div>
            <div className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                {segments.map((segment) => {
                    return (
                        <span
                            key={segment.key}
                            className="flex items-center gap-1.5"
                        >
                            <span
                                className={cn(
                                    'size-2 shrink-0 rounded-full',
                                    segment.colorClass,
                                )}
                                aria-hidden
                            />
                            <span className="text-muted-foreground">
                                {segment.label}
                            </span>
                            <span className="font-medium tabular-nums">
                                {formatNumber((segment.value / out) * 100, {
                                    maximumFractionDigits: 1,
                                })}
                                %
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                {formatMoney(segment.value / months, currency)}
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
