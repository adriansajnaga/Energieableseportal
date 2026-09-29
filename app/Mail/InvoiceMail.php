<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\Settlement;
use App\Pdf\PdfFactory;
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

    public function __construct(public Settlement $settlement) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Stromabrechnung '.$this->settlement->period->format('m/Y').' – Rechnung '.$this->settlement->formattedNumber(),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice', with: [
            'settlement' => $this->settlement,
            'landlord' => Setting::get('landlord_name'),
        ]);
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => app(PdfFactory::class)->invoices(collect([$this->settlement]))->content(),
                'Rechnung_'.$this->settlement->formattedNumber().'.pdf',
            )->withMime('application/pdf'),
        ];
    }
}
