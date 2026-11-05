<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** A real FTP server for the connector tests: a process of the suite's own, on 127.0.0.1. */
final class FtpFixture
{
    /** @var list<self> */
    private static array $running = [];

    /** @var resource */
    private $process;

    private bool $stopped = false;

    public readonly string $log;

    public readonly int $port;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $config['files'] = array_map(base64_encode(...), $config['files'] ?? []);
        $configFile = ingestScratch().'/ftp-'.uniqid('', true).'.json';
        file_put_contents($configFile, json_encode($config, JSON_THROW_ON_ERROR));
        $this->log = ingestScratch().'/ftp-'.uniqid('', true).'.log';
        touch($this->log);

        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/fixtures/ftp-server.php'), $configFile, $this->log],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new RuntimeException('The FTP fixture did not start.');
        }
        $this->process = $process;
        $this->port = (int) fgets($pipes[1]);
        self::$running[] = $this;
    }

    public static function stopAll(): void
    {
        foreach (self::$running as $fixture) {
            $fixture->stop();
        }
        self::$running = [];
    }

    /** @return list<string> every command the server received */
    public function commands(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->log))));
    }

    public function stop(): void
    {
        if (! $this->stopped) {
            $this->stopped = true;
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function url(string $path, string $scheme = 'ftp'): string
    {
        return "{$scheme}://127.0.0.1:{$this->port}{$path}";
    }
}
