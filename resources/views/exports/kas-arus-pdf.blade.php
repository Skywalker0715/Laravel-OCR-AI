<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Kas Arus</title>
    <style>
        /* Gaya dompdf-safe: table-based layout + CSS sederhana (dompdf tidak
           mendukung flexbox/grid penuh). Aksen mengikuti warna brand aplikasi
           (hijau #10B981). Font sans-serif memakai DejaVu Sans bawaan dompdf.
           Catatan batasan dompdf yang diverifikasi lewat pengujian render:
           1. dompdf TIDAK mendukung `box-sizing` — padding sel memakan lebar
              kolom, sehingga tabel aman selama total persentase kolom = 100%.
           2. JANGAN memakai `* { margin: 0 }` (reset margin via universal
              selector): dompdf menerapkannya juga ke konteks halaman sehingga
              margin @page menjadi 0 dan seluruh konten menempel ke tepi
              kertas. Reset margin hanya pada elemen tertentu (body/h3/table).
           3. Hindari `border-spacing` pada tabel ber-lebar persentase: dompdf
              TIDAK menguranginya dari lebar kolom, sehingga tabel meluber ke
              kanan melewati tepi halaman. Pakai sel spacer ber-lebar persen
              (lihat .summary). */
        @page {
            margin: 15mm 12mm 18mm;
        }

        * { padding: 0; }
        body, h3, table { margin: 0; }

        body {
            font-family: sans-serif;
            font-size: 11px;
            color: #111827;
        }

        .report-header { width: 100%; margin-bottom: 14px; border-bottom: 2px solid #10B981; padding-bottom: 10px; }
        .report-header .brand { font-size: 16px; font-weight: bold; color: #10B981; }
        .report-header .title { font-size: 13px; font-weight: bold; margin-top: 2px; }
        .report-header .meta { margin-top: 6px; color: #4B5563; font-size: 10px; }

        /* Ringkasan 3 kartu: gap antar kartu dibuat lewat sel .summary-gap
           ber-lebar persen (32% + 2% + 32% + 2% + 32% = 100% tepat), bukan
           border-spacing, agar total lebar tidak pernah meluber dari halaman. */
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .summary-cell { width: 32%; vertical-align: top; }
        .summary-gap { width: 2%; }
        .summary-card { width: 100%; border-collapse: collapse; }
        .summary-card td { border: 1px solid #D1D5DB; padding: 8px 10px; vertical-align: top; }
        .summary .label { color: #6B7280; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; }
        .summary .value { font-size: 14px; font-weight: bold; color: #059669; margin-top: 3px; }
        .summary .value.neg { color: #DC2626; }

        h3.section-title { font-size: 12px; margin: 14px 0 6px; color: #10B981; text-transform: uppercase; letter-spacing: 0.5px; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.data th {
            background-color: #10B981;
            color: #FFFFFF;
            text-align: left;
            padding: 6px 8px;
            font-size: 10px;
        }
        table.data td { border-bottom: 1px solid #E5E7EB; padding: 5px 8px; vertical-align: top; }
        table.data tr:nth-child(even) td { background-color: #F3F4F6; }
        .text-right { text-align: right; }
        .muted { color: #6B7280; }
        .pos { color: #059669; }
        .neg { color: #DC2626; }

        table.total { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.total td { padding: 7px 8px; font-weight: bold; background-color: #ECFDF5; border: 1px solid #10B981; }
        table.total td.neg-bg { background-color: #FEF2F2; border-color: #DC2626; color: #DC2626; }

        .report-footer { margin-top: 18px; color: #9CA3AF; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
    {{-- KOP LAPORAN --}}
    <table class="report-header">
        <tr>
            <td>
                <div class="brand">Catatan Belanja</div>
                <div class="title">Laporan Kas Arus</div>
                <div class="meta">
                    Periode: {{ $periodLabel }}
                    &middot; Pemilik: {{ $userName }}
                    &middot; Dibuat: {{ $generatedAt }}
                </div>
            </td>
        </tr>
    </table>

    {{-- RINGKASAN: 3 kartu dipisah sel spacer (lihat catatan .summary di CSS) --}}
    <table class="summary">
        <tr>
            <td class="summary-cell">
                <table class="summary-card">
                    <tr>
                        <td>
                            <div class="label">Total Pemasukan</div>
                            <div class="value">{{ \App\Support\MoneyFormatter::format($summary['income']) }}</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="summary-gap"></td>
            <td class="summary-cell">
                <table class="summary-card">
                    <tr>
                        <td>
                            <div class="label">Total Pengeluaran</div>
                            <div class="value">{{ \App\Support\MoneyFormatter::format($summary['expense']) }}</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="summary-gap"></td>
            <td class="summary-cell">
                <table class="summary-card">
                    <tr>
                        <td>
                            <div class="label">Saldo Akhir</div>
                            <div class="value {{ $summary['saldo'] < 0 ? 'neg' : '' }}">{{ \App\Support\MoneyFormatter::format($summary['saldo']) }}</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- BREAKDOWN PER BULAN --}}
    <h3 class="section-title">Kas Arus per Bulan</h3>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 28%;">Bulan</th>
                <th style="width: 24%; text-align: right;">Pemasukan</th>
                <th style="width: 24%; text-align: right;">Pengeluaran</th>
                <th style="width: 24%; text-align: right;">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="text-right">{{ \App\Support\MoneyFormatter::format($row['income']) }}</td>
                    <td class="text-right">{{ \App\Support\MoneyFormatter::format($row['expense']) }}</td>
                    <td class="text-right {{ $row['saldo'] < 0 ? 'neg' : 'pos' }}">{{ \App\Support\MoneyFormatter::format($row['saldo']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="muted">Tidak ada data pemasukan maupun pengeluaran pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- TOTAL KESELURUHAN PERIODE --}}
    <table class="total">
        <tr>
            <td>Total Pemasukan &amp; Pengeluaran</td>
            <td class="text-right">
                {{ \App\Support\MoneyFormatter::format($summary['income']) }}
                &middot;
                {{ \App\Support\MoneyFormatter::format($summary['expense']) }}
            </td>
        </tr>
        <tr>
            <td class="{{ $summary['saldo'] < 0 ? 'neg-bg' : '' }}">Saldo Akhir (Pemasukan &minus; Pengeluaran)</td>
            <td class="text-right {{ $summary['saldo'] < 0 ? 'neg-bg' : '' }}">{{ \App\Support\MoneyFormatter::format($summary['saldo']) }}</td>
        </tr>
    </table>

    <div class="report-footer">
        Laporan ini dibuat otomatis oleh Catatan Belanja — pemasukan &amp; pengeluaran dihitung hanya dari transaksi milik akun Anda (ter-scope per user) yang lolos filter periode.
    </div>
</body>
</html>

