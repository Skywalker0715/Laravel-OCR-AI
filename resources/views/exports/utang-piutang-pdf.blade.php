<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Utang Piutang</title>
    <style>
        @page { margin: 15mm 12mm 18mm; }
        * { padding: 0; }
        body, h3, table { margin: 0; }
        body { font-family: sans-serif; font-size: 11px; color: #111827; }
        .report-header { width: 100%; margin-bottom: 14px; border-bottom: 2px solid #10B981; padding-bottom: 10px; }
        .report-header .brand { font-size: 16px; font-weight: bold; color: #10B981; }
        .report-header .title { font-size: 13px; font-weight: bold; margin-top: 2px; }
        .report-header .meta { margin-top: 6px; color: #4B5563; font-size: 10px; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .summary-cell { width: 49%; vertical-align: top; }
        .summary-gap { width: 2%; }
        .summary-card { width: 100%; border-collapse: collapse; }
        .summary-card td { border: 1px solid #D1D5DB; padding: 8px 10px; vertical-align: top; }
        .summary .label { color: #6B7280; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; }
        .summary .value { font-size: 14px; font-weight: bold; margin-top: 3px; }
        .value-utang { color: #DC2626; }
        .value-piutang { color: #059669; }
        h3.section-title { font-size: 12px; margin: 14px 0 6px; color: #10B981; text-transform: uppercase; letter-spacing: 0.5px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.data th, table.data td { overflow-wrap: anywhere; }
        table.data th { background-color: #10B981; color: #FFFFFF; text-align: left; padding: 6px 8px; font-size: 10px; }
        table.data td { border-bottom: 1px solid #E5E7EB; padding: 5px 8px; vertical-align: top; }
        table.data tr:nth-child(even) td { background-color: #F3F4F6; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .muted { color: #6B7280; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: bold; }
        .badge-danger { background-color: #FEE2E2; color: #DC2626; }
        .badge-success { background-color: #DCFCE7; color: #16A34A; }
        .badge-warning { background-color: #FEF3C7; color: #D97706; }
        .badge-info { background-color: #E0F2FE; color: #0284C7; }
        .report-footer { margin-top: 18px; color: #9CA3AF; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
    <table class="report-header">
        <tr>
            <td>
                <div class="brand">Catatan Belanja</div>
                <div class="title">Laporan Utang Piutang</div>
                <div class="meta">
                    Pemilik: {{ $userName }}
                    &middot; Dibuat: {{ $generatedAt }}
                    &middot; Total Catatan: {{ $debts->count() }}
                </div>
            </td>
        </tr>
    </table>
    <table class="summary">
        <tr>
            <td class="summary-cell">
                <table class="summary-card">
                    <tr>
                        <td>
                            <div class="label">Total Utang Belum Lunas</div>
                            <div class="value value-utang">{{ \App\Support\MoneyFormatter::format($totalUtangBelumLunas) }}</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="summary-gap"></td>
            <td class="summary-cell">
                <table class="summary-card">
                    <tr>
                        <td>
                            <div class="label">Total Piutang Belum Lunas</div>
                            <div class="value value-piutang">{{ \App\Support\MoneyFormatter::format($totalPiutangBelumLunas) }}</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <h3 class="section-title">Daftar Catatan Utang Piutang ({{ $debts->count() }})</h3>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 4%;">No</th>
                <th style="width: 10%;">Tipe</th>
                <th style="width: 22%;">Nama Pihak</th>
                <th style="width: 14%; text-align: right;">Jumlah</th>
                <th style="width: 14%; text-align: right;">Dibayar</th>
                <th style="width: 14%; text-align: right;">Sisa</th>
                <th style="width: 11%; text-align: center;">Jatuh Tempo</th>
                <th style="width: 11%; text-align: center;">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($debts as $debt)
                <tr>
                    <td class="text-center">{{ $loop->iteration }}</td>
                    <td>
                        <span class="badge {{ $debt->type === \App\Models\Debt::TYPE_UTANG ? 'badge-danger' : 'badge-success' }}">
                            {{ $debt->typeLabel() }}
                        </span>
                    </td>
                    <td>
                        <strong>{{ $debt->counterparty_name ?? '—' }}</strong>
                        @if ($debt->notes)
                            <div class="muted" style="font-size: 9px; margin-top: 2px;">{{ $debt->notes }}</div>
                        @endif
                    </td>
                    <td class="text-right">{{ \App\Support\MoneyFormatter::format($debt->amount) }}</td>
                    <td class="text-right">{{ \App\Support\MoneyFormatter::format($debt->paid_amount) }}</td>
                    <td class="text-right">
                        <strong style="color: {{ $debt->isLunas() ? '#16A34A' : '#DC2626' }};">
                            {{ \App\Support\MoneyFormatter::format($debt->remainingAmount()) }}
                        </strong>
                    </td>
                    <td class="text-center">{{ $debt->due_date?->format('d/m/Y') ?? '—' }}</td>
                    <td class="text-center">
                        <span class="badge badge-{{ $debt->statusColor() }}">
                            {{ $debt->statusLabel() }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="muted text-center" style="padding: 12px;">Tidak ada data utang piutang.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="report-footer">
        Laporan ini dibuat otomatis oleh Catatan Belanja — data disaring mengikuti filter aktif.
    </div>
</body>
</html>
