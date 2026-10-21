<?php

namespace App\Providers;

use App\Contracts\AgeVerifier;
use App\Models\Brand;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\NewsletterDelivery;
use App\Models\PersonalAccessToken;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Notifications\NewsletterCampaignNotification;
use App\Services\Catalog\CloudinaryAdapter;
use App\Services\Checkout\SelfDeclarationAgeVerifier;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap for a stronger implementation if the business or regulation requires more than self-declaration.
        $this->app->bind(AgeVerifier::class, SelfDeclarationAgeVerifier::class);
        $this->app->singleton(PaymentGatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // The delivery log: a newsletter email counts as sent once the mail service has accepted it.
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->notification instanceof NewsletterCampaignNotification && $event->notification->deliveryId) {
                NewsletterDelivery::whereKey($event->notification->deliveryId)->update(['status' => NewsletterDelivery::SENT, 'sent_at' => now(), 'error' => null]);
            }
        });

        Storage::extend('cloudinary', function ($app, array $config) {
            $adapter = new CloudinaryAdapter($config);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });

        // Public catalog caches must never outlive an admin change.
        foreach ([Category::class, Brand::class, Product::class, ProductVariant::class, ProductImage::class] as $model) {
            $model::saved(fn () => Cache::forget('catalog.categories'));
            $model::deleted(fn () => Cache::forget('catalog.categories'));
        }

        DeliveryZone::saved(fn () => Cache::forget('delivery.zones'));
        DeliveryZone::deleted(fn () => Cache::forget('delivery.zones'));

        // The storefront calls this API from its own server, so every customer shares one IP. Key limits on the
        // cart token or signed-in user first, and fall back to IP only for anonymous callers.
        $shopper = fn (Request $request): string => $request->user('sanctum')?->getAuthIdentifier()
            ? 'user:'.$request->user('sanctum')->getAuthIdentifier()
            : ($request->header('X-Cart-Token') ? 'cart:'.$request->header('X-Cart-Token') : 'ip:'.$request->ip());

        RateLimiter::for('cart', fn (Request $request) => Limit::perMinute(120)->by($shopper($request)));
        // Codes are guessable by brute force, so apply attempts are tight per shopper, with a ceiling across everyone
        // (a shopper can mint new cart tokens, and every storefront visitor shares the storefront server's IP).
        RateLimiter::for('discount', fn (Request $request) => [
            Limit::perMinute(10)->by('discount:'.$shopper($request)),
            Limit::perMinute(300)->by('discount:all'),
        ]);
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($shopper($request)));
        RateLimiter::for('payments', fn (Request $request) => Limit::perMinute(20)->by($shopper($request)));
        RateLimiter::for('payment-verify', fn (Request $request) => Limit::perMinute(60)->by($shopper($request)));
        RateLimiter::for('order-status', fn (Request $request) => Limit::perMinute(30)->by($shopper($request)));
        // Password-reset emails link to the storefront page, not to this API.
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        // Per email+IP stops guessing one account; a looser per-IP cap stops spraying many accounts.
        $authLimits = fn (Request $request): array => [
            Limit::perMinute(10)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(300)->by($request->ip()),
        ];
        RateLimiter::for('auth', $authLimits);
        RateLimiter::for('newsletter', fn (Request $request) => [
            Limit::perMinute(5)->by('newsletter:'.$request->ip()),
            Limit::perMinute(300)->by('newsletter:all'),
        ]);
        RateLimiter::for('reviews', fn (Request $request) => Limit::perMinute(5)->by('reviews:'.$request->user('sanctum')?->getAuthIdentifier()));
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(300)->by($request->ip()),
        ]);
    }
}
