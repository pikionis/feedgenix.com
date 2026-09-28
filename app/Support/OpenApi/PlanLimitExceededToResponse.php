<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use App\Domain\Billing\PlanLimitExceeded;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Support\Str;

/**
 * Documents the 402 plan-limit contract in the OpenAPI document, so the SPA's generated types know
 * the exact payload its upgrade dialog receives.
 */
final class PlanLimitExceededToResponse extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(PlanLimitExceeded::class);
    }

    public function toResponse(Type $type): Response
    {
        $body = (new OpenApiTypes\ObjectType)
            ->addProperty('error', (new OpenApiTypes\StringType)->enum(['plan_limit', 'plan_feature']))
            ->addProperty('limit', (new OpenApiTypes\StringType)->setDescription('The limit key or feature, e.g. "languages".'))
            ->addProperty('allowed', (new OpenApiTypes\IntegerType)->nullable(true))
            ->addProperty('used', (new OpenApiTypes\IntegerType)->nullable(true)->setDescription('Usage before the action.'))
            ->addProperty('requested', (new OpenApiTypes\IntegerType)->nullable(true)->setDescription('What the action would have brought usage to.'))
            ->addProperty('plan', (new OpenApiTypes\StringType)->setDescription('The plan in force.'))
            ->addProperty('upgrade_to', (new OpenApiTypes\StringType)->nullable(true)->setDescription('The cheapest plan that fits, or null (talk to us).'))
            ->addProperty('message', (new OpenApiTypes\StringType)->setDescription('A sentence for the person, naming the numbers.'))
            ->setRequired(['error', 'limit', 'allowed', 'used', 'requested', 'plan', 'upgrade_to', 'message']);

        return Response::make(402)
            ->setDescription('A plan limit was reached, or the feature is part of a higher plan')
            ->setContent('application/json', Schema::fromType($body));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', Str::start($type->name, '\\'), $this->components);
    }
}
