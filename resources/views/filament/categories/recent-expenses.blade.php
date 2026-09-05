{{-- Tabel kecil "5 Transaksi Terakhir" pada halaman View Category (infolist
     CategoryResource). Dirender via komponen Schema Html sehingga menerima
     $expenses sebagai Eloquent Collection hasil Category::recentExpensesForUser().

     PENTING (dark mode): view ini dirender sebagai RAW HTML (Html::make),
     sehingga utility Tailwind (termasuk varian `dark:*`) TIDAK ikut ter-
     compile ke tema bawaan Filament — class Tailwind tidak boleh diandalkan
     untuk warna. Semua warna karenanya memakai design token var(--cb-...)
     (didefinisikan + di-override html.dark di AdminPanelProvider) sehingga
     ikut berubah saat toggle dark mode. Lebar kolom (persentase), padding,
     dan text-align tetap ditulis inline agar struktur tabel selalu rapi.

     RESPONSIF (mobile < 640px): wrapper membawa overflow-x: auto INLINE dan
     tabel membawa min-width: 30rem sehingga di layar sempit tabel digulir
     horizontal OLEH WRAPPER — bukan dipaksa muat (kolom nowrap "Tanggal"/
     "Jumlah" tidak lagi menjorok keluar sel dan saling menimpa). --}}
@if ($expenses->isNotEmpty())
    {{-- Wrapper scroll horizontal. overflow-x ditulis INLINE (bukan hanya class
         Tailwind) agar wrapper benar-benar bisa menggulir meski class Tailwind
         tidak ter-compile (konten raw HTML). --}}
    <div style="width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch;">
        {{-- table-layout: fixed + width persentase pada <th> membuat kolom
             Judul/Tanggal/Jumlah selalu sejajar dan tidak menyusut mengikuti
             panjang isinya. min-width menjaga tabel tetap lega di layar sempit:
             bila kontainer lebih sempit dari min-width, WRAPPER di atas yang
             menampilkan scrollbar — kolom nowrap (Tanggal/Jumlah) tidak dipaksa
             menyusut sehingga isinya menjorok keluar sel. Padding sel diturunkan
             ke 0.5rem (px-2) agar lebar minimum tetap ringkas di mobile. --}}
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

