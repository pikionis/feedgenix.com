<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use Closure;
use phpseclib4\Crypt\PublicKeyLoader;
use phpseclib4\Exception\BaseException;
use phpseclib4\Exception\FileSystemException;
use phpseclib4\Exception\TimeoutException;
use phpseclib4\Net\SFTP;
use phpseclib4\Net\SFTP\FileType;

/**
 * The real SFTP session. The library's exceptions stop here: what it says is never shown, because it
 * can name paths and server details, and each becomes a failure in the customer's words.
 */
final readonly class PhpseclibSftpSession implements SftpSession
{
    public function __construct(
        private SFTP $sftp,
    ) {}

    public function hostKey(): ?string
    {
        return $this->guarded(fn (): ?string => $this->sftp->getServerPublicHostKey());
    }

    public function login(Credentials $credentials): bool
    {
        return $this->guarded(function () use ($credentials): bool {
            if ($credentials->type === Credentials::TYPE_KEY) {
                [$username, $key, $passphrase] = $credentials->usernameAndKey();

                return $this->sftp->login($username, PublicKeyLoader::loadPrivateKey($key, $passphrase));
            }

            [$username, $password] = $credentials->usernameAndPassword();

            return $this->sftp->login($username, $password);
        });
    }

    public function stat(string $path): array
    {
        return $this->guarded(function () use ($path): array {
            $stat = $this->sftp->stat($path);

            return [
                'size' => isset($stat['size']) ? (int) $stat['size'] : null,
                'modified_at' => isset($stat['mtime']) ? (int) $stat['mtime'] : null,
                'directory' => ($stat['type'] ?? null) === FileType::DIRECTORY,
            ];
        });
    }

    public function read(string $path, Closure $chunk, int $maxBytes): void
    {
        $this->guarded(fn (): ?string => $this->sftp->get($path, $chunk, 0, $maxBytes));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function guarded(Closure $call): mixed
    {
        try {
            return $call();
        } catch (TimeoutException) {
            throw FetchFailed::because(FetchFailure::Timeout, 'The server took too long to answer.');
        } catch (FileSystemException) {
            throw FetchFailed::because(FetchFailure::NotFound, 'The server has no such file, or this login may not read it. Check the path.');
        } catch (BaseException) {
            throw FetchFailed::because(FetchFailure::ConnectFailed, 'The connection to the server failed.');
        }
    }
}
