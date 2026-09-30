<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\Settlement;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Verschickt Rechnungen sofort (nicht über die Warteschlange), damit der Versand
 * auch ohne laufenden Queue-Cronjob funktioniert und Fehler direkt angezeigt werden.
 */
class InvoiceMailer
{
    public function send(Settlement $settlement, string $email, ?string $subject = null, ?string $body = null): void
    {
        if (! $settlement->canBeEmailed()) {
            throw new RuntimeException(__('Diese Abrechnung hat keine Rechnungsnummer und kann nicht versendet werden.'));
        }

        Mail::to($email)->sendNow(new InvoiceMail($settlement, $subject, $body));

        $settlement->update(['emailed_at' => now(), 'emailed_to' => $email]);
    }
}
