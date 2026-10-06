<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
class BusinessAlert extends Notification implements ShouldQueue
{
    use Queueable;
    public function __construct(public array $payload,public bool $email) { $this->afterCommit(); }
    public function via(object $notifiable): array { return $this->email?['database','mail']:['database']; }
    public function toArray(object $notifiable): array { return $this->payload; }
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->payload['title'])->line($this->payload['description'])->action('Open CRM',url('/leads'));
    }
}
