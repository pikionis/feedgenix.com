<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Translation\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Language $resource
 */
final class LanguageResource extends JsonResource
{
    /**
     * @return array{code: string, name: string, native_name: string, direction: string}
     */
    public function toArray(Request $request): array
    {
        return self::shape($this->resource);
    }

    /**
     * @return array{code: string, name: string, native_name: string, direction: string}
     */
    public static function shape(?Language $language): array
    {
        return [
            'code' => (string) $language?->code,
            'name' => (string) $language?->name,
            'native_name' => (string) $language?->native_name,
            'direction' => (string) $language?->direction,
        ];
    }
}
