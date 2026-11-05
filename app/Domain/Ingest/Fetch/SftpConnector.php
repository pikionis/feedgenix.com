<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

interface SftpConnector
{
    /**
     * Opens a connection to the checked address and nowhere else.
     *
     * @throws FetchFailed
     */
    public function open(string $address, int $port, FetchSettings $settings): SftpSession;
}
