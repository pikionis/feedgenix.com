<?php

declare(strict_types=1);

namespace App\Domain\AI\Gateway;

use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\AI\Providers\Providers;
use App\Domain\AI\ValueSchema;
use LogicException;

/**
 * Last link: hands the attempt to its provider and checks the answer's shape. A cut-off answer is
 * terminal; so is any answer whose ids are not exactly the items' ids (missing, extra or renumbered):
 * the caller splits the unit, nothing is ever matched by position (legacy D12). An answer that
 * does not match its value schema is terminal too.
 */
final readonly class ProviderDispatch implements AiClient
{
    public function __construct(private Providers $providers) {}

    public function generate(AiRequest $request): AiResponse
    {
        $provider = $request->provider ?? throw new LogicException('The router names the provider before dispatch.');
        $result = $this->providers->get($provider)->call($request);

        if ($result->truncated) {
            throw AiTerminal::truncated($result->usage);
        }

        $expected = $request->itemIds();
        $returned = array_map(strval(...), array_keys($result->values));
        sort($expected, SORT_STRING);
        sort($returned, SORT_STRING);
        if ($returned !== $expected) {
            throw AiTerminal::misaligned($result->usage);
        }
        // Whatever the provider's schema mode promised, every answer is checked against its schema.
        foreach ($result->values as $id => $value) {
            if (! ValueSchema::accepts($request->valueSchema, $value)) {
                throw AiTerminal::invalidAnswer($result->usage, (string) $id);
            }
        }

        return new AiResponse($result->values, $result->usage, $result->model, $provider, $request->attempt, billed: $result->billed);
    }
}
