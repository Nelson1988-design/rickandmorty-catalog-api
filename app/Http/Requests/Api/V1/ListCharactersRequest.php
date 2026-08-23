<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Application\Catalog\CharacterFilters;
use App\Domain\Catalog\Enums\CharacterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListCharactersRequest extends FormRequest
{
    /**
     * The stored values are lower case, but nobody types them that way. A
     * caller asking for `Alive` means the same thing as one asking for `alive`,
     * so the input is normalised before it is checked rather than rejected on a
     * difference that carries no meaning.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('status') && is_string($this->input('status'))) {
            $this->merge(['status' => strtolower($this->string('status')->toString())]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            // An unrecognised status is answered rather than quietly ignored.
            // Silently returning the whole catalogue to someone who mistyped a
            // filter is worse than telling them.
            'status' => ['sometimes', Rule::enum(CharacterStatus::class)],
            'species' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function filters(): CharacterFilters
    {
        $status = $this->input('status');

        return new CharacterFilters(
            name: $this->input('name'),
            status: is_string($status) ? CharacterStatus::from($status) : null,
            species: $this->input('species'),
        );
    }
}
