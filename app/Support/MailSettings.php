<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Settlement;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * E-Mail-Einstellungen aus der Verwaltung (Einstellungen) statt aus der .env
 * sowie Vorlagen für Betreff und Text der Rechnungsmails.
 */
class MailSettings
{
    /** Platzhalter, die in Betreff und Text der Rechnungsmail ersetzt werden. */
    public const PLACEHOLDERS = [
        '{mieter}', '{rechnungsnummer}', '{zeitraum}', '{monat}', '{zaehler}',
        '{betrag_netto}', '{betrag_brutto}', '{vermieter}',
    ];

    /** Überschreibt die SMTP-Konfiguration, sobald in den Einstellungen ein Host eingetragen ist. */
    public static function apply(): void
    {
        try {
            $host = Setting::get('mail_host');
        } catch (Throwable) {
            return; // z. B. vor der ersten Migration
        }

        if (! $host) {
            return;
        }

        $encryption = Setting::get('mail_encryption');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => (int) Setting::get('mail_port'),
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => Setting::get('mail_username') ?: null,
            'mail.mailers.smtp.password' => self::password(),
            'mail.from.address' => Setting::get('mail_from_address') ?: config('mail.from.address'),
            'mail.from.name' => Setting::get('mail_from_name') ?: config('mail.from.name'),
        ]);
    }

    public static function password(): ?string
    {
        $encrypted = Setting::get('mail_password');

        if (! $encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null; // anderer APP_KEY – Passwort muss neu eingegeben werden
        }
    }

    public static function storePassword(string $password): void
    {
        Setting::put('mail_password', Crypt::encryptString($password));
    }

    public static function subject(Settlement $settlement): string
    {
        return self::render((string) Setting::get('mail_invoice_subject'), $settlement);
    }

    public static function body(Settlement $settlement): string
    {
        return self::render((string) Setting::get('mail_invoice_body'), $settlement);
    }

    public static function render(string $template, Settlement $settlement): string
    {
        $money = fn ($v) => number_format((float) $v, 2, ',', '.').' €';

        return strtr($template, [
            '{mieter}' => $settlement->tenant->name,
            '{rechnungsnummer}' => $settlement->formattedNumber(),
            '{zeitraum}' => $settlement->starts_on->format('d.m.Y').' – '.$settlement->ends_on->format('d.m.Y'),
            '{monat}' => $settlement->period->format('m/Y'),
            '{zaehler}' => $settlement->meterNumbers(),
            '{betrag_netto}' => $money($settlement->net_amount),
            '{betrag_brutto}' => $money($settlement->gross_amount),
            '{vermieter}' => (string) Setting::get('landlord_name'),
        ]);
    }
}
