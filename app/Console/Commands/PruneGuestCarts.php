<?php

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('carts:prune')]
#[Description('Delete guest carts that have been inactive past the retention period')]
class PruneGuestCarts extends Command
{
    public function handle(): int
    {
        $days = (int) config('commerce.guest_cart_retention_days');
        $deleted = Cart::query()->whereNull('user_id')->where('last_activity_at', '<', now()->subDays($days))->delete();
        $this->info("Deleted {$deleted} guest cart(s).");

        return self::SUCCESS;
    }
}
