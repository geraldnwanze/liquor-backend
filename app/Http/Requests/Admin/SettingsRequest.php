<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Business details (shown on receipts) and the commerce limits. Sent together, validated together.
 * The minimum age can be raised but never set below 18, the legal drinking age in Nigeria; anything stricter than
 * that is a decision for qualified local counsel, not something this form can unlock.
 */
class SettingsRequest extends FormRequest
{
    private const TEXT = ['name', 'address', 'phone', 'email', 'registration_number', 'receipt_footer'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $business = (array) $this->input('business', []);
        foreach (self::TEXT as $key) {
            if (isset($business[$key]) && is_string($business[$key])) {
                $business[$key] = trim(strip_tags($business[$key]));
            }
        }
        $this->merge(['business' => $business]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'business' => ['required', 'array'],
            'business.name' => ['nullable', 'string', 'max:120'],
            'business.address' => ['nullable', 'string', 'max:300'],
            'business.phone' => ['nullable', 'string', 'max:40'],
            'business.email' => ['nullable', 'email:rfc', 'max:190'],
            'business.registration_number' => ['nullable', 'string', 'max:60'],
            'business.receipt_footer' => ['nullable', 'string', 'max:300'],
            'commerce' => ['required', 'array'],
            'commerce.minimum_age' => ['required', 'integer', 'between:18,99'],
            'commerce.reservation_minutes' => ['required', 'integer', 'between:5,240'],
            'commerce.max_quantity_per_line' => ['required', 'integer', 'between:1,99'],
            'commerce.max_units_per_order' => ['required', 'integer', 'between:1,500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $line = $this->input('commerce.max_quantity_per_line');
            $order = $this->input('commerce.max_units_per_order');
            if (is_numeric($line) && is_numeric($order) && (int) $order < (int) $line) {
                $validator->errors()->add('commerce.max_units_per_order', 'The limit per order cannot be lower than the limit per item.');
            }
        }];
    }
}
