<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Staff without the cost-view permission cannot read or write cost price. */
    protected function prepareForValidation(): void
    {
        if (! $this->user()->hasPermission('catalog.view-cost')) {
            // getInputSource() covers both JSON and form bodies.
            $this->getInputSource()->remove('cost_price_kobo');
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $id = $this->route('variant')?->id;

        return [
            'sku' => [$required, 'string', 'max:64', Rule::unique('product_variants', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:64'],
            'name' => [$required, 'string', 'max:80'],
            'volume_ml' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'price_kobo' => [$required, 'integer', 'min:1', 'max:100000000000'],
            'sale_price_kobo' => ['nullable', 'integer', 'min:1'],
            'cost_price_kobo' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'initial_stock' => [Rule::prohibitedIf(! $this->isMethod('post')), 'nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $price = $this->input('price_kobo', $this->route('variant')?->price_kobo);
            $sale = $this->input('sale_price_kobo', $this->route('variant')?->sale_price_kobo);

            if ($sale !== null && $price !== null && (int) $sale >= (int) $price) {
                $validator->errors()->add('sale_price_kobo', 'The sale price must be lower than the regular price.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sku.unique' => 'That SKU is already used by another variant, or by a deleted one. Choose a different SKU.',
        ];
    }
}
