@php($kwh = fn ($v) => number_format((float) $v, 0, ',', '.'))
<span style="font-size:13pt;font-weight:bold;">Abweichungsbericht {{ $year }}</span>
<br><br>
@foreach ($comparison as $row)
    <table cellspacing="0" cellpadding="4" border="0.5">
        <tr style="background-color:#e8f5e9;"><td width="100%"><b>{{ $row['meter']->location }} – {{ $row['meter']->number }}</b></td></tr>
    </table>
    <table cellspacing="0" cellpadding="3" border="0.5" style="font-size:9pt;">
        <tr style="background-color:#f5f5f5;">
            <td width="22%" align="center"><b>Monat</b></td>
            <td width="22%" align="center"><b>gemäß Rechnung</b></td>
            <td width="22%" align="center"><b>gemäß Unterzählern</b></td>
            <td width="34%" align="center"><b>Abweichung</b></td>
        </tr>
        @foreach ($row['months'] as $key => $m)
            <tr>
                <td align="center">{{ \Carbon\Carbon::createFromFormat('!Y-m', $key)->locale('de')->isoFormat('MMMM YYYY') }}</td>
                <td align="center">{{ $m['supplier'] ? $kwh($m['supplier']).' kWh' : '–' }}</td>
                <td align="center">{{ $m['submeters'] ? $kwh($m['submeters']).' kWh' : '–' }}</td>
                <td align="center">
                    @if ($m['supplier'] && $m['submeters'])
                        {{ $kwh($m['difference']) }} kWh ({{ number_format($m['percent'], 1, ',', '.') }} %)
                    @else
                        –
                    @endif
                </td>
            </tr>
        @endforeach
        <tr style="background-color:#f5f5f5;">
            <td align="center"><b>Summe</b></td>
            <td align="center"><b>{{ $kwh($row['months']->sum('supplier')) }} kWh</b></td>
            <td align="center"><b>{{ $kwh($row['months']->sum('submeters')) }} kWh</b></td>
            <td align="center"><b>{{ $kwh($row['months']->sum('difference')) }} kWh</b></td>
        </tr>
    </table>
    <br><br>
@endforeach
