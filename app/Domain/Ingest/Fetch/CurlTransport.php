<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use CurlHandle;

/**
 * The real transport: curl, pinned to the checked address with CURLOPT_RESOLVE, so a second DNS
 * answer can never redirect the connection (DNS rebinding). No redirects (the fetcher checks
 * each one), http and https only, TLS verified, and a write callback that stops at the size cap.
 * Bytes arrive exactly as sent: a gzip transfer encoding is kept and inflated, with its integrity
 * checked, by the ingest preparer.
 */
final class CurlTransport implements Transport
{
    public function get(Target $target, string $address, array $headers, $sink, int $maxBytes, FetchSettings $settings): TransportResponse
    {
        $received = 0;
        $tooLarge = false;
        $responseHeaders = [];
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $target->url,
            CURLOPT_RESOLVE => ["{$target->host}:{$target->port}:{$pinned}"],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $settings->connectTimeout,
            CURLOPT_TIMEOUT => $settings->timeout,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => $settings->userAgent,
            CURLOPT_MAXFILESIZE_LARGE => $maxBytes,
            CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => "{$name}: {$value}", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $curl, string $line) use (&$responseHeaders): int {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (after 100 Continue): forget the old headers
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $curl, string $data) use ($sink, $maxBytes, &$received, &$tooLarge): int {
                $received += strlen($data);
                if ($received > $maxBytes) {
                    $tooLarge = true;

                    return 0; // abort
                }

                return (int) fwrite($sink, $data);
            },
        ]);

        $ok = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);

        if ($tooLarge || $errno === CURLE_FILESIZE_EXCEEDED) {
            throw FetchFailed::because(FetchFailure::TooLarge, 'The file is larger than one import may be.');
        }

        if ($ok === false) {
            throw $this->failure($errno, $error);
        }

        return new TransportResponse($status, $responseHeaders, $received);
    }

    private function failure(int $errno, string $error): FetchFailed
    {
        return match (true) {
            $errno === CURLE_OPERATION_TIMEDOUT => FetchFailed::because(FetchFailure::Timeout, 'The server took too long to send the feed.'),
            $errno === CURLE_PARTIAL_FILE => FetchFailed::because(FetchFailure::Truncated, 'The download was cut off before the end. Try again.'),
            in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_RECV_ERROR, CURLE_SEND_ERROR, CURLE_GOT_NOTHING], true) => FetchFailed::because(FetchFailure::ConnectFailed, 'The server could not be reached.'),
            in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER, CURLE_SSL_CACERT_BADFILE, CURLE_SSL_PINNEDPUBKEYNOTMATCH], true) => FetchFailed::because(FetchFailure::TlsFailed, 'The server’s HTTPS certificate is not valid, so the feed was not fetched.'),
            default => FetchFailed::because(FetchFailure::ConnectFailed, 'The download failed ('.substr($error, 0, 120).').'),
        };
    }
}
