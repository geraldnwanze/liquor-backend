<?php

use App\Http\Controllers\Api\V1\AccountDeletionController;
use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\DeliveryZoneController;
use App\Http\Controllers\Api\V1\DevPaymentController;
use App\Http\Controllers\Api\V1\NewsletterController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicSettingsController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\WishlistController;
use App\Http\Middleware\EnsureAccountActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::post('staff/login', [AuthController::class, 'staffLogin'])->middleware('throttle:auth');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
        Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');
    });

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/best-sellers', [ProductController::class, 'bestSellers']);
    Route::get('products/{slug}/reviews', [ReviewController::class, 'index']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);

    Route::post('newsletter', [NewsletterController::class, 'subscribe'])->middleware('throttle:newsletter');
    Route::post('newsletter/confirm', [NewsletterController::class, 'confirm'])->middleware('throttle:newsletter');
    Route::post('newsletter/unsubscribe', [NewsletterController::class, 'unsubscribe'])->middleware('throttle:newsletter');

    Route::get('pages', [PageController::class, 'index']);
    Route::get('pages/{slug}', [PageController::class, 'show']);

    Route::get('settings/public', [PublicSettingsController::class, 'show']);
    Route::get('delivery/zones', [DeliveryZoneController::class, 'index']);

    // Guest and customer carts share these routes. The cart is resolved from the Sanctum token or X-Cart-Token.
    Route::middleware('throttle:cart')->prefix('cart')->group(function () {
        Route::get('/', [CartController::class, 'show']);
        Route::delete('/', [CartController::class, 'clear']);
        Route::post('items', [CartController::class, 'addItem']);
        Route::patch('items/{item}', [CartController::class, 'updateItem'])->whereUuid('item');
        Route::delete('items/{item}', [CartController::class, 'destroyItem'])->whereUuid('item');
        Route::put('discount', [CartController::class, 'applyDiscount'])->middleware('throttle:discount');
        Route::delete('discount', [CartController::class, 'removeDiscount']);
    });

    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout');
    Route::get('orders/{order}', [OrderController::class, 'lookup'])->middleware('throttle:order-status');
    // Payments. The browser is only ever sent to a gateway's own page; confirmation always comes from the gateway to us.
    Route::get('payments/gateways', [PaymentController::class, 'gateways']);
    Route::post('orders/{order}/payments', [PaymentController::class, 'initialize'])->middleware('throttle:payments');
    Route::post('payments/{reference}/verify', [PaymentController::class, 'verify'])->middleware('throttle:payment-verify');
    Route::post('webhooks/{gateway}', [PaymentWebhookController::class, 'handle'])->where('gateway', 'paystack|flutterwave|fake');
    if (config('payments.gateways.fake.enabled') && ! app()->isProduction()) {
        Route::post('dev/payments/{reference}/simulate', [DevPaymentController::class, 'simulate']);
    }

    Route::get('orders/{order}/receipt', [OrderController::class, 'receipt'])->middleware('throttle:order-status');
    Route::get('orders/{order}/status', [OrderController::class, 'status'])->middleware('throttle:order-status');

    Route::middleware(['auth:sanctum', EnsureAccountActive::class])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [ProfileController::class, 'update']);
        Route::put('me/password', [ProfileController::class, 'changePassword'])->middleware('throttle:auth');
        Route::get('me/addresses', [AddressController::class, 'index']);
        Route::post('me/addresses', [AddressController::class, 'store']);
        Route::patch('me/addresses/{address}', [AddressController::class, 'update'])->whereUuid('address');
        Route::delete('me/addresses/{address}', [AddressController::class, 'destroy'])->whereUuid('address');
        Route::get('me/deletion-request', [AccountDeletionController::class, 'show']);
        Route::post('me/deletion-request', [AccountDeletionController::class, 'store'])->middleware('throttle:auth');
        Route::delete('me/deletion-request', [AccountDeletionController::class, 'cancel']);
        Route::get('me/wishlist', [WishlistController::class, 'index']);
        Route::get('me/wishlist/slugs', [WishlistController::class, 'slugs']);
        Route::put('me/wishlist/{slug}', [WishlistController::class, 'store']);
        Route::delete('me/wishlist/{slug}', [WishlistController::class, 'destroy']);
        Route::get('me/products/{slug}/review', [ReviewController::class, 'mine']);
        Route::delete('me/products/{slug}/review', [ReviewController::class, 'destroyMine']);
        Route::post('products/{slug}/reviews', [ReviewController::class, 'store'])->middleware('throttle:reviews');
        Route::get('me/orders', [OrderController::class, 'index']);
        Route::get('me/orders/{reference}', [OrderController::class, 'show']);

        Route::prefix('admin')->group(function () {
            Route::middleware('permission:catalog.manage')->group(function () {
                Route::apiResource('products', Admin\ProductController::class)->names('admin.products');
                Route::post('products/{product}/archive', [Admin\ProductController::class, 'archive']);
                Route::post('products/{product}/duplicate', [Admin\ProductController::class, 'duplicate']);
                Route::post('products/{product}/variants', [Admin\VariantController::class, 'store']);
                Route::match(['put', 'patch'], 'variants/{variant}', [Admin\VariantController::class, 'update']);
                Route::delete('variants/{variant}', [Admin\VariantController::class, 'destroy']);
                Route::post('products/{product}/images', [Admin\ProductImageController::class, 'store']);
                Route::match(['put', 'patch'], 'images/{image}', [Admin\ProductImageController::class, 'update']);
                Route::post('images/{image}/primary', [Admin\ProductImageController::class, 'makePrimary']);
                Route::delete('images/{image}', [Admin\ProductImageController::class, 'destroy']);
                Route::post('categories/{category}/image', [Admin\CategoryController::class, 'uploadImage'])->whereUuid('category');
                Route::delete('categories/{category}/image', [Admin\CategoryController::class, 'removeImage'])->whereUuid('category');
                Route::apiResource('categories', Admin\CategoryController::class)->names('admin.categories');
                Route::post('brands/{brand}/logo', [Admin\BrandController::class, 'uploadLogo'])->whereUuid('brand');
                Route::delete('brands/{brand}/logo', [Admin\BrandController::class, 'removeLogo'])->whereUuid('brand');
                Route::apiResource('brands', Admin\BrandController::class)->names('admin.brands');
            });

            Route::middleware('permission:catalog.manage,content.manage,promotions.manage,shipping.manage,reviews.moderate,customers.manage')->group(function () {
                Route::get('trash', [Admin\TrashController::class, 'index']);
                Route::post('trash/{type}/{id}/restore', [Admin\TrashController::class, 'restore'])->whereUuid('id')->where('type', '[a-z]+');
            });

            Route::middleware('permission:reviews.moderate')->group(function () {
                Route::get('reviews', [Admin\ReviewController::class, 'index']);
                Route::post('reviews/{review}/approve', [Admin\ReviewController::class, 'approve'])->whereUuid('review');
                Route::post('reviews/{review}/reject', [Admin\ReviewController::class, 'reject'])->whereUuid('review');
                Route::put('reviews/{review}/reply', [Admin\ReviewController::class, 'reply'])->whereUuid('review');
                Route::delete('reviews/{review}/reply', [Admin\ReviewController::class, 'removeReply'])->whereUuid('review');
                Route::delete('reviews/{review}', [Admin\ReviewController::class, 'destroy'])->whereUuid('review');
            });

            Route::middleware('permission:content.manage')->group(function () {
                Route::get('homepage', [Admin\HomepageController::class, 'show']);
                Route::put('homepage', [Admin\HomepageController::class, 'update']);
                Route::post('homepage/hero-image', [Admin\HomepageController::class, 'uploadHeroImage']);
                Route::delete('homepage/hero-image', [Admin\HomepageController::class, 'removeHeroImage']);
                Route::post('pages/images', [Admin\PageController::class, 'uploadImage'])->middleware('throttle:30,1');
                Route::apiResource('pages', Admin\PageController::class)->names('admin.pages');
            });

            Route::middleware('permission:orders.view,orders.manage')->group(function () {
                Route::get('orders', [Admin\OrderController::class, 'index']);
                Route::get('orders/summary', [Admin\OrderController::class, 'summary']);
                Route::get('orders/{order}', [Admin\OrderController::class, 'show']);
                Route::get('orders/{order}/receipt', [Admin\OrderController::class, 'receipt']);
            });
            Route::middleware('permission:orders.manage')->group(function () {
                Route::patch('orders/{order}/fulfilment', [Admin\OrderController::class, 'fulfilment']);
                Route::put('orders/{order}/shipment', [Admin\OrderController::class, 'shipment']);
                Route::post('orders/{order}/notes', [Admin\OrderController::class, 'note']);
                Route::post('orders/{order}/cancel', [Admin\OrderController::class, 'cancel']);
            });
            Route::middleware('permission:orders.manage')->get('payments', [Admin\PaymentController::class, 'index']);
            Route::middleware('permission:orders.refund')->post('payments/{payment}/refunds', [Admin\PaymentController::class, 'refund']);

            Route::middleware('permission:reports.view')->prefix('reports')->group(function () {
                Route::get('sales', [Admin\ReportController::class, 'sales']);
                Route::get('products', [Admin\ReportController::class, 'products']);
                Route::get('discounts', [Admin\ReportController::class, 'discounts']);
                Route::get('stock', [Admin\ReportController::class, 'stock']);
            });

            // Only a super administrator holds this (via "*"): whoever can assign roles can grant themselves everything.
            Route::middleware('permission:staff.manage')->group(function () {
                Route::get('staff', [Admin\StaffController::class, 'index']);
                Route::get('roles', [Admin\StaffController::class, 'roles']);
                Route::post('staff', [Admin\StaffController::class, 'store']);
                Route::patch('staff/{staff}', [Admin\StaffController::class, 'update'])->whereUuid('staff');
                Route::post('staff/{staff}/deactivate', [Admin\StaffController::class, 'deactivate'])->whereUuid('staff');
                Route::post('staff/{staff}/reactivate', [Admin\StaffController::class, 'reactivate'])->whereUuid('staff');
                Route::post('staff/{staff}/resend-invite', [Admin\StaffController::class, 'resendInvite'])->whereUuid('staff');
            });

            Route::middleware('permission:settings.manage')->group(function () {
                Route::get('settings', [Admin\SettingsController::class, 'show']);
                Route::put('settings', [Admin\SettingsController::class, 'update']);
                Route::post('settings/logo', [Admin\SettingsController::class, 'uploadLogo']);
                Route::delete('settings/logo', [Admin\SettingsController::class, 'removeLogo']);
            });

            Route::middleware('permission:customers.manage')->group(function () {
                Route::get('deletion-requests', [Admin\AccountDeletionController::class, 'index']);
                Route::post('deletion-requests/{deletionRequest}/complete', [Admin\AccountDeletionController::class, 'complete'])->whereUuid('deletionRequest');
                Route::post('deletion-requests/{deletionRequest}/reject', [Admin\AccountDeletionController::class, 'reject'])->whereUuid('deletionRequest');
                Route::post('customers/{customer}/suspend', [Admin\CustomerController::class, 'suspend'])->whereUuid('customer');
                Route::post('customers/{customer}/reactivate', [Admin\CustomerController::class, 'reactivate'])->whereUuid('customer');
                Route::get('customers', [Admin\CustomerController::class, 'index']);
                Route::get('customers/{customer}', [Admin\CustomerController::class, 'show'])->whereUuid('customer');
                Route::post('customers/{customer}/notes', [Admin\CustomerController::class, 'addNote'])->whereUuid('customer');
                Route::delete('customers/{customer}/notes/{note}', [Admin\CustomerController::class, 'deleteNote'])->whereUuid(['customer', 'note']);
            });

            Route::middleware('permission:promotions.manage')->group(function () {
                Route::get('discounts/targets', [Admin\DiscountController::class, 'targets']);
                Route::apiResource('discounts', Admin\DiscountController::class)->names('admin.discounts');
                Route::get('newsletter', [Admin\NewsletterController::class, 'index']);
                Route::get('newsletter/campaigns', [Admin\NewsletterCampaignController::class, 'index']);
                Route::post('newsletter/campaigns', [Admin\NewsletterCampaignController::class, 'store']);
                Route::put('newsletter/campaigns/{campaign}', [Admin\NewsletterCampaignController::class, 'update'])->whereUuid('campaign');
                Route::delete('newsletter/campaigns/{campaign}', [Admin\NewsletterCampaignController::class, 'destroy'])->whereUuid('campaign');
                Route::post('newsletter/campaigns/{campaign}/test', [Admin\NewsletterCampaignController::class, 'test'])->whereUuid('campaign');
                Route::get('newsletter/campaigns/{campaign}/deliveries', [Admin\NewsletterCampaignController::class, 'deliveries'])->whereUuid('campaign');
                Route::post('newsletter/campaigns/{campaign}/retry-failed', [Admin\NewsletterCampaignController::class, 'retryFailed'])->middleware('throttle:5,1')->whereUuid('campaign');
                Route::post('newsletter/campaigns/{campaign}/send', [Admin\NewsletterCampaignController::class, 'send'])->middleware('throttle:5,1')->whereUuid('campaign');
            });

            Route::middleware('permission:shipping.manage')->group(function () {
                Route::apiResource('delivery-zones', Admin\DeliveryZoneController::class)->except('show')->parameters(['delivery-zones' => 'zone'])->names('admin.delivery-zones');
            });

            Route::middleware('permission:inventory.manage')->group(function () {
                Route::post('variants/{variant}/stock', [Admin\StockController::class, 'adjust']);
                Route::get('variants/{variant}/movements', [Admin\StockController::class, 'movements']);
                Route::get('inventory/low-stock', [Admin\StockController::class, 'lowStock']);
            });
        });
    });
});
