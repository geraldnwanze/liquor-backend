<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            // A photo is uploaded (preferred) or linked by https address; media can also be hosted externally.
            'file' => $creating
                ? ['required_without:url', 'prohibits:url', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=400,min_height=400,max_width=4500,max_height=4500']
                : ['prohibited'],
            'url' => $creating ? ['required_without:file', 'url:https', 'max:500'] : ['sometimes', 'url:https', 'max:500'],
            'alt' => ['nullable', 'string', 'max:190'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.max' => 'The photo must be 5 MB or smaller.',
            'file.mimes' => 'Upload a JPEG, PNG or WebP photo.',
            'file.dimensions' => 'The photo must be between 400 and 4500 pixels on each side.',
            'file.uploaded' => 'The upload failed. Check the file is under 5 MB and try again.',
        ];
    }
}
