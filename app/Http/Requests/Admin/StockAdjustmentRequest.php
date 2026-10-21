<?php

namespace App\Http\Requests\Admin;

use App\Enums\StockReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'delta' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            // Sales are recorded by the order pipeline, never by manual adjustment.
            'reason' => ['required', Rule::enum(StockReason::class)->except(StockReason::Sale)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
