{{-- Tabel "5 Transaksi Terakhir" pada halaman View Category (infolist
     CategoryResource), menerima $expenses dari Category::recentExpensesForUser().

     PENTING (dark mode): view dirender sebagai RAW HTML (Html::make), sehingga
     utility Tailwind (termasuk varian dark:*) TIDAK ikut ter-compile ke tema
     bawaan Filament — semua warna memakai design token var(--cb-...)
     (didefinisikan + di-override html.dark di AdminPanelProvider) dan styling
     krusial ditulis inline. Wrapper membawa overflow-x: auto inline + tabel
     min-width: 30rem agar di layar sempit WRAPPER yang menggulir horizontal —
     kolom nowrap (Tanggal/Jumlah) tidak dipaksa menyusut dan isinya tidak
     menjorok keluar sel. --}}
@if ($expenses->isNotEmpty())
    <div style="width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch;">
        {{-- table-layout: fixed + width persentase pada <th> menjaga kolom
             Judul/Tanggal/Jumlah selalu sejajar; min-width membuat WRAPPER di
             atas yang menggulir saat kontainer lebih sempit (lihat catatan
             atas). --}}
        <table style="width: 100%; min-width: 30rem; font-size: 0.875rem; line-height: 1.25rem; border-collapse: collapse; table-layout: fixed;">
            <thead>
                <tr>
                    <th style="width: 48%; padding: 0.5rem; text-align: left; font-weight: 600; color: var(--cb-muted); border-bottom: 1px solid var(--cb-border);">Judul</th>
                    <th style="width: 24%; padding: 0.5rem; text-align: left; font-weight: 600; color: var(--cb-muted); border-bottom: 1px solid var(--cb-border);">Tanggal</th>
                    <th style="width: 28%; padding: 0.5rem; text-align: right; font-weight: 600; color: var(--cb-muted); border-bottom: 1px solid var(--cb-border);">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($expenses as $expense)
                    <tr>
                        <td style="padding: 0.5rem; font-weight: 500; color: var(--cb-strong); border-bottom: 1px solid var(--cb-border); overflow-wrap: anywhere;">
                            {{ $expense->title ?? '—' }}
                        </td>
                        <td style="padding: 0.5rem; text-align: left; color: var(--cb-muted); border-bottom: 1px solid var(--cb-border); white-space: nowrap;">
                            {{ $expense->date_shopping?->format('d M Y') ?? '—' }}
                        </td>
                        <td style="padding: 0.5rem; text-align: right; font-weight: 500; color: var(--cb-strong); border-bottom: 1px solid var(--cb-border); white-space: nowrap;">
                            {{ \App\Support\MoneyFormatter::format($expense->amount) ?? '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p style="font-size: 0.875rem; line-height: 1.25rem; color: var(--cb-muted);">
        Belum ada transaksi yang memakai kategori ini.
    </p>
@endif

