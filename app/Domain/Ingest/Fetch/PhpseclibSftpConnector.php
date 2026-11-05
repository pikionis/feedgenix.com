<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use phpseclib4\Net\SFTP;

/**
 * Opens the socket itself, to the checked address, and hands it to the SSH library, so no second DNS
 * lookup can happen (DNS rebinding) and the connect timeout is the fetcher's own.
 */
final class PhpseclibSftpConnector implements SftpConnector
{
    /** A stalled server is given up on after this long without a packet, as on the web. */
    private const int STALL_SECONDS = 120;

    public function open(string $address, int $port, FetchSettings $settings): SftpSession
    {
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;

        set_error_handler(static fn (): bool => true); // a refused connection is a failure below, not a warning
        try {
            $socket = stream_socket_client("tcp://{$pinned}:{$port}", $errno, $error, $settings->connectTimeout);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw FetchFailed::because(FetchFailure::ConnectFailed, 'The server could not be reached.');
        }

        $sftp = new SFTP($socket, $port, $settings->connectTimeout);
        $sftp->setTimeout(self::STALL_SECONDS);

        return new PhpseclibSftpSession($sftp);
    }
}
