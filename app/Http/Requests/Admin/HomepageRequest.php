<?php

namespace App\Http\Requests\Admin;

use App\Services\Administration\HomepageContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Home page wording. Plain text only; links are site paths (/search) or https addresses, never scripts. */
class HomepageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (array_keys(HomepageContent::TEXT) as $key) {
            if (is_string($this->input($key))) {
                $clean[$key] = trim(strip_tags($this->input($key)));
            }
        }
        $this->merge($clean);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = [];
        foreach (HomepageContent::TEXT as $key => $max) {
            $rules[$key] = ['nullable', 'string', "max:{$max}"];
        }
        foreach (['announcement_link', 'hero_primary_href', 'hero_secondary_href'] as $link) {
            $rules[$link][] = 'regex:#^(/(?!/)[^\s]*|https://[^\s]+)$#';
        }
        foreach (HomepageContent::SECTIONS as $key) {
            $rules[$key] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['*.regex' => 'Use a site address such as /search, or a full https:// address.'];
    }

    /** A button needs both its words and its destination. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ([['hero_primary_label', 'hero_primary_href'], ['hero_secondary_label', 'hero_secondary_href']] as [$label, $href]) {
                $hasLabel = filled($this->input($label));
                $hasHref = filled($this->input($href));
                if ($hasLabel && ! $hasHref) {
                    $validator->errors()->add($href, 'Add the address this button goes to.');
                }
                if ($hasHref && ! $hasLabel) {
                    $validator->errors()->add($label, 'Add the words for this button.');
                }
            }
        }];
    }
}
