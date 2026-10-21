<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The date range a report covers. Dates are whole days in Nigerian time (Africa/Lagos), both ends included, so
 * "today" means the shop's today, not the server's. Defaults to the last 30 days; at most a year at a time.
 */
class ReportRangeRequest extends FormRequest
{
    public const TIMEZONE = 'Africa/Lagos';

    public const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'group' => ['nullable', 'in:day,week,month'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort' => ['nullable', 'in:revenue,units'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->localEnd()->lt($this->localStart())) {
                $validator->errors()->add('to', 'The end date must be on or after the start date.');
            } elseif ($this->localStart()->diffInDays($this->localEnd()) >= self::MAX_DAYS) {
                $validator->errors()->add('to', 'Choose a range of at most one year.');
            }
        }];
    }

    public function localStart(): CarbonImmutable
    {
        $from = $this->input('from');

        return $from ? CarbonImmutable::createFromFormat('Y-m-d', $from, self::TIMEZONE)->startOfDay() : $this->localEnd()->subDays(29)->startOfDay();
    }

    public function localEnd(): CarbonImmutable
    {
        $to = $this->input('to');

        return $to ? CarbonImmutable::createFromFormat('Y-m-d', $to, self::TIMEZONE)->startOfDay() : CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    /** Start of the range as a UTC instant (inclusive). */
    public function startUtc(): CarbonImmutable
    {
        return $this->localStart()->utc();
    }

    /** End of the range as a UTC instant (exclusive): midnight at the end of the last chosen day. */
    public function endUtc(): CarbonImmutable
    {
        return $this->localEnd()->addDay()->startOfDay()->utc();
    }

    public function group(): string
    {
        return $this->input('group', 'day');
    }
}
