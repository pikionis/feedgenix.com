<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\FetchSettings;
use App\Domain\Ingest\Fetch\SftpConnector;
use App\Domain\Ingest\Fetch\SftpSession;
use Closure;

/** A scripted SFTP server that records everything it is told, in order. */
final class FakeSftp implements SftpConnector, SftpSession
{
    /** @var list<string> */
    public array $told = [];

    public ?string $publicKey = 'ssh-ed25519 '.'AAAAC3NzaC1lZDI1NTE5AAAAIGVk';

    public bool $acceptLogin = true;

    public ?string $connectedTo = null;

    /** @var array{size: ?int, modified_at: ?int, directory: bool} */
    public array $stat = ['size' => 3, 'modified_at' => 1_790_000_000, 'directory' => false];

    public string $bytes = 'sku';

    public function open(string $address, int $port, FetchSettings $settings): SftpSession
    {
        $this->connectedTo = "{$address}:{$port}";
        $this->told[] = 'connect';

        return $this;
    }

    public function hostKey(): ?string
    {
        return $this->publicKey;
    }

    public function login(Credentials $credentials): bool
    {
        $this->told[] = 'login';

        return $this->acceptLogin;
    }

    public function stat(string $path): array
    {
        $this->told[] = "stat {$path}";

        return $this->stat;
    }

    public function read(string $path, Closure $chunk, int $maxBytes): void
    {
        $this->told[] = "read {$path} up to {$maxBytes}";
        foreach (str_split($this->bytes, 2) as $piece) {
            $chunk($piece);
        }
    }
}
