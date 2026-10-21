<?php

namespace App\Console\Commands;

use App\Support\SoftDeletable;
use Illuminate\Console\Command;

class TrashList extends Command
{
    protected $signature = 'trash:list {type? : product, variant, brand, category, page, discount, review… (all types when omitted)}';

    protected $description = 'List soft-deleted records, newest deletion first';

    public function handle(): int
    {
        $types = $this->argument('type') ? [$this->argument('type')] : array_keys(SoftDeletable::TYPES);
        $rows = [];

        foreach ($types as $type) {
            $model = SoftDeletable::TYPES[$type] ?? null;
            if (! $model) {
                $this->error("Unknown type \"{$type}\". Choose from: ".implode(', ', array_keys(SoftDeletable::TYPES)));

                return self::FAILURE;
            }
            foreach ($model::onlyTrashed()->latest('deleted_at')->limit(200)->get() as $record) {
                $rows[] = [$type, $record->getKey(), $record->name ?? $record->title ?? $record->code ?? $record->subject ?? $record->sku ?? '', $record->deleted_at->toDateTimeString()];
            }
        }

        $rows ? $this->table(['Type', 'Id', 'Name', 'Deleted at'], $rows) : $this->info('Nothing has been deleted.');

        return self::SUCCESS;
    }
}
