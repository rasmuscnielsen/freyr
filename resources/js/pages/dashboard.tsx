import { Head, router } from '@inertiajs/react';
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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { index as postingsIndex } from '@/routes/postings';

type Period = {
    months: number;
    start: string;
    end: string;
    options: number[];
};

type Summary = {
    income: number;
    bills: number;
    consumption: number;
    expenses: number;
    savings: number;
    result: number;
    average_result: number;
    result_with_savings: number;
};

type MonthlyPoint = {
    month: string;
    label: string;
    income: number;
    bills: number;
    consumption: number;
    expenses: number;
    savings: number;
    result: number;
};

type SpendingCategory = {
    name: string;
    amount: number;
    total_amount: number;
    posting_count: number;
    category_id: number | null;
};

type DashboardProps = {
    period: Period;
    summary: Summary;
    monthly: MonthlyPoint[];
    spendingCategories: SpendingCategory[];
};

const money = new Intl.NumberFormat('da-DK', {
    style: 'currency',
    currency: 'DKK',
    maximumFractionDigits: 0,
});

function formatMoney(value: number): string {
    return money.format(value);
}

function chartTooltip(props: unknown) {
    const { active, payload, label } = props as {
        active?: boolean;
        payload?: Array<{ name: string; value: number; color?: string }>;
        label?: string;
    };

    if (!active || !payload?.length) {
        return null;
    }

    return (
        <div className="rounded-md border bg-background p-3 text-sm shadow-md">
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
                                {formatMoney(item.value)}
                            </span>
                        </div>
                    ))}
            </div>
        </div>
    );
}

export default function Dashboard({
    period,
    summary,
    monthly,
    spendingCategories,
}: DashboardProps) {
    const changePeriod = (months: string) => {
        router.get(
            dashboard.url({ query: { months } }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    const openCategory = (category: SpendingCategory) => {
        if (category.category_id === null) {
            router.visit(
                postingsIndex.url({
                    query: { filter: { uncategorized: true } },
                }),
            );

            return;
        }

        router.visit(
            postingsIndex.url({
                query: { filter: { category: category.category_id } },
            }),
        );
    };

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Income and expenses
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {period.start} to {period.end}
                        </p>
                    </div>
                    <Select
                        value={String(period.months)}
                        onValueChange={changePeriod}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {period.options.map((option) => (
                                <SelectItem key={option} value={String(option)}>
                                    {option} months
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="grid gap-4 md:grid-cols-5">
                    <SummaryCard label="Income" value={summary.income} />
                    <SummaryCard label="Bills" value={summary.bills} />
                    <SummaryCard
                        label="Consumption"
                        value={summary.consumption}
                    />
                    <SummaryCard label="Savings" value={summary.savings} />
                    <SummaryCard label="Result" value={summary.result} />
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
                                <ComposedChart data={monthly}>
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        vertical={false}
                                    />
                                    <XAxis dataKey="label" />
                                    <YAxis
                                        tickFormatter={(value: number) =>
                                            formatMoney(value).replace(
                                                'kr.',
                                                '',
                                            )
                                        }
                                    />
                                    <ReferenceLine
                                        y={0}
                                        stroke="var(--border)"
                                    />
                                    <Tooltip content={chartTooltip} />
                                    <Bar
                                        dataKey="income"
                                        name="Income"
                                        fill="var(--color-chart-2)"
                                        radius={[4, 4, 0, 0]}
                                    >
                                        {monthly.map((entry) => (
                                            <Cell
                                                key={`income-${entry.month}`}
                                                opacity={
                                                    entry.month >
                                                    period.end.slice(0, 7)
                                                        ? 0.45
                                                        : 1
                                                }
                                            />
                                        ))}
                                    </Bar>
                                    <Bar
                                        dataKey="bills"
                                        name="Bills"
                                        fill="var(--color-chart-1)"
                                        stackId="expenses"
                                        radius={[0, 0, 4, 4]}
                                    >
                                        {monthly.map((entry) => (
                                            <Cell
                                                key={`bills-${entry.month}`}
                                                opacity={
                                                    entry.month >
                                                    period.end.slice(0, 7)
                                                        ? 0.45
                                                        : 1
                                                }
                                            />
                                        ))}
                                    </Bar>
                                    <Bar
                                        dataKey="consumption"
                                        name="Consumption"
                                        fill="var(--color-chart-5)"
                                        stackId="expenses"
                                        radius={[0, 0, 4, 4]}
                                    >
                                        {monthly.map((entry) => (
                                            <Cell
                                                key={`consumption-${entry.month}`}
                                                opacity={
                                                    entry.month >
                                                    period.end.slice(0, 7)
                                                        ? 0.45
                                                        : 1
                                                }
                                            />
                                        ))}
                                    </Bar>
                                    <Line
                                        type="monotone"
                                        dataKey="result"
                                        name="Result"
                                        stroke="var(--foreground)"
                                        strokeWidth={2}
                                        dot={{ r: 4 }}
                                    />
                                </ComposedChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Where the money went</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="h-96">
                            <ResponsiveContainer
                                width="100%"
                                height={384}
                                minWidth={0}
                                minHeight={384}
                            >
                                <BarChart
                                    data={spendingCategories}
                                    layout="vertical"
                                    margin={{
                                        left: 24,
                                        right: 56,
                                        top: 8,
                                        bottom: 8,
                                    }}
                                >
                                    <CartesianGrid
                                        horizontal={false}
                                        strokeDasharray="3 3"
                                    />
                                    <XAxis
                                        type="number"
                                        tickFormatter={(value: number) =>
                                            formatMoney(value).replace(
                                                'kr.',
                                                '',
                                            )
                                        }
                                    />
                                    <YAxis
                                        dataKey="name"
                                        type="category"
                                        width={150}
                                    />
                                    <Tooltip content={chartTooltip} />
                                    <Bar
                                        dataKey="amount"
                                        name="Monthly average"
                                        fill="var(--color-chart-1)"
                                        radius={[0, 4, 4, 0]}
                                        cursor="pointer"
                                        onClick={(entry: unknown) => {
                                            const payload = (
                                                entry as {
                                                    payload?: SpendingCategory;
                                                }
                                            ).payload;

                                            if (payload) {
                                                openCategory(payload);
                                            }
                                        }}
                                    >
                                        <LabelList
                                            dataKey="amount"
                                            position="right"
                                            formatter={(value: unknown) =>
                                                formatMoney(Number(value ?? 0))
                                            }
                                        />
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function SummaryCard({ label, value }: { label: string; value: number }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                    {label}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-semibold">
                    {formatMoney(value)}
                </div>
            </CardContent>
        </Card>
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
