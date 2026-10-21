<?php

namespace App\Services\Customers;

use App\Enums\OrderFulfilment;
use App\Enums\OrderStatus;
use App\Models\NewsletterDelivery;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Services\Catalog\ProductImageStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Erases a customer's personal data while keeping what the law and the books need.
 *
 * Gone: name, email, phone, date of birth, the ability to sign in, saved addresses, support notes, review text and
 * photos (the files are deleted), wishlist, the cart and any newsletter subscription. Kept: the account row itself
 * (past orders point at it), and past orders as they were, with the name, delivery address and contact details on
 * them, because tax and accounting records must be retained. That retention is a policy decision that needs local
 * legal review; the wording customers see says so plainly.
 */
class CustomerEraser
{
    public function __construct(private ProductImageStore $images) {}

    /** Orders that are still in motion: unpaid and not expired, or paid but not yet delivered. */
    public function hasOpenOrders(User $user): bool
    {
        return $user->orders()->where(fn ($q) => $q
            ->where('status', OrderStatus::PendingPayment)
            ->orWhere(fn ($p) => $p->where('status', OrderStatus::Paid)->where('fulfilment_status', '!=', OrderFulfilment::Delivered)))->exists();
    }

    public function erase(User $user): string
    {
        $originalEmail = $user->email;

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->carts()->delete();

            foreach ($user->addresses()->get() as $address) {
                $address->forceFill(['label' => null, 'recipient_name' => '[removed]', 'phone' => '[removed]', 'state' => '[removed]', 'city' => '[removed]', 'address_line' => '[removed]', 'instructions' => null, 'is_default' => false])->save();
                $address->delete();
            }
            foreach ($user->supportNotes()->get() as $note) {
                $note->forceFill(['body' => '[removed]'])->save();
                $note->delete();
            }
            foreach ($user->reviews()->with('images')->get() as $review) {
                foreach ($review->images as $image) {
                    $this->images->deletePath((string) $image->getRawOriginal('path'));
                    $image->forceFill(['path' => ''])->save();
                }
                $review->forceFill(['title' => null, 'body' => '[removed]', 'reply' => null])->save();
                $review->delete();
            }
            $user->wishlistItems()->delete();

            // The newsletter list holds the address itself, so it goes; delivery history keeps only counts.
            $subscriber = NewsletterSubscriber::where('email', mb_strtolower($user->email))->first();
            if ($subscriber) {
                NewsletterDelivery::where('subscriber_id', $subscriber->id)->update(['email' => '[erased]']);
                $subscriber->delete();
            }

            $user->forceFill([
                'name' => 'Deleted customer',
                'email' => "deleted-{$user->id}@deleted.invalid",
                'phone' => null,
                'date_of_birth' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'is_active' => false,
                'suspended_at' => null,
                'suspension_reason' => null,
                'anonymized_at' => now(),
            ])->save();
        });

        return $originalEmail;
    }
}
