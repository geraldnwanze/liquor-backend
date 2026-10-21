<?php

namespace App\Http\Requests\Admin;

use App\Models\DeliveryZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class DeliveryZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'state' => [$required, 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'fee_kobo' => [$required, 'integer', 'min:0', 'max:100000000'],
            'free_threshold_kobo' => ['nullable', 'integer', 'min:1'],
            'estimated_days_min' => ['nullable', 'integer', 'min:0', 'max:60'],
            'estimated_days_max' => ['nullable', 'integer', 'min:0', 'max:60', 'gte:estimated_days_min'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** One zone per state+city (the DB unique index cannot enforce this for NULL cities). */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $current = $this->route('zone');
            $state = $this->input('state', $current?->state);
            $city = $this->has('city') ? $this->input('city') : $current?->city;

            if ($state === null) {
                return;
            }

            $duplicate = DeliveryZone::query()->withTrashed()
                ->whereRaw('lower(state) = ?', [mb_strtolower($state)])
                ->when($city === null || $city === '', fn ($q) => $q->whereNull('city'), fn ($q) => $q->whereRaw('lower(city) = ?', [mb_strtolower($city)]))
                ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('city', 'A delivery zone already exists for this state and city (possibly a deleted one).');
            }
        }];
    }
}
