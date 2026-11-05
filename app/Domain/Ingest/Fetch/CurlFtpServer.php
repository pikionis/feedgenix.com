<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use CurlHandle;

/**
 * The real FTP(S) client: curl, pinned to the checked address with CURLOPT_RESOLVE, passive mode
 * only, and the address in a passive reply ignored: a server could otherwise send the data
 * connection to an internal address (CURLOPT_FTP_SKIP_PASV_IP). `ftps://` demands TLS before the
 * login is sent (explicit AUTH TLS; implicit on port 990), certificates verified. Bytes arrive
 * exactly as stored: always a binary transfer.
 */
final class CurlFtpServer implements FileServer
{
    /** Names PHP's curl extension does not define. */
    private const int CURLE_LOGIN_DENIED = 67;

    private const int CURLE_REMOTE_FILE_NOT_FOUND = 78;

    public function info(Target $target, string $address, ?Credentials $credentials, ?string $hostKey, FetchSettings $settings): FileInfo
    {
        $handle = $this->handle($target, $address, $credentials, $settings);
        curl_setopt_array($handle, [CURLOPT_NOBODY => true, CURLOPT_FILETIME => true]);

        if (curl_exec($handle) === false) {
            throw $this->failure(curl_errno($handle), curl_error($handle), $credentials);
        }

        $size = (int) curl_getinfo($handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $modifiedAt = curl_getinfo($handle, CURLINFO_FILETIME);
        // "Unknown" is -1, which comes back as 2^32 - 1 where a C long is 32 bits wide.
        $known = $modifiedAt > 0 && $modifiedAt !== 0xFFFFFFFF;

        return new FileInfo($size >= 0 ? $size : null, $known ? $modifiedAt : null);
    }

    public function download(Target $target, string $address, ?Credentials $credentials, ?string $hostKey, $sink, int $maxBytes, FetchSettings $settings): int
    {
        $received = 0;
        $tooLarge = false;

        $handle = $this->handle($target, $address, $credentials, $settings);
        curl_setopt_array($handle, [
            CURLOPT_MAXFILESIZE_LARGE => $maxBytes,
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
        $errno = curl_errno($handle);

        if ($tooLarge || $errno === CURLE_FILESIZE_EXCEEDED) {
            throw FetchFailed::because(FetchFailure::TooLarge, 'The file is larger than one import may be.');
        }

        if ($ok === false) {
            throw $this->failure($errno, curl_error($handle), $credentials);
        }

        return $received;
    }

    private function handle(Target $target, string $address, ?Credentials $credentials, FetchSettings $settings): CurlHandle
    {
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;
        $tls = $target->scheme === 'ftps';
        // curl reads ftps:// as implicit TLS. Explicit TLS is ftp:// with TLS demanded.
        $url = $tls && $target->port !== 990 ? (string) preg_replace('/^ftps:/i', 'ftp:', $target->url) : $target->url;

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => ["{$target->host}:{$target->port}:{$pinned}"],
            CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS,
            CURLOPT_USE_SSL => $tls ? CURLUSESSL_ALL : CURLUSESSL_NONE,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_TRANSFERTEXT => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $settings->connectTimeout,
            CURLOPT_TIMEOUT => $settings->timeout,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 120,
            CURLOPT_NOSIGNAL => true,
        ]);

        if ($credentials instanceof Credentials) {
            [$username, $password] = $credentials->usernameAndPassword();
            curl_setopt($handle, CURLOPT_USERNAME, $username);
            curl_setopt($handle, CURLOPT_PASSWORD, $password);
        }

        return $handle;
    }

    private function failure(int $errno, string $error, ?Credentials $credentials): FetchFailed
    {
        return match (true) {
            in_array($errno, [self::CURLE_LOGIN_DENIED, CURLE_FTP_USER_PASSWORD_INCORRECT], true) => $credentials instanceof Credentials
                ? FetchFailed::because(FetchFailure::LoginRefused, 'The server refused the login. Check it in the feed’s source settings.')
                : FetchFailed::because(FetchFailure::LoginNeeded, 'The server needs a login. Add one in the feed’s source settings.'),
            in_array($errno, [self::CURLE_REMOTE_FILE_NOT_FOUND, CURLE_FTP_COULDNT_RETR_FILE, CURLE_FTP_ACCESS_DENIED], true) => FetchFailed::because(FetchFailure::NotFound, 'The server has no such file, or this login may not read it. Check the path.'),
            $errno === CURLE_FTP_SSL_FAILED => FetchFailed::because(FetchFailure::TlsFailed, 'The server does not offer an encrypted connection, so the login was not sent. If it only speaks plain FTP, use an ftp:// address.'),
            in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER, CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE, CURLE_SSL_PINNEDPUBKEYNOTMATCH], true) => FetchFailed::because(FetchFailure::TlsFailed, 'The server’s certificate is not valid, so the login was not sent.'),
            $errno === CURLE_OPERATION_TIMEDOUT => FetchFailed::because(FetchFailure::Timeout, 'The server took too long to send the feed.'),
            $errno === CURLE_PARTIAL_FILE => FetchFailed::because(FetchFailure::Truncated, 'The download was cut off before the end. Try again.'),
            in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_RECV_ERROR, CURLE_SEND_ERROR, CURLE_GOT_NOTHING, CURLE_FTP_WEIRD_SERVER_REPLY, CURLE_FTP_WEIRD_PASV_REPLY, CURLE_FTP_WEIRD_227_FORMAT, CURLE_FTP_CANT_GET_HOST], true) => FetchFailed::because(FetchFailure::ConnectFailed, 'The server could not be reached.'),
            default => FetchFailed::because(FetchFailure::ConnectFailed, 'The download failed ('.substr($error, 0, 120).').'),
        };
    }
}
