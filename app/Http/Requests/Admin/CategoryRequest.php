<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $id = $this->route('category')?->id;

        return [
            'name' => [$required, 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($id)],
            'parent_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image_url' => ['nullable', 'url:https', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:190'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ];
    }

    /** Prevents a category becoming its own ancestor. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $category = $this->route('category');
            $parentId = $this->input('parent_id');

            if (! $category || ! $parentId) {
                return;
            }

            for ($cursor = Category::find($parentId); $cursor; $cursor = $cursor->parent_id ? Category::find($cursor->parent_id) : null) {
                if ($cursor->is($category)) {
                    $validator->errors()->add('parent_id', 'A category cannot be placed inside itself or one of its children.');

                    return;
                }
            }
        }];
    }
}
