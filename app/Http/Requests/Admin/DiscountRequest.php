<?php

namespace App\Http\Requests\Admin;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Discount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create and update share these rules. On update, anything not sent keeps its current value, and the rules run on
 * the combined result, so a change can never leave the discount in a contradictory state (for example switching a
 * percentage code to free delivery while it still has a percentage, or targeting products with an order-wide code).
 */
class DiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('code'))) {
            $merge['code'] = Discount::normaliseCode($this->input('code'));
        }
        if (is_string($this->input('name'))) {
            $merge['name'] = trim(strip_tags($this->input('name')));
        }

        /** @var ?Discount $existing */
        $existing = $this->route('discount');
        if ($existing) {
            $current = [
                'code' => $existing->code, 'name' => $existing->name, 'type' => $existing->type->value, 'scope' => $existing->scope->value,
                'percent' => $existing->type === DiscountType::Percentage ? $existing->value : null,
                'amount_kobo' => $existing->type === DiscountType::Fixed ? $existing->value : null,
                'max_discount_kobo' => $existing->max_discount_kobo, 'min_order_kobo' => $existing->min_order_kobo,
                'starts_at' => $existing->starts_at?->toIso8601String(), 'ends_at' => $existing->ends_at?->toIso8601String(),
                'usage_limit' => $existing->usage_limit, 'per_customer_limit' => $existing->per_customer_limit, 'is_active' => $existing->is_active,
                'product_ids' => $existing->products()->pluck('products.id')->all(),
                'category_ids' => $existing->categories()->pluck('categories.id')->all(),
            ];
            // A type change drops the amounts that belonged to the old type unless they are sent again.
            if ($this->has('type') && $this->input('type') !== $existing->type->value) {
                $current['percent'] = $current['amount_kobo'] = $current['max_discount_kobo'] = null;
            }
            if ($this->has('scope') && $this->input('scope') !== $existing->scope->value) {
                $current['product_ids'] = $current['category_ids'] = [];
            }
            foreach ($current as $key => $value) {
                if (! $this->has($key)) {
                    $merge[$key] = $value;
                }
            }
        }

        $this->merge($merge);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('discount')?->id;
        $type = $this->input('type');
        $scope = $this->input('scope');

        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{3,32}$/', Rule::unique('discounts', 'code')->ignore($id)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'type' => ['required', Rule::enum(DiscountType::class)],
            'percent' => $type === 'percentage' ? ['required', 'integer', 'between:1,100'] : ['prohibited'],
            'amount_kobo' => $type === 'fixed' ? ['required', 'integer', 'min:1', 'max:100000000000'] : ['prohibited'],
            'max_discount_kobo' => $type === 'percentage' ? ['nullable', 'integer', 'min:1', 'max:100000000000'] : ['prohibited'],
            'min_order_kobo' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'scope' => ['required', Rule::enum(DiscountScope::class)],
            'product_ids' => $scope === 'products' ? ['required', 'array', 'min:1', 'max:200'] : ['prohibited'],
            'product_ids.*' => ['uuid', 'distinct', 'exists:products,id'],
            'category_ids' => $scope === 'categories' ? ['required', 'array', 'min:1', 'max:50'] : ['prohibited'],
            'category_ids.*' => ['uuid', 'distinct', 'exists:categories,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', ...($this->filled('starts_at') ? ['after:starts_at'] : [])],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Free delivery discounts the delivery fee, which belongs to the whole order, not to chosen items.
            if ($this->input('type') === 'free_delivery' && $this->input('scope') !== 'order') {
                $validator->errors()->add('scope', 'A free delivery code applies to the whole order.');
            }
            $total = $this->input('usage_limit');
            $each = $this->input('per_customer_limit');
            if ($total !== null && $each !== null && (int) $each > (int) $total) {
                $validator->errors()->add('per_customer_limit', 'One customer cannot be allowed more uses than the total.');
            }
        }];
    }

    /**
     * The row to store: percentage and fixed amount share the `value` column.
     *
     * @return array<string, mixed>
     */
    public function discountAttributes(): array
    {
        $data = $this->validated();
        $value = match ($data['type']) {
            'percentage' => (int) $data['percent'],
            'fixed' => (int) $data['amount_kobo'],
            default => 0,
        };

        return [
            'code' => $data['code'], 'name' => $data['name'], 'type' => $data['type'], 'value' => $value,
            'max_discount_kobo' => $data['max_discount_kobo'] ?? null, 'min_order_kobo' => $data['min_order_kobo'] ?? null,
            'scope' => $data['scope'], 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null,
            'usage_limit' => $data['usage_limit'] ?? null, 'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'is_active' => (bool) $data['is_active'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'That code is already used by another discount, or by a deleted one. Choose a different code.',
        ];
    }
}
