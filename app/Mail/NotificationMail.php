<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $emailSubject,
        public string $heading,
        public string $body,
        public string $ctaLabel = '',
        public string $ctaUrl = '',
        public string $accentColor = '#E91E63',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    private function buildHtml(): string
    {
        $cta = '';
        if ($this->ctaLabel && $this->ctaUrl) {
            $cta = <<<HTML
            <div style="text-align: center; margin: 24px 0;">
                <a href="{$this->ctaUrl}" style="display: inline-block; padding: 12px 28px; background: {$this->accentColor}; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px;">{$this->ctaLabel}</a>
            </div>
HTML;
        }

        return <<<HTML
<div style="font-family: 'Helvetica Neue', Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #ffffff;">
    <div style="text-align: center; margin-bottom: 30px;">
        <span style="font-size: 24px; font-weight: 700; color: #E91E63;">ILLIZEO</span>
        <br><span style="font-size: 10px; color: #999; letter-spacing: 1px;">THE ALL-IN-ONE HR SOLUTION</span>
    </div>

    <div style="background: #f8f9fa; border-radius: 12px; padding: 24px 28px; margin-bottom: 20px;">
        <h2 style="margin: 0 0 12px 0; font-size: 18px; color: #1a1a2e;">{$this->heading}</h2>
        <p style="margin: 0; font-size: 14px; color: #444; line-height: 1.6;">{$this->body}</p>
    </div>

    {$cta}

    <p style="font-size: 13px; color: #666;">Cordialement,<br><strong>L'équipe Illizeo</strong></p>

    <div style="margin-top: 30px; padding-top: 16px; border-top: 1px solid #eee; font-size: 11px; color: #aaa; text-align: center;">
        Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse<br>
        <a href="https://www.illizeo.com" style="color: #aaa;">www.illizeo.com</a> · contact@illizeo.com
    </div>
</div>
HTML;
    }
}
