<?php

namespace App\Mail;

use App\Models\Settlement;
use App\Pdf\PdfFactory;
use App\Support\MailSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $subjectText;

    public string $bodyText;

    /**
     * Betreff und Text kommen aus der Vorlage in den Einstellungen,
     * können beim Einzelversand aber überschrieben werden.
     */
    public function __construct(public Settlement $settlement, ?string $subjectText = null, ?string $bodyText = null)
    {
        $this->subjectText = $subjectText ?: MailSettings::subject($settlement);
        $this->bodyText = $bodyText ?: MailSettings::body($settlement);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice', with: [
            'settlement' => $this->settlement,
            'paragraphs' => preg_split('/\R{2,}/', trim($this->bodyText)),
        ]);
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => app(PdfFactory::class)->invoices(collect([$this->settlement]))->content(),
                $this->settlement->pdfFilename(),
            )->withMime('application/pdf'),
        ];
    }
}
