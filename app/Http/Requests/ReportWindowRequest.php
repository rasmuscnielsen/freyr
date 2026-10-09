<?php

namespace App\Http\Requests;

use App\Enums\Interval;
use App\Models\Posting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared input for the /api/reports aggregate endpoints: an optional
 * `interval` (month|year, default month) and a `from`/`to` window given as
 * `YYYY`, `YYYY-MM` or `YYYY-MM-DD`. Shorthand bounds widen to the whole
 * period — `to=2024` means through the end of 2024.
 */
class ReportWindowRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'interval' => ['sometimes', 'string', Rule::enum(Interval::class)],
            'from' => ['sometimes', 'string', 'regex:/^\d{4}(-\d{2})?(-\d{2})?$/'],
            'to' => ['sometimes', 'string', 'regex:/^\d{4}(-\d{2})?(-\d{2})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->parseBoundary($this->query('from'), false);
            $to = $this->parseBoundary($this->query('to'), true);

            if ($this->query('from') !== null && $from === null) {
                $validator->errors()->add('from', 'The from field must be a valid date.');
            }

            if ($this->query('to') !== null && $to === null) {
                $validator->errors()->add('to', 'The to field must be a valid date.');
            }

            if ($from !== null && $to !== null && $to->lt($from)) {
                $validator->errors()->add('to', 'The to field must not be before the from field.');
            }
        });
    }

    public function reportInterval(): Interval
    {
        return Interval::tryFrom((string) $this->query('interval', '')) ?? Interval::Month;
    }

    /**
     * The requested window, defaulting to the user's full data range: from
     * their earliest posting to today.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(User $user): array
    {
        $from = $this->parseBoundary($this->query('from'), false);
        $to = $this->parseBoundary($this->query('to'), true);

        if ($from === null) {
            $earliest = Posting::query()->forUser($user)->min('date');
            $from = $earliest !== null
                ? $this->reportInterval()->startOf(CarbonImmutable::parse($earliest))
                : CarbonImmutable::today()->startOfDay();
        }

        $to ??= CarbonImmutable::today()->endOfDay();

        if ($to->lt($from)) {
            $to = $this->reportInterval()->endOf($from);
        }

        return [$from, $to];
    }

    /**
     * Parses a `YYYY`, `YYYY-MM` or `YYYY-MM-DD` bound. Shorthand values
     * widen to the first or last instant of the period they name.
     */
    private function parseBoundary(mixed $value, bool $end): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}(-\d{2})?(-\d{2})?$/', $value) !== 1) {
            return null;
        }

        $parts = array_map('intval', explode('-', $value));
        [$year, $month, $day] = [$parts[0], $parts[1] ?? 1, $parts[2] ?? 1];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = CarbonImmutable::create($year, $month, $day);

        if (! $end) {
            return $date->startOfDay();
        }

        return match (count($parts)) {
            1 => $date->endOfYear(),
            2 => $date->endOfMonth(),
            default => $date->endOfDay(),
        };
    }
}
