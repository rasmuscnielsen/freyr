import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState } from 'react';
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
import { formatDate, formatMonth } from '@/lib/dates';
import { formatMoney as formatSharedMoney, formatNumber } from '@/lib/money';
import { useRegion } from '@/lib/region';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as postingsIndex, retryConversions } from '@/routes/postings';

type Period = {
    selected: string;
    interval: 'month' | 'year';
    months: number;
    units: number;
    start: string;
    end: string;
    options: string[];
};

type Coverage = {
    eligible: number;
    converted: number;
    missing: number;
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
    coverage: Coverage;
};

type FlowPoint = {
    // Series key: 'YYYY-MM' on monthly periods, 'YYYY' on all-years.
    period: string;
    label: string;
    complete: boolean;
    income: number;
    bills: number;
    consumption: number;
    investments: number;
    savings: number;
    uncategorized: number;
    result: number;
    missing: number;
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
    missing: number;
    topSubcategories: TopSubcategory[];
    topPostings: TopPosting[];
};

type Breakdown = {
    focus: string | null;
    categories: BreakdownCategory[];
};

type Balance = {
    total: number | null;
    currency: string | null;
    missing_accounts: number;
    valuation_date: string | null;
};

type DashboardProps = {
    period: Period;
    summary: Summary;
    series: FlowPoint[];
    breakdown?: Breakdown;
    balance: Balance;
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

function formatMoney(
    value: number,
    currency: string | null,
    locale?: string,
): string {
    return formatSharedMoney(value, currency, locale, {
        maximumFractionDigits: 0,
    });
}

/**
 * Formats a magnitude with an explicit leading sign, e.g. "+ DKK 50.123" or
 * "− DKK 12.000". Uses a true minus sign for typographic balance.
 */
function formatSignedMoney(
    value: number,
    currency: string | null,
    locale?: string,
): string {
    const rounded = Math.round(value);
    const sign = rounded < 0 ? '−' : '+';

    return `${sign} ${formatMoney(Math.abs(rounded), currency, locale)}`;
}

function formatAxis(value: number, locale?: string): string {
    return formatNumber(value, locale, {
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

function formatMonthLabel(month: string, locale?: string): string {
    const [year, monthPart] = month.split('-');
    const date = new Date(Number(year), Number(monthPart) - 1, 1);

    return new Intl.DateTimeFormat(locale, {
        month: 'long',
        year: 'numeric',
    }).format(date);
}

const mutedTick = { fill: 'var(--muted-foreground)', fontSize: 12 };

// Coarse pointers (touch) can't hover, so the breakdown rows become a
// two-tap interaction instead of hover-reveal + click-through.
const coarsePointerMql =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia('(hover: none)');

function periodLabel(option: string): string {
    if (option === '12m') {
        return '12 months';
    }

    if (option === 'years') {
        return 'All years';
    }

    return option;
}

/**
 * Tooltip rows mirror the allocation legend: color dot, muted label, bold
 * share of the month's outgoing total (buckets only), muted amount.
 */
function flowTooltip(
    props: unknown,
    currency: string | null,
    points: FlowPoint[],
    interval: Period['interval'],
    locale?: string,
) {
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
    const point = points.find((item) => item.label === label);
    const missing = point?.missing ?? 0;
    const unit = interval === 'year' ? 'year' : 'month';

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
                                        {formatNumber(share, locale, {
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
                                          locale,
                                      )
                                    : formatMoney(
                                          Number(item.value),
                                          currency,
                                          locale,
                                      )}
                            </span>
                        </Fragment>
                    );
                })}
            </div>
            {missing > 0 && (
                <div className="mt-2 border-t pt-2 text-muted-foreground">
                    {missing} {missing === 1 ? 'posting has' : 'postings have'}{' '}
                    no reporting amount — {unit} is partial.
                </div>
            )}
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
    locale,
    onSelect,
}: {
    category: BreakdownCategory;
    currency: string | null;
    locale?: string;
    onSelect: (category: BreakdownCategory) => void;
}) {
    return (
        <div className="text-sm">
            <div className="mb-2 flex items-center justify-between gap-6 font-medium">
                <span>{category.name}</span>
                <span className="tabular-nums">
                    {formatMoney(category.total, currency, locale)}
                </span>
            </div>
            {category.missing > 0 && (
                <div className="mb-2 text-muted-foreground">
                    {category.missing}{' '}
                    {category.missing === 1 ? 'posting has' : 'postings have'}{' '}
                    no reporting amount — total is partial.
                </div>
            )}
            {category.topSubcategories.length > 0 && (
                <div className="space-y-1 border-t pt-2 text-muted-foreground">
                    {category.topSubcategories.map((subcategory) => (
                        <div
                            key={subcategory.name}
                            className="flex items-center justify-between gap-6"
                        >
                            <span>{subcategory.name}</span>
                            <span className="font-medium text-foreground tabular-nums">
                                {formatMoney(
                                    subcategory.amount,
                                    currency,
                                    locale,
                                )}
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
                                    {formatMoney(
                                        posting.amount,
                                        currency,
                                        locale,
                                    )}
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
    series,
    breakdown,
    balance,
    primaryCurrency,
}: DashboardProps) {
    const { locale } = useRegion();
    const [selectedPeriod, setSelectedPeriod] = useState<string | null>(
        breakdown?.focus ?? null,
    );
    const [breakdownLoading, setBreakdownLoading] = useState(false);

    const isYearly = period.interval === 'year';
    const unit = isYearly ? 'year' : 'month';

    // Bucket totals arrive as positive magnitudes; outgoing buckets are drawn
    // below the axis.
    const flowData = series.map((point) => ({
        ...point,
        billsStacked: -Math.abs(point.bills),
        consumptionStacked: -Math.abs(point.consumption),
        investmentsStacked: -Math.abs(point.investments),
        savingsStacked: -Math.abs(point.savings),
        uncategorizedStacked: -Math.abs(point.uncategorized),
    }));

    // The Y scale fits the completed periods only. The incomplete current
    // period is a dimmed preview and a single early-period outlier would
    // otherwise flatten the real data; its bars clip at the top of the
    // chart instead.
    const completedFlow = flowData.filter((point) => point.complete);
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
        setSelectedPeriod(null);
        router.get(
            dashboard.url({ query: { period: value } }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    const reloadBreakdown = (focus: string | null) => {
        setSelectedPeriod(focus);
        router.reload({
            only: ['breakdown'],
            data: { focus: focus ?? undefined },
            onStart: () => setBreakdownLoading(true),
            onFinish: () => setBreakdownLoading(false),
        });
    };

    const togglePeriod = (key: string) => {
        reloadBreakdown(selectedPeriod === key ? null : key);
    };

    const openCategory = (category: BreakdownCategory) => {
        const filter: Record<string, string | number | boolean> =
            category.id === null
                ? { uncategorized: true }
                : { category: category.id };

        if (selectedPeriod !== null) {
            // A single series period is selected: window to that month's or
            // year's range.
            if (isYearly) {
                filter.posted_between = [
                    `${selectedPeriod}-01-01`,
                    `${selectedPeriod}-12-31`,
                ].join(',');
            } else {
                const [year, monthPart] = selectedPeriod
                    .split('-')
                    .map(Number);
                const from = new Date(Date.UTC(year, monthPart - 1, 1));
                const to = new Date(Date.UTC(year, monthPart, 0));
                filter.posted_between = [
                    from.toISOString().slice(0, 10),
                    to.toISOString().slice(0, 10),
                ].join(',');
            }
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
        selectedPeriod !== null
            ? isYearly
                ? selectedPeriod
                : formatMonthLabel(selectedPeriod, locale)
            : `Average per ${unit}`;

    const resultPositive = summary.result >= 0;
    const hasBalance = balance.total !== null;
    const heroValue = hasBalance ? (balance.total ?? 0) : summary.result;
    const missingCount = summary.coverage.missing;
    // The headline metrics read as per-period averages — per month on
    // monthly periods, per year on all years; the hero delta stays the
    // window total.
    const unitsInPeriod = Math.max(period.units, 1 / 12);

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
                                    {formatMoney(
                                        heroValue,
                                        primaryCurrency,
                                        locale,
                                    )}
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
                                        locale,
                                    )}
                                </span>
                            </div>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {formatMonth(period.start, locale)} –{' '}
                            {formatMonth(period.end, locale)}{' '}
                            {isYearly ? 'averaged per year' : 'averages'}
                        </p>
                        {balance.missing_accounts > 0 && (
                            <p className="text-sm text-muted-foreground">
                                Excludes {balance.missing_accounts}{' '}
                                {balance.missing_accounts === 1
                                    ? 'account'
                                    : 'accounts'}{' '}
                                without a known balance or exchange rate
                                {balance.valuation_date !== null && (
                                    <>
                                        {' '}
                                        · rates as of{' '}
                                        {formatDate(
                                            balance.valuation_date,
                                            locale,
                                        )}
                                    </>
                                )}
                            </p>
                        )}
                        {missingCount > 0 && (
                            <p className="text-sm text-muted-foreground">
                                {formatNumber(missingCount, locale)}{' '}
                                {missingCount === 1
                                    ? 'posting is'
                                    : 'postings are'}{' '}
                                missing reporting amounts — known subtotals are
                                partial.{' '}
                                <button
                                    type="button"
                                    className="underline underline-offset-2"
                                    onClick={() =>
                                        router.post(retryConversions.url())
                                    }
                                >
                                    Retry now
                                </button>
                            </p>
                        )}
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
                            value={summary.in / unitsInPeriod}
                            currency={primaryCurrency}
                            locale={locale}
                            direction="in"
                        />
                        <FlowStat
                            label="Out"
                            value={summary.out / unitsInPeriod}
                            currency={primaryCurrency}
                            locale={locale}
                            direction="out"
                        />
                    </div>

                    <AllocationBar
                        summary={summary}
                        units={unitsInPeriod}
                        currency={primaryCurrency}
                        locale={locale}
                        period={period}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {isYearly ? 'Yearly flow' : 'Monthly flow'}
                        </CardTitle>
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
                                    // Recharts 3 click state has no
                                    // activePayload — activeTooltipIndex is
                                    // the snapped column index, so a click
                                    // anywhere the tooltip ruler shows (bar,
                                    // line, or empty band space) toggles the
                                    // period exactly once.
                                    onClick={(state: unknown) => {
                                        const index = (
                                            state as {
                                                activeTooltipIndex?:
                                                    | number
                                                    | string;
                                            }
                                        )?.activeTooltipIndex;
                                        const key =
                                            index !== undefined
                                                ? flowData[Number(index)]
                                                      ?.period
                                                : undefined;

                                        if (key) {
                                            togglePeriod(key);
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
                                        tickFormatter={(value: number) =>
                                            formatAxis(value, locale)
                                        }
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
                                            flowTooltip(
                                                props,
                                                primaryCurrency,
                                                flowData,
                                                period.interval,
                                                locale,
                                            )
                                        }
                                        cursor={{ fill: 'var(--muted)' }}
                                        // The rendered tooltip must not
                                        // intercept clicks on the bars
                                        // beneath it.
                                        wrapperStyle={{ pointerEvents: 'none' }}
                                    />
                                    <Bar
                                        dataKey="income"
                                        name="Income"
                                        fill="var(--color-chart-income)"
                                        stackId="flow"
                                        radius={[4, 4, 0, 0]}
                                    >
                                        {flowData.map((entry) => (
                                            <Cell
                                                key={`income-${entry.period}`}
                                                opacity={cellOpacity(
                                                    entry.period,
                                                    entry.complete,
                                                    selectedPeriod,
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
                                        >
                                            {flowData.map((entry) => (
                                                <Cell
                                                    key={`${bucket.key}-${entry.period}`}
                                                    opacity={cellOpacity(
                                                        entry.period,
                                                        entry.complete,
                                                        selectedPeriod,
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
                            Click a {unit} to break it down below.{' '}
                            {selectedPeriod !== null && (
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
                        {selectedPeriod !== null && (
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
                            // The stale rows stay mounted while the focused
                            // period loads — dimmed like an unselected flow
                            // bar — then the bars slide to their new widths.
                            <div
                                className={cn(
                                    'flex flex-col gap-8 transition-opacity duration-200',
                                    breakdownLoading &&
                                        'pointer-events-none opacity-40',
                                )}
                            >
                                <BreakdownGroup
                                    title="In"
                                    categories={incomeCategories}
                                    onSelect={openCategory}
                                    currency={primaryCurrency}
                                    locale={locale}
                                />
                                <BreakdownGroup
                                    title="Out"
                                    categories={expenseCategories}
                                    onSelect={openCategory}
                                    currency={primaryCurrency}
                                    locale={locale}
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
    period: string,
    complete: boolean,
    selectedPeriod: string | null,
): number {
    if (selectedPeriod !== null) {
        return selectedPeriod === period ? 1 : 0.35;
    }

    // The in-progress period is a dimmed preview until it is selected.
    if (!complete) {
        return 0.45;
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
    locale,
    direction,
}: {
    label: string;
    value: number;
    currency: string | null;
    locale?: string;
    direction: 'in' | 'out';
}) {
    const display =
        direction === 'out' && value !== 0
            ? formatSignedMoney(-value, currency, locale)
            : value < 0
              ? formatSignedMoney(value, currency, locale)
              : formatMoney(value, currency, locale);

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
    locale,
}: {
    title: string;
    categories: BreakdownCategory[];
    onSelect: (category: BreakdownCategory) => void;
    currency: string | null;
    locale?: string;
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
                        {formatMoney(groupTotal, currency, locale)}
                        {categories.some(
                            (category) => category.missing > 0,
                        ) && (
                            <span className="text-muted-foreground">
                                {' '}
                                (partial)
                            </span>
                        )}
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
                            locale={locale}
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
    locale,
}: {
    category: BreakdownCategory;
    pct: number;
    onSelect: (category: BreakdownCategory) => void;
    currency: string | null;
    locale?: string;
}) {
    const barColor =
        BUCKET_COLORS[category.bucket] ?? 'var(--color-chart-uncategorized)';
    // Where the pointer entered the row, so the detail card opens near the
    // cursor instead of pinned to the row's left edge covering the bars.
    const [anchorX, setAnchorX] = useState(0);
    const [open, setOpen] = useState(false);
    const rowRef = useRef<HTMLDivElement>(null);
    const lastTapAt = useRef(0);

    // Radix suppresses the trigger's emulated click on touch, so taps are
    // handled on pointerup: the first opens the detail card, the second (or
    // "View postings" inside it) navigates.
    const tap = () => {
        if (open) {
            onSelect(category);
        } else {
            setOpen(true);
        }
    };

    // A tap already handled on pointerup may still produce an emulated
    // click; ignore clicks arriving right after a touch so one tap isn't
    // read as two.
    const selectFromClick = () => {
        if (Date.now() - lastTapAt.current < 400) {
            return;
        }

        if (coarsePointerMql?.matches) {
            tap();

            return;
        }

        onSelect(category);
    };

    // Touch never fires pointerleave, so an open card dismisses on the next
    // tap outside the row or its portaled content.
    useEffect(() => {
        if (!open || !coarsePointerMql?.matches) {
            return;
        }

        const dismiss = (event: PointerEvent) => {
            const target = event.target;

            if (
                !(target instanceof Element) ||
                rowRef.current?.contains(target) ||
                target.closest('[data-slot="hover-card-content"]')
            ) {
                return;
            }

            setOpen(false);
        };

        document.addEventListener('pointerdown', dismiss, true);

        return () => document.removeEventListener('pointerdown', dismiss, true);
    }, [open]);

    return (
        <div
            ref={rowRef}
            className="group flex w-full cursor-pointer flex-col gap-1 py-1 text-left"
            onClick={selectFromClick}
            onPointerUp={(event) => {
                if (event.pointerType === 'touch') {
                    lastTapAt.current = Date.now();
                    tap();
                }
            }}
        >
            <button
                type="button"
                className="flex cursor-pointer items-center gap-1 self-start text-sm font-medium underline-offset-2 group-hover:underline"
                onClick={(event) => {
                    // The row's own handler covers pointer clicks; this button
                    // exists for keyboard and screen-reader access.
                    event.stopPropagation();
                    selectFromClick();
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
                <HoverCard
                    open={open}
                    onOpenChange={setOpen}
                    openDelay={100}
                    closeDelay={80}
                >
                    <HoverCardTrigger asChild>
                        <span
                            onMouseEnter={(event) =>
                                setAnchorX(
                                    event.clientX -
                                        event.currentTarget.getBoundingClientRect()
                                            .left,
                                )
                            }
                            className="h-5 min-w-0 shrink rounded-full transition-[width,opacity] duration-300 hover:opacity-90"
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
                            locale={locale}
                            onSelect={onSelect}
                        />
                    </HoverCardContent>
                </HoverCard>
                <span className="shrink-0 text-sm text-muted-foreground tabular-nums">
                    {formatMoney(category.total, currency, locale)}
                    {category.missing > 0 && (
                        <span> +{category.missing} missing</span>
                    )}
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
 * hidden; the Uncategorized entry links to the postings that need
 * categorizing so the debt is actionable, not just visible.
 */
function AllocationBar({
    summary,
    units,
    currency,
    locale,
    period,
}: {
    summary: Summary;
    units: number;
    currency: string | null;
    locale?: string;
    period: Period;
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
                        title={`${segment.label} · ${formatMoney(segment.value / units, currency, locale)}`}
                    />
                ))}
            </div>
            <div className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                {segments.map((segment) => {
                    const content = (
                        <>
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
                                {formatNumber(
                                    (segment.value / out) * 100,
                                    locale,
                                    {
                                        maximumFractionDigits: 1,
                                    },
                                )}
                                %
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                {formatMoney(
                                    segment.value / units,
                                    currency,
                                    locale,
                                )}
                            </span>
                        </>
                    );

                    if (segment.key === 'uncategorized') {
                        return (
                            <Link
                                key={segment.key}
                                href={postingsIndex.url({
                                    query: {
                                        filter: {
                                            uncategorized: true,
                                            posted_between: `${period.start},${period.end}`,
                                        },
                                    },
                                })}
                                title="Review uncategorized postings"
                                className="flex items-center gap-1.5 underline-offset-2 hover:underline"
                            >
                                {content}
                                <span
                                    className="text-muted-foreground"
                                    aria-hidden
                                >
                                    →
                                </span>
                            </Link>
                        );
                    }

                    return (
                        <span
                            key={segment.key}
                            className="flex items-center gap-1.5"
                        >
                            {content}
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
