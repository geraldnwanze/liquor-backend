<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    private const TEXT_FIELDS = ['name', 'short_description', 'description', 'tasting_notes', 'serving_suggestions', 'authenticity_note', 'country_of_origin', 'seo_title', 'seo_description'];

    public function authorize(): bool
    {
        return true;
    }

    /** Descriptive text is stored as plain text; the storefront must still escape on output. */
    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (self::TEXT_FIELDS as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim(strip_tags($this->input($field)));
            }
        }
        $this->merge($clean);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $id = $this->route('product')?->id;

        return [
            'name' => [$required, 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($id)],
            'brand_id' => ['nullable', 'uuid', 'exists:brands,id'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'country_of_origin' => ['nullable', 'string', 'max:80'],
            'abv' => ['nullable', 'numeric', 'between:0,100'],
            'flavor_profile' => ['nullable', 'array', 'max:12'],
            'flavor_profile.*' => ['string', 'max:40'],
            'tasting_notes' => ['nullable', 'string', 'max:5000'],
            'serving_suggestions' => ['nullable', 'string', 'max:5000'],
            'authenticity_note' => ['nullable', 'string', 'max:2000'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_age_restricted' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:190'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ];
    }
}
