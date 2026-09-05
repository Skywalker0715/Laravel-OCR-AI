<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Pengeluaran</title>
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
            /* Margin halaman yang lega agar tabel/kartu tidak mepet ke tepi:
               atas 15mm, kiri-kanan 12mm, bawah 18mm (ruang ekstra untuk footer). */
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

        h3.section-title { font-size: 12px; margin: 14px 0 6px; color: #10B981; text-transform: uppercase; letter-spacing: 0.5px; }

        /* overflow-wrap: anywhere mencegah judul/vendor hasil OCR (bisa berupa
           deretan huruf tanpa spasi) melebarkan tabel: min-width sel turun jadi
           selebar satu karakter sehingga tabel tetap muat di halaman. Catatan:
           `word-wrap: break-word` SAJA tidak cukup — dompdf tetap memakai lebar
           kata penuh untuk kalkulasi min-width kolom sehingga tabel bisa tetap
           meluber ke kanan. */
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.data th, table.data td { overflow-wrap: anywhere; }
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

        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 4px; vertical-align: middle; margin-right: 5px; }

        table.total { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.total td { padding: 7px 8px; font-weight: bold; background-color: #ECFDF5; border: 1px solid #10B981; }

        .report-footer { margin-top: 18px; color: #9CA3AF; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
    {{-- KOP LAPORAN --}}
    <table class="report-header">
        <tr>
            <td>
                <div class="brand">Catatan Belanja</div>
                <div class="title">Laporan Pengeluaran</div>
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
                            <div class="label">Total Pengeluaran</div>
                            <div class="value">{{ \App\Support\MoneyFormatter::format($summary['total']) }}</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="summary-gap"></td>
            <td class="summary-cell">
                <table class="summary-card">
                    <tr>
                        <td>
                            <div class="label">Jumlah Transaksi</div>
                            <div class="value">{{ $summary['count'] }}</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="summary-gap"></td>
            <td class="summary-cell">
                <table class="summary-card">
                    <tr>
                        <td>
                            <div class="label">Rata-rata per Transaksi</div>
                            <div class="value">{{ \App\Support\MoneyFormatter::format($summary['average']) }}</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- BREAKDOWN PER KATEGORI --}}
    <h3 class="section-title">Pengeluaran per Kategori</h3>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 38%;">Kategori</th>
                <th style="width: 18%;">Transaksi</th>
                <th style="width: 18%;">Porsi</th>
                <th style="width: 26%; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($categoryBreakdown as $row)
                <tr>
                    <td><span class="dot" style="background-color: {{ $row['color'] }};"></span>{{ $row['name'] }}</td>
                    <td>{{ $row['count'] }}</td>
                    <td>
                        @if ($summary['total'] > 0)
                            {{ round($row['total'] / $summary['total'] * 100, 1) }}%
                        @else
                            0%
                        @endif
                    </td>
                    <td class="text-right">{{ \App\Support\MoneyFormatter::format($row['total']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="muted">Tidak ada pengeluaran pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>


    {{-- DETAIL TRANSAKSI --}}
    <h3 class="section-title">Detail Transaksi ({{ $summary['count'] }})</h3>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 30%;">Judul</th>
                <th style="width: 17%;">Vendor</th>
                <th style="width: 13%;">Tanggal</th>
                <th style="width: 17%;">Kategori</th>
                <th style="width: 18%; text-align: right;">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($expenses as $expense)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $expense->title ?? '—' }}</td>
                    <td>{{ $expense->vendor ?? '—' }}</td>
                    <td>{{ $expense->date_shopping?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $expense->category?->name ?? 'Tanpa Kategori' }}</td>
                    <td class="text-right">{{ \App\Support\MoneyFormatter::format($expense->amount) ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="muted">Tidak ada transaksi pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- TOTAL KESELURUHAN --}}
    @if ($summary['count'] > 0)
        <table class="total">
            <tr>
                <td>Total {{ $summary['count'] }} Transaksi</td>
                <td class="text-right">{{ \App\Support\MoneyFormatter::format($summary['total']) }}</td>
            </tr>
        </table>
    @endif

    <div class="report-footer">
        Laporan ini dibuat otomatis oleh Catatan Belanja — total &amp; rata-rata dihitung hanya dari transaksi yang lolos filter periode &amp; kategori.
    </div>
</body>
</html>
