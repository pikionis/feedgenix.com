<?php

declare(strict_types=1);

namespace App\Domain\Notices;

use App\Domain\Notices\Models\Notice;
use App\Support\Frontend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A notice by email, to the workspace's owner and admins. The same words as in the app. */
final class NoticeMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $workspace,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionLabel,
        public readonly ?string $actionPath,
    ) {}

    public static function of(Notice $notice, string $workspace): self
    {
        return new self($workspace, $notice->title, $notice->body, $notice->action_label, $notice->action_path);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("{$this->title} · {$this->workspace}")
            ->line($this->body);
        if ($this->actionLabel !== null && $this->actionPath !== null) {
            $mail->action($this->actionLabel, Frontend::url($this->actionPath));
        }

        return $mail->line('You get this because you manage the workspace '.$this->workspace.' on FeedGenix.');
    }
}
