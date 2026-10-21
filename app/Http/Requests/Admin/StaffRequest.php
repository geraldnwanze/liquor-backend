<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRequest extends FormRequest
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
        if (is_string($this->input('email'))) {
            $merge['email'] = mb_strtolower(trim($this->input('email')));
        }
        $this->merge($merge);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:2', 'max:120'],
            // Staff and customers share one table, so an address already used by a customer is refused too.
            'email' => [$creating ? 'required' : 'prohibited', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            // A staff member always has at least one role: a user with none would simply become a customer.
            'roles' => [$required, 'array', 'min:1', 'max:10'],
            'roles.*' => ['string', 'distinct', Rule::exists(Role::class, 'slug')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'That email address already has an account. Use a different address.',
            'email.prohibited' => "A staff member's email can't be changed here.",
            'roles.min' => 'Choose at least one role.',
        ];
    }
}
