<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Zamówienie {{ $order->number }}</title>
    <style>
        @page { margin: 28mm 18mm 22mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.4; }
        .brand-bar { height: 4px; background: linear-gradient(90deg, {{ $brand['primary_color'] ?? '#E91E8C' }}, {{ $brand['secondary_color'] ?? '#9B26D9' }}); margin-bottom: 14px; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .header .left { display: table-cell; width: 60%; vertical-align: top; }
        .header .right { display: table-cell; width: 40%; vertical-align: top; text-align: right; }
        .doc-title { font-size: 22pt; font-weight: bold; color: {{ $brand['primary_color'] ?? '#E91E8C' }}; margin: 0 0 4px 0; }
        .doc-number { font-size: 12pt; color: #555; }
        .doc-meta { font-size: 9pt; color: #666; margin-top: 8px; }

        .parties { display: table; width: 100%; margin: 16px 0 24px; border-spacing: 12px 0; }
        .party { display: table-cell; width: 50%; padding: 10px 12px; background: #f7f7fb; border-left: 3px solid {{ $brand['primary_color'] ?? '#E91E8C' }}; }
        .party-label { font-size: 8pt; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 4px; }
        .party-name { font-weight: bold; font-size: 11pt; margin-bottom: 4px; }
        .party-line { font-size: 9pt; color: #444; }

        table.items { width: 100%; border-collapse: collapse; margin: 8px 0 16px; }
        table.items th { background: #ededf2; padding: 8px 6px; text-align: left; font-size: 9pt; text-transform: uppercase; color: #555; border-bottom: 2px solid {{ $brand['primary_color'] ?? '#E91E8C' }}; }
        table.items td { padding: 8px 6px; border-bottom: 1px solid #eaeaef; vertical-align: top; }
        table.items .num { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
        table.items .lp { text-align: center; color: #999; width: 28px; }

        .totals { width: 50%; margin-left: 50%; margin-top: 8px; }
        .totals td { padding: 4px 6px; font-size: 10pt; }
        .totals .label { text-align: right; color: #666; }
        .totals .value { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
        .totals .grand { font-size: 13pt; font-weight: bold; color: {{ $brand['primary_color'] ?? '#E91E8C' }}; border-top: 2px solid {{ $brand['primary_color'] ?? '#E91E8C' }}; padding-top: 6px; }

        .notes { margin-top: 24px; padding: 12px; background: #fafafd; border-left: 3px solid #ccc; font-size: 9pt; color: #555; }
        .notes-label { font-size: 8pt; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 4px; }

        .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 8pt; color: #888; text-align: center; padding-top: 8px; border-top: 1px solid #eaeaef; }
    </style>
</head>
<body>
    <div class="brand-bar"></div>

    <div class="header">
        <div class="left">
            <h1 class="doc-title">Zamówienie</h1>
            <div class="doc-number">{{ $order->number }}</div>
        </div>
        <div class="right">
            <div class="doc-meta">
                <strong>Data wystawienia:</strong> {{ $order->order_date?->format('d.m.Y') }}<br>
                @if($order->delivery_date)
                    <strong>Termin realizacji:</strong> {{ $order->delivery_date->format('d.m.Y') }}<br>
                @endif
                <strong>Status:</strong> {{ $order->statusLabel() }}
            </div>
        </div>
    </div>

    <div class="parties">
        <div class="party">
            <div class="party-label">Sprzedawca</div>
            @php $c = $order->snapshot_company ?? []; @endphp
            <div class="party-name">{{ $c['name'] ?? '—' }}</div>
            @if(!empty($c['address']))<div class="party-line">{{ $c['address'] }}</div>@endif
            @if(!empty($c['postal']) || !empty($c['city']))
                <div class="party-line">{{ $c['postal'] ?? '' }} {{ $c['city'] ?? '' }}</div>
            @endif
            @if(!empty($c['nip']))<div class="party-line">NIP: {{ $c['nip'] }}</div>@endif
            @if(!empty($c['regon']))<div class="party-line">REGON: {{ $c['regon'] }}</div>@endif
            @if(!empty($c['email']))<div class="party-line">{{ $c['email'] }}</div>@endif
            @if(!empty($c['phone']))<div class="party-line">{{ $c['phone'] }}</div>@endif
        </div>
        <div class="party">
            <div class="party-label">Nabywca</div>
            @php $cl = $order->snapshot_client ?? []; @endphp
            <div class="party-name">{{ $cl['name'] ?? '—' }}</div>
            @if(!empty($cl['address']))<div class="party-line">{{ $cl['address'] }}</div>@endif
            @if(!empty($cl['postal']) || !empty($cl['city']))
                <div class="party-line">{{ $cl['postal'] ?? '' }} {{ $cl['city'] ?? '' }}</div>
            @endif
            @if(!empty($cl['nip']))<div class="party-line">NIP: {{ $cl['nip'] }}</div>@endif
            @if(!empty($cl['contact_person']))<div class="party-line">Kontakt: {{ $cl['contact_person'] }}</div>@endif
            @if(!empty($cl['email']))<div class="party-line">{{ $cl['email'] }}</div>@endif
            @if(!empty($cl['phone']))<div class="party-line">{{ $cl['phone'] }}</div>@endif
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="lp">Lp.</th>
                <th>Nazwa towaru / usługi</th>
                <th>SKU</th>
                <th class="num">Ilość</th>
                <th>Jedn.</th>
                <th class="num">Cena netto</th>
                <th class="num">VAT</th>
                <th class="num">Wartość netto</th>
                <th class="num">Wartość brutto</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $i => $item)
                <tr>
                    <td class="lp">{{ $i + 1 }}</td>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->sku ?? '—' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($item->quantity, 3, ',', ' '), '0'), ',') }}</td>
                    <td>{{ $item->unit }}</td>
                    <td class="num">{{ number_format($item->price_net, 2, ',', ' ') }}</td>
                    <td class="num">{{ $item->vat_rate }}%</td>
                    <td class="num">{{ number_format($item->total_net, 2, ',', ' ') }}</td>
                    <td class="num">{{ number_format($item->total_gross, 2, ',', ' ') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Razem netto:</td>
            <td class="value">{{ number_format($order->total_net, 2, ',', ' ') }} PLN</td>
        </tr>
        <tr>
            <td class="label">Razem VAT:</td>
            <td class="value">{{ number_format($order->total_vat, 2, ',', ' ') }} PLN</td>
        </tr>
        <tr>
            <td class="label grand">Do zapłaty (brutto):</td>
            <td class="value grand">{{ number_format($order->total_gross, 2, ',', ' ') }} PLN</td>
        </tr>
    </table>

    @if($order->notes)
        <div class="notes">
            <div class="notes-label">Uwagi</div>
            {!! nl2br(e($order->notes)) !!}
        </div>
    @endif

    @if(!empty($c['bank_account']))
        <div class="notes" style="margin-top: 12px; border-left-color: {{ $brand['primary_color'] ?? '#E91E8C' }};">
            <div class="notes-label">Numer konta do wpłaty</div>
            <strong>{{ $c['bank_account'] }}</strong>
        </div>
    @endif

    <div class="footer">
        Wystawiono w {{ $brand['name'] ?? 'OVERCRM' }} · {{ now()->format('d.m.Y H:i') }}
        @if($order->user) · Wystawił: {{ $order->user->name }} @endif
    </div>
</body>
</html>
