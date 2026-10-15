<?php

namespace App\Veil;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Veil;

final class SmokeExportTable implements VeilTable
{
    public function table(): string
    {
        return 'smoke_records';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'email' => 'redacted@example.invalid',
            'name' => fn ($original, $row) => 'masked-' . $row['id'] . '-' . strtolower($original),
            'note' => Veil::unchanged(),
        ];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return null;
    }
}
