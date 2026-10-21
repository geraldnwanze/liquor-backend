<?php

namespace App\Http\Requests;

use App\Support\NigerianPhone;
use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['label', 'recipient_name', 'state', 'city', 'address_line', 'instructions'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim(strip_tags($this->input($field)));
            }
        }
        if (is_string($this->input('phone'))) {
            $merge['phone'] = NigerianPhone::normalize($this->input('phone')) ?? $this->input('phone');
        }
        $this->merge($merge);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'label' => ['nullable', 'string', 'max:40'],
            'recipient_name' => [$required, 'string', 'min:2', 'max:120'],
            'phone' => [$required, 'regex:/^\+234[789]\d{9}$/'],
            'state' => [$required, 'string', 'max:80'],
            'city' => [$required, 'string', 'max:80'],
            'address_line' => [$required, 'string', 'min:5', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 0803 123 4567.'];
    }
}
