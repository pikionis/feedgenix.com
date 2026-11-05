<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/**
 * An SFTP server (ADR 0032). Nothing of the customer's is sent until the server has proved it holds
 * the key FeedGenix expects: a server that answers with another key gets no username and no
 * password. With no key on file yet, the one it shows is trusted, and kept once a file has arrived.
 */
final readonly class SftpServer implements FileServer
{
    /** A SHA-256 fingerprint as `ssh-keygen -l` prints it. */
    public const string FINGERPRINT_PATTERN = '/\ASHA256:[A-Za-z0-9+\/]{43}\z/';

    public function __construct(
        private SftpConnector $connector,
    ) {}

    public function info(Target $target, string $address, ?Credentials $credentials, ?string $hostKey, FetchSettings $settings): FileInfo
    {
        [$session, $seen] = $this->open($target, $address, $credentials, $hostKey, $settings);

        $stat = $session->stat($target->filePath());
        if ($stat['directory']) {
            throw FetchFailed::because(FetchFailure::NotFound, 'This path is a folder. Give the path of the file.');
        }

        return new FileInfo($stat['size'], $stat['modified_at'], $seen);
    }

    public function download(Target $target, string $address, ?Credentials $credentials, ?string $hostKey, $sink, int $maxBytes, FetchSettings $settings): int
    {
        [$session] = $this->open($target, $address, $credentials, $hostKey, $settings);

        $received = 0;
        $session->read($target->filePath(), static function (string $data) use ($sink, $maxBytes, &$received): void {
            $received += strlen($data);
            if ($received > $maxBytes) {
                throw FetchFailed::because(FetchFailure::TooLarge, 'The file is larger than one import may be.');
            }

            fwrite($sink, $data);
        }, $maxBytes + 1);

        return $received;
    }

    /** `ssh-ed25519 AAAA…` as `SHA256:…`, the form `ssh-keygen -l` prints. */
    public static function fingerprint(string $publicKey): string
    {
        $blob = (string) base64_decode(explode(' ', $publicKey)[1] ?? '', true);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Connects, checks who answers, and only then logs in.
     *
     * @return array{0: SftpSession, 1: string} the session and the fingerprint of its server
     */
    private function open(Target $target, string $address, ?Credentials $credentials, ?string $hostKey, FetchSettings $settings): array
    {
        $session = $this->connector->open($address, $target->port, $settings);

        $key = $session->hostKey();
        if ($key === null) {
            throw FetchFailed::because(FetchFailure::HostKeyChanged, 'The server could not prove who it is, so the login was not sent.');
        }

        $seen = self::fingerprint($key);
        if ($hostKey !== null && $hostKey !== $seen) {
            throw FetchFailed::because(FetchFailure::HostKeyChanged, "The server’s key changed: it now shows {$seen}, and FeedGenix trusted {$hostKey}. The login was not sent. If your host moved or rebuilt the server, confirm the new key in the feed’s source settings.");
        }

        if (! $credentials instanceof Credentials) {
            throw FetchFailed::because(FetchFailure::LoginNeeded, 'An SFTP server needs a login. Add one in the feed’s source settings.');
        }

        if (! $session->login($credentials)) {
            throw FetchFailed::because(FetchFailure::LoginRefused, 'The server refused the login. Check it in the feed’s source settings.');
        }

        return [$session, $seen];
    }
}
