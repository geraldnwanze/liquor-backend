<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Services\Administration\AuditLogger;
use App\Services\Administration\BusinessLogo;
use App\Services\Administration\SettingsRepository;
use App\Services\Commerce\CommerceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    private const BUSINESS = ['name', 'address', 'phone', 'email', 'registration_number', 'receipt_footer'];

    public function __construct(private SettingsRepository $settings, private CommerceSettings $commerce, private AuditLogger $audit, private BusinessLogo $logo) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->snapshot()]);
    }

    public function update(SettingsRequest $request): JsonResponse
    {
        $before = $this->snapshot();

        foreach (self::BUSINESS as $key) {
            $value = $request->input("business.{$key}");
            $this->settings->set('business', $key, ($value === null || $value === '') ? null : $value);
        }
        foreach (['minimum_age', 'reservation_minutes', 'max_quantity_per_line', 'max_units_per_order'] as $key) {
            $this->settings->set('commerce', $key, (int) $request->input("commerce.{$key}"));
        }

        $after = $this->snapshot();
        $changed = [];
        foreach (['business', 'commerce'] as $group) {
            foreach ($after[$group] as $key => $value) {
                if ($key !== 'logo_url' && $value !== $before[$group][$key]) {
                    $changed["{$group}.{$key}"] = ['from' => $before[$group][$key], 'to' => $value];
                }
            }
        }
        $this->audit->record('settings.updated', null, ['changed' => $changed]);

        return response()->json(['data' => $after]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=100,min_height=100,max_width=4000,max_height=4000']],
            [
                'file.max' => 'The logo must be 2 MB or smaller.',
                'file.mimes' => 'Upload a PNG, JPEG or WebP logo.',
                'file.dimensions' => 'The logo must be between 100 and 4000 pixels on each side.',
                'file.uploaded' => 'The upload failed. Check the file is under 2 MB and try again.',
            ],
        );

        $url = $this->logo->replace($request->file('file'));
        $this->audit->record('settings.logo_updated');

        return response()->json(['data' => ['logo_url' => $url]]);
    }

    public function removeLogo(): JsonResponse
    {
        $this->logo->remove();
        $this->audit->record('settings.logo_removed');

        return response()->json(null, 204);
    }

    /**
     * What is stored now, plus what each commerce rule falls back to (the config default) and what a blank
     * business name resolves to on receipts.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'business' => collect(self::BUSINESS)->mapWithKeys(fn (string $k) => [$k => $this->settings->get('business', $k)])->all() + ['logo_url' => $this->logo->url()],
            'commerce' => [
                'minimum_age' => $this->commerce->minimumAge(),
                'reservation_minutes' => $this->commerce->reservationMinutes(),
                'max_quantity_per_line' => $this->commerce->maxQuantityPerLine(),
                'max_units_per_order' => $this->commerce->maxUnitsPerOrder(),
            ],
            'defaults' => ['business_name' => config('app.name'), 'commerce' => config('commerce')],
        ];
    }
}
