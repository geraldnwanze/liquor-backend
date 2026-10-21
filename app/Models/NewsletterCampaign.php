<?php

namespace App\Models;

use Database\Factories\NewsletterCampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['subject', 'body', 'status', 'created_by', 'sent_by', 'sent_at', 'recipient_count'])]
class NewsletterCampaign extends Model
{
    /** @use HasFactory<NewsletterCampaignFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    /** @return HasMany<NewsletterDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NewsletterDelivery::class, 'campaign_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'recipient_count' => 'integer'];
    }
}
