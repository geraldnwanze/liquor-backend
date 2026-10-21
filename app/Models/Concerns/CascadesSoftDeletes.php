<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Soft-deleting a parent soft-deletes the children listed in `$cascadesSoftDeletes` (relation names); restoring the
 * parent restores the children that went with it. Children deleted separately, more than a minute before the parent,
 * stay deleted. Database "cascade on delete" only fires for real deletes, so without this a deleted product would
 * leave live variants, photos and reviews behind.
 *
 * @mixin Model
 */
trait CascadesSoftDeletes
{
    public static function bootCascadesSoftDeletes(): void
    {
        static::deleting(function (Model $parent): void {
            if (method_exists($parent, 'isForceDeleting') && $parent->isForceDeleting()) {
                return;
            }
            foreach ($parent->cascadesSoftDeletes ?? [] as $relation) {
                $parent->{$relation}()->get()->each->delete();
            }
        });

        static::restoring(function (Model $parent): void {
            $since = ($parent->deleted_at ?? now())->copy()->subMinute();
            foreach ($parent->cascadesSoftDeletes ?? [] as $relation) {
                $parent->{$relation}()->onlyTrashed()->where('deleted_at', '>=', $since)->get()->each->restore();
            }
        });
    }
}
