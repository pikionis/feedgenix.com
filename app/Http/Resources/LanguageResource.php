<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Translation\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A language from the registry, by its BCP-47 code.
 *
 * @property Language $resource
 */
final class LanguageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'native_name' => $this->resource->native_name,
            'direction' => $this->resource->direction,
        ];
    }
}
