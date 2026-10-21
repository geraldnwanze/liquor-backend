<?php

namespace App\Services\Administration;

use App\Services\Catalog\ProductImageStore;
use Illuminate\Http\UploadedFile;

/**
 * The business logo: stored as a path in settings (`business.logo_path`), re-encoded to WebP like every other upload
 * (transparency kept, longest side at most 800 px), and shown on the shop, receipts and the admin.
 */
class BusinessLogo
{
    public const MAX_SIDE = 800;

    public function __construct(private SettingsRepository $settings, private ProductImageStore $images) {}

    public function url(): ?string
    {
        $path = $this->settings->get('business', 'logo_path');

        return is_string($path) && $path !== '' ? $this->images->urlFor($path) : null;
    }

    /** Stores the new logo, then removes the one it replaces. */
    public function replace(UploadedFile $file): string
    {
        $old = $this->settings->get('business', 'logo_path');
        $path = $this->images->putFile($file, 'branding', self::MAX_SIDE);
        $this->settings->set('business', 'logo_path', $path, isPublic: false);
        if (is_string($old) && $old !== '') {
            $this->images->deletePath($old);
        }

        return $this->images->urlFor($path);
    }

    public function remove(): void
    {
        $old = $this->settings->get('business', 'logo_path');
        $this->settings->set('business', 'logo_path', null);
        if (is_string($old) && $old !== '') {
            $this->images->deletePath($old);
        }
    }
}
