<?php

namespace App\Services\Administration;

use App\Services\Catalog\ProductImageStore;
use Illuminate\Http\UploadedFile;

/**
 * What staff can change on the shop's front page and in the announcement strip above every page. Stored as settings
 * (group "homepage"); a blank value means "use the shop's built-in wording", so the shop never shows an empty hero.
 */
class HomepageContent
{
    /** Text fields staff may edit, with their length limits. */
    public const TEXT = [
        'announcement_text' => 160, 'announcement_link' => 300,
        'hero_eyebrow' => 60, 'hero_title' => 120, 'hero_title_emphasis' => 80, 'hero_text' => 300,
        'hero_primary_label' => 40, 'hero_primary_href' => 300, 'hero_secondary_label' => 40, 'hero_secondary_href' => 300,
    ];

    /** On/off switches for the home page sections (all on by default). */
    public const SECTIONS = ['show_best_sellers', 'show_new_arrivals', 'show_on_sale', 'show_how_it_works', 'show_promises'];

    public function __construct(private SettingsRepository $settings, private ProductImageStore $images) {}

    /** @return array<string, mixed> */
    public function values(): array
    {
        $out = [];
        foreach (array_keys(self::TEXT) as $key) {
            $out[$key] = $this->settings->get('homepage', $key) ?: null;
        }
        foreach (self::SECTIONS as $key) {
            $out[$key] = (bool) $this->settings->get('homepage', $key, true);
        }
        $out['hero_image_url'] = $this->heroImageUrl();

        return $out;
    }

    /** @param  array<string, mixed>  $data */
    public function save(array $data): void
    {
        foreach (array_keys(self::TEXT) as $key) {
            $value = $data[$key] ?? null;
            $this->settings->set('homepage', $key, ($value === null || $value === '') ? null : $value, isPublic: true);
        }
        foreach (self::SECTIONS as $key) {
            $this->settings->set('homepage', $key, (bool) ($data[$key] ?? true), isPublic: true);
        }
    }

    public function heroImageUrl(): ?string
    {
        $path = $this->settings->get('homepage', 'hero_image_path');

        return is_string($path) && $path !== '' ? $this->images->urlFor($path) : null;
    }

    public function replaceHeroImage(UploadedFile $file): string
    {
        $old = $this->settings->get('homepage', 'hero_image_path');
        $path = $this->images->putFile($file, 'homepage', 2000);
        $this->settings->set('homepage', 'hero_image_path', $path, isPublic: false);
        if (is_string($old) && $old !== '') {
            $this->images->deletePath($old);
        }

        return $this->images->urlFor($path);
    }

    public function removeHeroImage(): void
    {
        $old = $this->settings->get('homepage', 'hero_image_path');
        $this->settings->set('homepage', 'hero_image_path', null);
        if (is_string($old) && $old !== '') {
            $this->images->deletePath($old);
        }
    }
}
