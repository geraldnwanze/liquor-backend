<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A slug stays taken after its record is soft-deleted, so restoring the record can never clash. */
final class UniqueSlug
{
    /** @param class-string<Model> $model */
    public static function make(string $model, string $source, ?string $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        $i = 2;

        while ($model::query()->withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
