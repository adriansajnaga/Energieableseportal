<table cellspacing="0" cellpadding="4" border="0.5">
    <tr style="background-color:#e2e2e2;">
        <td width="8%" rowspan="2" align="center"><br><br><br><b>{{ $number }}</b></td>
        <td width="92%"><b>Zähler-Nummer: {{ $meter->number }}</b></td>
    </tr>
    <tr>
        <td height="112">
            <table cellspacing="0" cellpadding="1" border="0">
                <tr><td width="28%">Ort / Gebäude:</td><td width="44%"><b>{{ $meter->location }}</b></td><td width="28%"></td></tr>
                <tr><td>Mieter:</td><td><b>{{ $meter->tenant?->name }}</b></td><td></td></tr>
                <tr><td>Telefon:</td><td><b>{{ $meter->tenant?->phone }}</b></td><td></td></tr>
                <tr><td>Hauptzähler:</td><td><b>{{ $meter->is_main ? 'Ja' : $meter->parent?->number }}</b></td><td></td></tr>
                <tr><td>Faktor:</td><td><b>{{ $meter->factor }}</b></td><td></td></tr>
            </table>
        </td>
    </tr>
</table>
