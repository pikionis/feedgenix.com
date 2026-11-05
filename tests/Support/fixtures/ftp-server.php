<?php

declare(strict_types=1);

/*
 * A tiny FTP server for tests, on 127.0.0.1 and an ephemeral port, in a process of its own. It holds
 * the files it is given, says what it is told to say, and writes down every command it receives.
 *
 * Usage: php ftp-server.php <config.json> <commands.log>. Prints the port once it listens.
 *
 * Config: files {path: bytes (base64)}, user, pass (null: anyone gets in), pasv_ip (the address a
 * PASV reply claims), epsv (bool), mdtm (bool), size (bool), size_says (int: lie about the size),
 * send_only (int: cut the data connection after so many bytes).
 */

$config = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$log = fopen($argv[2], 'ab');

function note(string $line): void
{
    global $log;
    fwrite($log, $line."\n");
    fflush($log);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    exit(1);
}
echo explode(':', (string) stream_socket_get_name($server, false))[1]."\n";
flush();

set_time_limit(60);
while ($client = @stream_socket_accept($server, 60)) {
    session($client, $config);
    fclose($client);
}

function say($client, string $line): void
{
    fwrite($client, $line."\r\n");
}

function session($client, array $config): void
{
    $files = array_map(static fn (string $bytes): string => (string) base64_decode($bytes, true), $config['files'] ?? []);
    $dir = '/';
    $user = null;
    $authed = false;
    $passive = null;
    say($client, '220 Test server ready');

    while (($line = fgets($client)) !== false) {
        $line = rtrim($line, "\r\n");
        note($line);
        [$command, $argument] = array_pad(explode(' ', $line, 2), 2, '');
        $path = str_starts_with($argument, '/') ? $argument : rtrim($dir, '/').'/'.$argument;

        switch (strtoupper($command)) {
            case 'USER':
                $user = $argument;
                say($client, '331 Password please');
                break;
            case 'PASS':
                $authed = ($config['user'] ?? null) === null || ($user === $config['user'] && $argument === $config['pass']);
                say($client, $authed ? '230 Welcome' : '530 Login incorrect');
                break;
            case 'AUTH':
                say($client, '500 No TLS here');
                break;
            case 'PWD':
                say($client, '257 "'.$dir.'"');
                break;
            case 'CWD':
                $dir = rtrim($path, '/') ?: '/';
                $known = array_filter(array_keys($files), static fn (string $file): bool => str_starts_with($file, rtrim($dir, '/').'/'));
                say($client, $known !== [] ? '250 OK' : '550 No such directory');
                break;
            case 'TYPE':
                note('type='.$argument);
                say($client, '200 OK');
                break;
            case 'SIZE':
                if (! ($config['size'] ?? true)) {
                    say($client, '500 Not supported');
                    break;
                }
                if (! isset($files[$path])) {
                    say($client, '550 No such file');
                    break;
                }
                say($client, '213 '.($config['size_says'] ?? strlen($files[$path])));
                break;
            case 'MDTM':
                if (! isset($files[$path]) || ! ($config['mdtm'] ?? true)) {
                    say($client, '550 Not available');
                    break;
                }
                say($client, '213 20260929101500');
                break;
            case 'REST':
                say($client, '350 OK');
                break;
            case 'EPSV':
                if (! ($config['epsv'] ?? false)) {
                    say($client, '500 Not supported');
                    break;
                }
                $passive = stream_socket_server('tcp://127.0.0.1:0');
                say($client, '229 Entering Extended Passive Mode (|||'.explode(':', (string) stream_socket_get_name($passive, false))[1].'|)');
                break;
            case 'PASV':
                $passive = stream_socket_server('tcp://127.0.0.1:0');
                $port = (int) explode(':', (string) stream_socket_get_name($passive, false))[1];
                $claimed = str_replace('.', ',', $config['pasv_ip'] ?? '127.0.0.1');
                say($client, '227 Entering Passive Mode ('.$claimed.','.intdiv($port, 256).','.($port % 256).')');
                break;
            case 'RETR':
                if (! isset($files[$path]) || $passive === null) {
                    say($client, '550 No such file');
                    break;
                }
                $data = @stream_socket_accept($passive, 5);
                if ($data === false) {
                    say($client, '425 No data connection');
                    break;
                }
                say($client, '150 Sending');
                $bytes = $files[$path];
                fwrite($data, isset($config['send_only']) ? substr($bytes, 0, $config['send_only']) : $bytes);
                fclose($data);
                fclose($passive);
                $passive = null;
                say($client, '226 Done');
                break;
            case 'QUIT':
                say($client, '221 Bye');

                return;
            default:
                say($client, '500 Unknown');
        }
    }
}
