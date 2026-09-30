<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/** Why a feed URL could not be fetched. Rate limits are not failures of the feed: retry later. */
enum FetchFailure: string
{
    case InvalidUrl = 'invalid_url';
    case BlockedAddress = 'blocked_address';
    case DnsFailed = 'dns_failed';
    case ConnectFailed = 'connect_failed';
    case Timeout = 'timeout';
    case TlsFailed = 'tls_failed';
    case HttpStatus = 'http_status';
    case RateLimited = 'rate_limited';
    case TooLarge = 'too_large';
    case TooManyRedirects = 'too_many_redirects';
    case Truncated = 'truncated';
}
