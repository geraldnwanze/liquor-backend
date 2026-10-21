<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'date_of_birth', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /** @var array<int, string>|null */
    private ?array $permissionSlugs = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'is_active' => 'boolean',
            'suspended_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** @return HasMany<Address, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<CustomerNote, $this> */
    public function supportNotes(): HasMany
    {
        return $this->hasMany(CustomerNote::class, 'customer_id');
    }

    /** @return HasMany<Cart, $this> */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /** @return HasMany<AccountDeletionRequest, $this> */
    public function deletionRequests(): HasMany
    {
        return $this->hasMany(AccountDeletionRequest::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains('slug', $slug);
    }

    public function isStaff(): bool
    {
        return $this->roles->isNotEmpty();
    }

    /** Every permission this user's roles grant. A super administrator is shown as ["*"]. @return list<string> */
    public function permissionSlugs(): array
    {
        if ($this->hasRole('super-admin')) {
            return ['*'];
        }

        return $this->permissionSlugs ??= $this->roles()->with('permissions:id,slug')->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('slug'))
            ->unique()->values()->all();
    }

    public function hasPermission(string $slug): bool
    {
        $slugs = $this->permissionSlugs();

        return $slugs === ['*'] || in_array($slug, $slugs, true);
    }
}
