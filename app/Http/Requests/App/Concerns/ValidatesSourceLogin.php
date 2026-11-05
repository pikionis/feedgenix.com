<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Concerns;

use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\SftpServer;
use Closure;
use InvalidArgumentException;

/**
 * The login a customer may set on a URL feed: a username and password, one request header, or an SSH
 * private key; and the key an SFTP server must prove it holds.
 */
trait ValidatesSourceLogin
{
    /** @return array<string, mixed> */
    protected function loginRules(): array
    {
        return [
            /**
             * The source's login: `password` needs username and password, `header` needs name and
             * value, `key` needs username and private_key (and passphrase, if it has one). An empty
             * object is not "no login": only an explicit null removes one.
             *
             * @var array{type: 'password'|'header'|'key', username?: string, password?: string, name?: string, value?: string, private_key?: string, passphrase?: string}|null
             */
            'auth' => ['sometimes', 'nullable', 'array:type,username,password,name,value,private_key,passphrase', 'required_array_keys:type'],
            'auth.type' => ['required_with:auth', 'string', 'in:'.Credentials::TYPE_PASSWORD.','.Credentials::TYPE_HEADER.','.Credentials::TYPE_KEY],
            'auth.username' => ['nullable', 'required_if:auth.type,'.Credentials::TYPE_PASSWORD.','.Credentials::TYPE_KEY, 'string', 'max:255', 'regex:'.Credentials::USERNAME_PATTERN],
            'auth.password' => ['nullable', 'required_if:auth.type,'.Credentials::TYPE_PASSWORD, 'string', 'max:1024', 'regex:'.Credentials::SECRET_PATTERN],
            'auth.name' => ['nullable', 'required_if:auth.type,'.Credentials::TYPE_HEADER, 'string', 'max:64', 'regex:'.Credentials::HEADER_NAME_PATTERN, self::notReservedHeader()],
            'auth.value' => ['nullable', 'required_if:auth.type,'.Credentials::TYPE_HEADER, 'string', 'max:4096', 'regex:'.Credentials::SECRET_PATTERN],
            'auth.private_key' => ['nullable', 'required_if:auth.type,'.Credentials::TYPE_KEY, 'string', 'max:16384', $this->readablePrivateKey()],
            'auth.passphrase' => ['nullable', 'string', 'max:1024'],
            /**
             * The SHA-256 fingerprint the SFTP server must show, as `ssh-keygen -l` prints it. Null
             * trusts the key it shows on the next fetch.
             */
            'host_key' => ['sometimes', 'nullable', 'string', 'regex:'.SftpServer::FINGERPRINT_PATTERN],
        ];
    }

    public function login(): ?Credentials
    {
        $auth = $this->validated('auth');

        return is_array($auth) ? Credentials::fromArray($auth) : null;
    }

    public function hostKey(): ?string
    {
        $key = $this->validated('host_key');

        return is_string($key) ? $key : null;
    }

    private static function notReservedHeader(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && Credentials::isReservedHeader($value)) {
                $fail("FeedGenix sets {$value} itself. Use the header that carries your key.");
            }
        };
    }

    private function readablePrivateKey(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $passphrase = $this->input('auth.passphrase');
            if ($this->input('auth.type') !== Credentials::TYPE_KEY || ! is_string($value)) {
                return;
            }

            try {
                Credentials::key('check', $value, is_string($passphrase) ? $passphrase : null);
            } catch (InvalidArgumentException $unreadable) {
                $fail($unreadable->getMessage());
            }
        };
    }
}
