<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use InvalidArgumentException;
use LogicException;
use phpseclib4\Crypt\PublicKeyLoader;
use phpseclib4\Exception\BaseException;
use SensitiveParameter;

/**
 * The login of a feed source (ADR 0030). Encrypted on FeedSource, and never returned by the API: it
 * shows the kind only. Its values cannot break out of a request header, and it hides them from
 * dumps and stack traces.
 */
final readonly class Credentials
{
    public const string TYPE_PASSWORD = 'password';

    public const string TYPE_HEADER = 'header';

    public const string TYPE_KEY = 'key';

    /** No control characters (a line break would inject a header), and no colon in a username (RFC 7617). */
    public const string USERNAME_PATTERN = '/\A[^\x00-\x1F\x7F:]+\z/u';

    public const string SECRET_PATTERN = '/\A[^\x00-\x1F\x7F]+\z/u';

    /** An HTTP token (RFC 9110). */
    public const string HEADER_NAME_PATTERN = '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/';

    /** @param array<string, string> $values */
    private function __construct(
        public string $type,
        #[SensitiveParameter]
        private array $values,
    ) {}

    public static function password(
        #[SensitiveParameter]
        string $username,
        #[SensitiveParameter]
        string $password,
    ): self {
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1 || preg_match(self::SECRET_PATTERN, $password) !== 1) {
            throw new InvalidArgumentException('This username or password cannot be sent.');
        }

        return new self(self::TYPE_PASSWORD, ['username' => $username, 'password' => $password]);
    }

    public static function header(
        string $name,
        #[SensitiveParameter]
        string $value,
    ): self {
        if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1 || self::isReservedHeader($name) || preg_match(self::SECRET_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('This header cannot be sent.');
        }

        return new self(self::TYPE_HEADER, ['name' => $name, 'value' => $value]);
    }

    /** An SSH private key that must open here and now: an unreadable one is refused when saved. */
    public static function key(
        #[SensitiveParameter]
        string $username,
        #[SensitiveParameter]
        string $privateKey,
        #[SensitiveParameter]
        ?string $passphrase = null,
    ): self {
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new InvalidArgumentException('This username cannot be sent.');
        }

        try {
            PublicKeyLoader::loadPrivateKey($privateKey, $passphrase);
        } catch (BaseException) {
            throw new InvalidArgumentException('This private key cannot be read. Paste the whole key, with its passphrase if it has one.');
        }

        $values = ['username' => $username, 'private_key' => $privateKey];

        return new self(self::TYPE_KEY, $passphrase === null ? $values : [...$values, 'passphrase' => $passphrase]);
    }

    /**
     * Headers that shape the request itself rather than prove who asks: the fetcher and curl set
     * these, and overriding them could smuggle a request or undo the conditional download.
     */
    public static function isReservedHeader(string $name): bool
    {
        $name = strtolower($name);

        return in_array($name, ['host', 'connection', 'content-length', 'transfer-encoding', 'te', 'trailer', 'upgrade', 'keep-alive', 'expect', 'range', 'accept', 'accept-encoding'], true)
            || str_starts_with($name, 'if-')
            || str_starts_with($name, 'proxy-');
    }

    /**
     * What came out of storage, or a validated request.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromArray(
        #[SensitiveParameter]
        array $stored,
    ): self {
        $text = static fn (string $key): string => is_string($stored[$key] ?? null)
            ? $stored[$key]
            : throw new InvalidArgumentException('This stored login is incomplete.');

        return match ($stored['type'] ?? null) {
            self::TYPE_PASSWORD => self::password($text('username'), $text('password')),
            self::TYPE_HEADER => self::header($text('name'), $text('value')),
            self::TYPE_KEY => self::key($text('username'), $text('private_key'), is_string($stored['passphrase'] ?? null) ? $stored['passphrase'] : null),
            default => throw new InvalidArgumentException('This stored login is of an unknown kind.'),
        };
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['type' => $this->type, ...$this->values];
    }

    /**
     * Why this login may not be used on this address; null when it may. Plain FTP is allowed, with a
     * warning on the form: many shops' hosts offer nothing else (ADR 0031).
     */
    public function refusalFor(Target $target): ?string
    {
        return match (true) {
            $target->scheme === 'http' => 'A login is only ever sent over https. Use an https:// address.',
            $this->type === self::TYPE_HEADER && ! $target->isWeb() => 'A header only works on an https:// address. Use a username and password here.',
            $this->type === self::TYPE_KEY && $target->scheme !== 'sftp' => 'A private key only works on an sftp:// address.',
            default => null,
        };
    }

    /**
     * What a file server is told; only for a username and password.
     *
     * @return array{0: non-empty-string, 1: non-empty-string}
     */
    public function usernameAndPassword(): array
    {
        $username = $this->values['username'] ?? '';
        $password = $this->values['password'] ?? '';
        if ($username === '' || $password === '') {
            throw new LogicException('This login has no username and password.');
        }

        return [$username, $password];
    }

    /**
     * What an SSH server is shown; only for a private key.
     *
     * @return array{0: string, 1: string, 2: ?string} the username, the key and its passphrase
     */
    public function usernameAndKey(): array
    {
        if ($this->type !== self::TYPE_KEY) {
            throw new LogicException('This login has no private key.');
        }

        return [$this->values['username'], $this->values['private_key'], $this->values['passphrase'] ?? null];
    }

    /** @return array<string, string> the request headers that carry this login */
    public function httpHeaders(): array
    {
        return match ($this->type) {
            self::TYPE_PASSWORD => ['Authorization' => 'Basic '.base64_encode($this->values['username'].':'.$this->values['password'])],
            self::TYPE_HEADER => [$this->values['name'] => $this->values['value']],
            default => throw new LogicException('This login cannot go in a request header.'),
        };
    }

    /** @return array{type: string} */
    public function __debugInfo(): array
    {
        return ['type' => $this->type];
    }
}
