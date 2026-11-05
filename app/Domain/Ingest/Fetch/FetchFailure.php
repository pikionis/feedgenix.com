<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/** Why a feed address could not be fetched. Rate limits are not failures of the feed: retry later. */
enum FetchFailure: string
{
    case InvalidUrl = 'invalid_url';
    case NotFound = 'not_found';
    case BlockedAddress = 'blocked_address';
    case DnsFailed = 'dns_failed';
    case ConnectFailed = 'connect_failed';
    case Timeout = 'timeout';
    case TlsFailed = 'tls_failed';
    case HttpStatus = 'http_status';
    case RateLimited = 'rate_limited';
    case LoginNeeded = 'login_needed';
    case LoginRefused = 'login_refused';
    case TooLarge = 'too_large';
    case TooManyRedirects = 'too_many_redirects';
    case Truncated = 'truncated';
}
