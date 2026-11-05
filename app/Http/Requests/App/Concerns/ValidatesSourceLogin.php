<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Concerns;

use App\Domain\Ingest\Fetch\Credentials;
use Closure;

/** The login a customer may set on a URL feed: a username and password, or one request header. */
trait ValidatesSourceLogin
{
    /** @return array<string, mixed> */
    protected function loginRules(): array
    {
        return [
            /**
             * The source's login: `password` needs username and password, `header` needs name and
             * value. An empty object is not "no login": only an explicit null removes one.
             *
             * @var array{type: 'password'|'header', username?: string, password?: string, name?: string, value?: string}|null
             */
            'auth' => ['sometimes', 'nullable', 'array:type,username,password,name,value', 'required_array_keys:type'],
            'auth.type' => ['required_with:auth', 'string', 'in:'.Credentials::TYPE_PASSWORD.','.Credentials::TYPE_HEADER],
            'auth.username' => ['nullable', 'required_if:auth.type,'.Credentials::TYPE_PASSWORD, 'string', 'max:255', 'regex:'.Credentials::USERNAME_PATTERN],
            'auth.password' => ['nullable', 'required_if:auth.type,'.Credentials::TYPE_PASSWORD, 'string', 'max:1024', 'regex:'.Credentials::SECRET_PATTERN],
            'auth.name' => ['nullable', 'required_if:auth.type,'.Credentials::TYPE_HEADER, 'string', 'max:64', 'regex:'.Credentials::HEADER_NAME_PATTERN, self::notReservedHeader()],
            'auth.value' => ['nullable', 'required_if:auth.type,'.Credentials::TYPE_HEADER, 'string', 'max:4096', 'regex:'.Credentials::SECRET_PATTERN],
        ];
    }

    public function login(): ?Credentials
    {
        $auth = $this->validated('auth');

        return is_array($auth) ? Credentials::fromArray($auth) : null;
    }

    private static function notReservedHeader(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && Credentials::isReservedHeader($value)) {
                $fail("FeedGenix sets {$value} itself. Use the header that carries your key.");
            }
        };
    }
}
