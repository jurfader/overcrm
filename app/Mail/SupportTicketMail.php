<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportTicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket,
        public ?string $logExcerpt = null,
    ) {}

    public function envelope(): Envelope
    {
        $domain = parse_url(config('app.url'), PHP_URL_HOST) ?: 'overcrm';
        $catLabel = match($this->ticket->category) {
            'bug' => 'BUG',
            'question' => 'Pytanie',
            'feature' => 'Sugestia',
            default => 'Inne',
        };

        return new Envelope(
            subject: "[OVERCRM/{$domain}] [{$catLabel}] " . $this->ticket->subject,
            replyTo: $this->ticket->user?->email
                ? [$this->ticket->user->email]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.support-ticket',
            with: [
                'ticket'     => $this->ticket,
                'logExcerpt' => $this->logExcerpt,
            ],
        );
    }

    public function attachments(): array
    {
        if (!$this->logExcerpt) return [];

        return [
            Attachment::fromData(fn () => $this->logExcerpt, 'laravel-log-tail.txt')
                ->withMime('text/plain'),
        ];
    }
}
