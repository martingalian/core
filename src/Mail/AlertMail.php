<?php

declare(strict_types=1);

namespace Martingalian\Core\Mail;

use Martingalian\Core\Enums\NotificationSeverity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $notificationTitle,
        public string $notificationMessage,
        public ?NotificationSeverity $severity = null,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
        public ?string $details = null,
        public ?string $hostname = null,
        public ?string $userName = null,
        public ?string $exchange = null,
        public ?string $serverIp = null
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        // Build subject line with optional server IP and exchange context
        $subject = $this->notificationTitle;

        // Only add server context if exchange or serverIp is explicitly provided
        // If both are null, the notification doesn't need server context (e.g., credential issues)
        if ($this->serverIp || $this->exchange) {
            // Add "- Server IP xxx.xxx.xxx.xxx on Exchange" if both serverIp and exchange are provided
            if ($this->serverIp && $this->exchange) {
                $subject .= ' - Server '.$this->serverIp.' on '.ucfirst($this->exchange);
            } elseif ($this->serverIp) {
                // Just server IP if no exchange
                $subject .= ' - Server '.$this->serverIp;
            } elseif ($this->hostname && $this->exchange) {
                // Legacy fallback: use hostname if IP not provided but exchange is
                $subject .= ' - Server '.$this->hostname.' on '.ucfirst($this->exchange);
            } elseif ($this->hostname) {
                // Legacy fallback: just hostname if exchange provided without IP
                $subject .= ' - Server '.$this->hostname;
            }
        }
        // If both exchange and serverIp are null, don't add any server context

        $envelope = new Envelope(
            subject: $subject,
        );

        // Set email priority based on severity level
        // Critical and High severity get high priority headers
        if ($this->severity && in_array($this->severity, [NotificationSeverity::Critical, NotificationSeverity::High])) {
            $envelope->using(function (\Symfony\Component\Mime\Email $message) {
                $message->priority(\Symfony\Component\Mime\Email::PRIORITY_HIGH);
                $message->getHeaders()
                    ->addTextHeader('X-Priority', '1')
                    ->addTextHeader('Importance', 'high');
            });
        }

        return $envelope;
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'martingalian::emails.notification',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
