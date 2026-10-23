<?php

declare(strict_types=1);

namespace App\Domain\Translation\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The text a person translated or approved changed in the feed meanwhile: reload it first (409). */
final class SourceChanged extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The text changed in the feed since you loaded it. Reload it and look again.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => 'source_changed', 'message' => $this->getMessage()], 409);
    }
}
