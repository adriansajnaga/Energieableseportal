<?php

return [
    // Alte QR-Codes (md5 der Zähler-ID) weiterhin annehmen. Nach dem Austausch der Etiketten auf false setzen,
    // weil diese Codes erratbar sind.
    'legacy_qr' => env('ENERGIE_LEGACY_QR', true),

    // Verbindung zur alten Datenbank für "php artisan energie:import-legacy".
    'legacy_connection' => env('ENERGIE_LEGACY_CONNECTION', 'legacy'),

    // Mobiler Analysator: Zeitzone für Anzeige und Tageswerte (Plan-Ablesung um 00:00 Ortszeit).
    'analyzer_timezone' => env('ENERGIE_ANALYZER_TIMEZONE', 'Europe/Warsaw'),

    // Höchstzahl Anfragen pro Minute und Gerät (bei Rückstand sendet das Gerät mehrere Pakete pro Sekunde).
    'analyzer_rate_limit' => (int) env('ENERGIE_ANALYZER_RATE_LIMIT', 120),
];
