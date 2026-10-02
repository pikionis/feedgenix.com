<?php

declare(strict_types=1);

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
 * Until a mail provider is set up, the server's log mailer is how a verification or reset link
 * reaches anyone (ops/README.md). The server logs at info level, and the log transport writes at
 * debug: without a channel of its own, every message was silently dropped.
 */

/** @return array{0: string, 1: string} the mail log and the app log, as the server would write them */
function mailAndAppLog(Closure $send): array
{
    $dir = sys_get_temp_dir().'/feedgenix-mail-log-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dir);
    config([
        // As ops/templates/env.template sets them on the server.
        'logging.default' => 'daily',
        'logging.channels.daily.level' => 'info',
        'logging.channels.daily.path' => $dir.'/laravel.log',
        'logging.channels.mail.path' => $dir.'/mail.log',
        'mail.default' => 'log',
    ]);

    try {
        $send();
        $read = static function (string $pattern): string {
            $text = '';
            foreach (glob($pattern) ?: [] as $file) {
                $text .= (string) file_get_contents($file);
            }

            return $text;
        };

        return [$read($dir.'/mail-*.log'), $read($dir.'/laravel-*.log')];
    } finally {
        File::deleteDirectory($dir);
    }
}

it('writes a mailed link to the mail log although the app log keeps only info and above', function (): void {
    [$mailLog] = mailAndAppLog(function (): void {
        Mail::raw('Verify: https://staging.feedgenix.test/api/auth/verify-email/7/abc123', function (Message $message): void {
            $message->to('owner@example.com')->subject('Verify your address');
        });
    });

    expect($mailLog)->toContain('verify-email/7/abc123')->toContain('owner@example.com');
});

it('keeps mail out of the app log', function (): void {
    [, $appLog] = mailAndAppLog(function (): void {
        Mail::raw('Reset: https://staging.feedgenix.test/reset-password/secret-token', function (Message $message): void {
            $message->to('owner@example.com')->subject('Reset');
        });
    });

    expect($appLog)->not->toContain('secret-token');
});
