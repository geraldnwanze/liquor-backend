<?php

namespace App\Http\Requests\Auth;

use App\Support\NigerianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim($this->string('email')->toString()))]);
        }
        if ($this->filled('phone')) {
            $this->merge(['phone' => NigerianPhone::normalize($this->string('phone')->toString()) ?? $this->input('phone')]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'regex:/^\+234[789]\d{9}$/', 'unique:users,phone'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 0803 123 4567.'];
    }
}
