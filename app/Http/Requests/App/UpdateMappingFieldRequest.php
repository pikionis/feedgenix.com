<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use App\Domain\Mapping\Data\Recipe;
use Illuminate\Foundation\Http\FormRequest;

/** Set one field of a mapping: a column, a composition of columns, or nothing. */
final class UpdateMappingFieldRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The mapping version you loaded. */
            'version' => ['required', 'integer', 'min:1'],
            /** A canonical field key, e.g. `title`. */
            'target' => ['required', 'string', 'max:48'],
            /** BCP-47, for language-specific fields only. */
            'locale' => ['nullable', 'string', 'max:16'],
            /** The column that fills it alone; omit both column and recipe to leave it empty. */
            'column' => ['nullable', 'string', 'max:1000', 'prohibits:recipe'],
            'recipe' => ['nullable', 'array'],
            'recipe.sources' => ['required_with:recipe', 'array', 'min:1', 'max:30'],
            'recipe.sources.*.column' => ['required', 'string', 'max:1000'],
            'recipe.sources.*.label' => ['nullable', 'string', 'max:60'],
            'recipe.separator' => ['nullable', 'string', 'max:10'],
            'recipe.label_separator' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function recipe(): ?Recipe
    {
        $recipe = $this->validated('recipe');

        return is_array($recipe) ? Recipe::fromArray($recipe) : null;
    }
}
