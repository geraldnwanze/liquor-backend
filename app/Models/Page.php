<?php

namespace App\Models;

use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title', 'slug', 'body', 'meta_description', 'status', 'show_in_footer', 'sort_order', 'updated_by'])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['show_in_footer' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @param Builder<Page> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::PUBLISHED);
    }
}
