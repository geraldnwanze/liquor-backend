<?php

namespace App\Http\Requests;

use App\Models\Discount;
use Illuminate\Foundation\Http\FormRequest;

class ApplyDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Discount::normaliseCode($this->input('code'))]);
        }
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.regex' => "That code isn't valid."];
    }
}
