<?php

namespace App\Services\Administration;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsRepository
{
    private const CACHE_KEY = 'settings.all';

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        return $this->all()[$group][$key]['value'] ?? $default;
    }

    public function set(string $group, string $key, mixed $value, bool $isPublic = false): Setting
    {
        $setting = Setting::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value, 'is_public' => $isPublic],
        );
        Cache::forget(self::CACHE_KEY);

        return $setting;
    }

    /** @return array<string, mixed> public settings grouped by group, safe to expose to the storefront */
    public function publicSettings(): array
    {
        $out = [];
        foreach ($this->all() as $group => $items) {
            foreach ($items as $key => $item) {
                if ($item['is_public']) {
                    $out[$group][$key] = $item['value'];
                }
            }
        }

        return $out;
    }

    /** @return array<string, array<string, array{value: mixed, is_public: bool}>> */
    private function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $out = [];
            foreach (Setting::all() as $s) {
                $out[$s->group][$s->key] = ['value' => $s->value, 'is_public' => $s->is_public];
            }

            return $out;
        });
    }
}
