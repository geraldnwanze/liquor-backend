<?php

namespace App\Console\Commands;

use App\Support\SoftDeletable;
use Illuminate\Console\Command;

class TrashRestore extends Command
{
    protected $signature = 'trash:restore {type : product, variant, brand, category, page, discount, review…} {id : the record\'s UUID (see trash:list)}';

    protected $description = 'Bring a soft-deleted record back (a product also brings back the variants, photos and reviews deleted with it)';

    public function handle(): int
    {
        $model = SoftDeletable::TYPES[$this->argument('type')] ?? null;
        if (! $model) {
            $this->error('Unknown type. Choose from: '.implode(', ', array_keys(SoftDeletable::TYPES)));

            return self::FAILURE;
        }

        $record = $model::onlyTrashed()->find($this->argument('id'));
        if (! $record) {
            $this->error('No deleted record with that id.');

            return self::FAILURE;
        }

        $record->restore();
        $this->info('Restored.');

        return self::SUCCESS;
    }
}
