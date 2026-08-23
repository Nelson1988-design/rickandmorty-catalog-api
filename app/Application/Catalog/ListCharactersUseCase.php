<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Models\Character;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListCharactersUseCase
{
    /**
     * @return LengthAwarePaginator<int, Character>
     */
    public function execute(CharacterFilters $filters, int $perPage): LengthAwarePaginator
    {
        return Character::query()
            // Loaded up front so rendering the page does not go back to the
            // database once per row.
            ->with(['origin', 'currentLocation'])
            // A partial match, because someone typing "rick" expects to find
            // "Toxic Rick" too. It is also why no index was put on this column:
            // a B-tree cannot help a search that begins with a wildcard, and an
            // index nobody uses still slows every write of the synchronisation.
            ->when(
                $filters->name !== null,
                fn ($query) => $query->where('name', 'like', '%'.$filters->name.'%'),
            )
            // These two are exact matches on low cardinality columns, which is
            // precisely the case an index does help with — and where the two
            // indexes this table has were spent.
            ->when(
                $filters->status !== null,
                fn ($query) => $query->where('status', $filters->status->value),
            )
            ->when(
                $filters->species !== null,
                fn ($query) => $query->where('species', $filters->species),
            )
            ->orderBy('external_id')
            ->paginate($perPage);
    }
}
