<?php

namespace App\Http\Requests\Admin;

use App\Models\Page;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'body', 'meta_description'] as $field) {
            if (is_string($this->input($field))) {
                // Plain text only: markup is stripped before validating, so a markup-only body is rejected, not saved empty.
                $this->merge([$field => trim(strip_tags($this->input($field)))]);
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($this->route('page'))],
            'body' => [$required, 'string', 'min:10', 'max:20000'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'status' => ['sometimes', Rule::in([Page::DRAFT, Page::PUBLISHED])],
            'show_in_footer' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
        ];
    }
}
