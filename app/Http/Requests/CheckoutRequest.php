<?php

namespace App\Http\Requests;

use App\Services\Checkout\CheckoutData;
use App\Support\NigerianPhone;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = ['idempotency_key' => $this->header('Idempotency-Key')];

        if (is_string($this->input('phone'))) {
            $merge['phone'] = NigerianPhone::normalize($this->input('phone')) ?? $this->input('phone');
        }
        if (is_string($this->input('delivery_instructions'))) {
            $merge['delivery_instructions'] = trim(strip_tags($this->input('delivery_instructions')));
        }
        foreach (['name', 'address', 'state', 'city'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim(strip_tags($this->input($field)));
            }
        }

        $this->merge($merge);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Required so that a network retry can never create a second order.
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-]{16,64}$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', 'regex:/^\+234[789]\d{9}$/'],
            'state' => ['required', 'string', 'max:80'],
            'city' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'min:5', 'max:255'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
            'age_confirmed' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'An Idempotency-Key header (16-64 letters, digits, - or _) is required.',
            'idempotency_key.regex' => 'The Idempotency-Key header must be 16-64 letters, digits, - or _.',
            'phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 0803 123 4567.',
        ];
    }

    public function checkoutData(): CheckoutData
    {
        return new CheckoutData(
            name: $this->string('name')->toString(),
            email: $this->string('email')->toString(),
            phone: $this->string('phone')->toString(),
            state: $this->string('state')->toString(),
            city: $this->string('city')->toString(),
            address: $this->string('address')->toString(),
            instructions: $this->filled('delivery_instructions') ? $this->string('delivery_instructions')->toString() : null,
            ageConfirmed: $this->boolean('age_confirmed'),
        );
    }
}
