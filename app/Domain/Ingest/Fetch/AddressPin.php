<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/**
 * The one address a fetch may connect to: the host is resolved once, every address it answers must
 * be public, and the first one is used, so DNS rebinding cannot switch the connection to an
 * internal address (ADR 0013).
 */
final readonly class AddressPin
{
    public function __construct(
        private Resolver $resolver,
        private bool $allowPrivateNetworks,
    ) {}

    /** @throws FetchFailed */
    public function for(Target $target): string
    {
        $addresses = $this->resolver->resolve($target->host);
        if ($addresses === []) {
            throw FetchFailed::because(FetchFailure::DnsFailed, "The host {$target->host} does not exist.");
        }

        if (! $this->allowPrivateNetworks) {
            foreach ($addresses as $address) {
                if (! AddressPolicy::isPublic($address)) {
                    throw FetchFailed::because(FetchFailure::BlockedAddress, "{$target->host} points to a private or reserved network address, which FeedGenix never fetches from.");
                }
            }
        }

        return $addresses[0];
    }
}
