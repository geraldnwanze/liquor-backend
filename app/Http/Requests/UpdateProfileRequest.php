<?php

namespace App\Http\Requests;

use App\Support\NigerianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('name'))) {
            $merge['name'] = trim(strip_tags($this->input('name')));
        }
        if (is_string($this->input('phone'))) {
            $merge['phone'] = $this->input('phone') === '' ? null : (NigerianPhone::normalize($this->input('phone')) ?? $this->input('phone'));
        }
        $this->merge($merge);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'regex:/^\+234[789]\d{9}$/', Rule::unique('users', 'phone')->ignore($this->user()->id)],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 0803 123 4567.'];
    }
}
