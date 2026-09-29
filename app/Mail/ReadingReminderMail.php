<?php

namespace App\Mail;

use App\Models\Meter;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Erinnerung an Mieter (eigene Zähler) oder Hausmeister (alle offenen Zähler).
 */
class ReadingReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  Collection<int, Meter>  $meters */
    public function __construct(public Collection $meters, public bool $forCaretaker = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Erinnerung: Zählerstand für '.now()->locale('de')->isoFormat('MMMM YYYY').' melden');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reading-reminder', with: [
            'deadline' => Setting::get('reading_deadline_day'),
            'landlord' => Setting::get('landlord_name'),
        ]);
    }
}
