<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'status', 'reason', 'requested_at', 'processed_at', 'processed_by', 'staff_note'])]
class AccountDeletionRequest extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
