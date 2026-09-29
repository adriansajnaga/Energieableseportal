<?php

return [
    // Alte QR-Codes (md5 der Zähler-ID) weiterhin annehmen. Nach dem Austausch der Etiketten auf false setzen,
    // weil diese Codes erratbar sind.
    'legacy_qr' => env('ENERGIE_LEGACY_QR', true),

    // Verbindung zur alten Datenbank für "php artisan energie:import-legacy".
    'legacy_connection' => env('ENERGIE_LEGACY_CONNECTION', 'legacy'),
];
