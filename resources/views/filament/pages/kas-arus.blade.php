<x-filament-panels::page>
    {{-- Filter + widget ringkasan/chart via schema content (pola sama dengan Laporan). --}}
    {{ $this->content }}

    {{-- Tabel breakdown per bulan — data diambil langsung dari KasArusReport
         sehingga angkanya dijamin sama dengan ringkasan, chart, dan export.

         PENTING (jangan diganti dengan utility Tailwind): halaman panel Filament
         hanya memuat css/filament/filament/app.css + public/css/admin-panel.css,
         TANPA build Tailwind (resources/css/app.css tidak diload di panel), sehingga
         class seperti py-2/pe-4/text-end/divide-y tidak punya CSS sama sekali —
         akibatnya sel tabel tanpa padding/border dan semua kolom menempel jadi
         satu baris teks. Semua styling di bawah ditulis inline dengan design token
         var(--cb-*) yang didefinisikan + di-override mode gelap di
         public/css/admin-panel.css — pola yang sama dengan view panel lain
         (filament/categories/recent-expenses.blade.php & filament/components/
         item-list.blade.php). --}}
    @php
        $breakdown = $this->monthlyBreakdown();
        $totals = $this->totals();
    @endphp

    <x-filament::section
        heading="Breakdown per Bulan"
        description="Pemasukan dikurangi pengeluaran per bulan pada periode yang dipilih."
    >
        {{-- Wrapper overflow-x inline: di layar sempit yang menggulir horizontal
             adalah WRAPPER-nya (table punya min-width), bukan kolom yang terpotong. --}}
        <div style="width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <table style="width: 100%; min-width: 40rem; font-size: 0.875rem; line-height: 1.25rem; border-collapse: collapse;">
                <thead>
                    {{-- Header: background khas sel "surface" + teks muted tebal —
                         kontras dengan isi tabel (pola header tabel item-list). --}}
                    <tr style="background-color: var(--cb-surface);">
                        <th style="padding: 0.5rem 1rem; text-align: left; font-weight: 600; color: var(--cb-muted); border-bottom: 1px solid var(--cb-border); white-space: nowrap;">Bulan</th>
                        <th style="padding: 0.5rem 1rem; text-align: right; font-weight: 600; color: var(--cb-muted); border-bottom: 1px solid var(--cb-border); white-space: nowrap;">Pemasukan</th>
                        <th style="padding: 0.5rem 1rem; text-align: right; font-weight: 600; color: var(--cb-muted); border-bottom: 1px solid var(--cb-border); white-space: nowrap;">Pengeluaran</th>
                        <th style="padding: 0.5rem 1rem; text-align: right; font-weight: 600; color: var(--cb-muted); border-bottom: 1px solid var(--cb-border); white-space: nowrap;">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($breakdown as $row)
                        <tr>
                            <td style="padding: 0.5rem 1rem; text-align: left; color: var(--cb-strong); border-bottom: 1px solid var(--cb-border); white-space: nowrap;">{{ $row['label'] }}</td>
                            <td style="padding: 0.5rem 1rem; text-align: right; color: var(--cb-strong); border-bottom: 1px solid var(--cb-border); font-variant-numeric: tabular-nums; white-space: nowrap;">
                                {{ \App\Support\MoneyFormatter::format($row['income']) }}
                            </td>
                            <td style="padding: 0.5rem 1rem; text-align: right; color: var(--cb-strong); border-bottom: 1px solid var(--cb-border); font-variant-numeric: tabular-nums; white-space: nowrap;">
                                {{ \App\Support\MoneyFormatter::format($row['expense']) }}
                            </td>
                            {{-- Warna saldo mengikuti tanda: hijau positif, merah defisit. --}}
                            <td style="padding: 0.5rem 1rem; text-align: right; font-weight: 500; border-bottom: 1px solid var(--cb-border); font-variant-numeric: tabular-nums; white-space: nowrap; color: {{ $row['saldo'] >= 0 ? '#059669' : '#DC2626' }};">
                                {{ \App\Support\MoneyFormatter::format($row['saldo']) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="padding: 1rem; text-align: center; color: var(--cb-muted); border-bottom: 1px solid var(--cb-border);">
                                Tidak ada data pada periode ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                {{-- Baris "Total Periode" = ringkasan, bukan baris bulan biasa:
                     dibedakan lewat background surface + huruf tebal + garis atas
                     tebal 2px (gaya sama dengan baris total di tabel item-list). --}}
                <tfoot>
                    <tr style="background-color: var(--cb-surface);">
                        <td style="padding: 0.5rem 1rem; text-align: left; font-weight: 700; color: var(--cb-strong); border-top: 2px solid var(--cb-border); white-space: nowrap;">Total Periode</td>
                        <td style="padding: 0.5rem 1rem; text-align: right; font-weight: 700; color: var(--cb-strong); border-top: 2px solid var(--cb-border); font-variant-numeric: tabular-nums; white-space: nowrap;">
                            {{ \App\Support\MoneyFormatter::format($totals['income']) }}
                        </td>
                        <td style="padding: 0.5rem 1rem; text-align: right; font-weight: 700; color: var(--cb-strong); border-top: 2px solid var(--cb-border); font-variant-numeric: tabular-nums; white-space: nowrap;">
                            {{ \App\Support\MoneyFormatter::format($totals['expense']) }}
                        </td>
                        <td style="padding: 0.5rem 1rem; text-align: right; font-weight: 700; border-top: 2px solid var(--cb-border); font-variant-numeric: tabular-nums; white-space: nowrap; color: {{ $totals['saldo'] >= 0 ? '#059669' : '#DC2626' }};">
                            {{ \App\Support\MoneyFormatter::format($totals['saldo']) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
