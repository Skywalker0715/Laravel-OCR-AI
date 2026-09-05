{{-- Progress pemakaian budget TERBARU kategori (periode apa pun) pada halaman
     View Category (infolist CategoryResource). Angka (spent, limit, percent,
     period) dihitung di CategoryResource::infolist(); blade ini hanya
     menampilkan. Warna batang mengikuti persentase pemakaian: hijau (<75%),
     oranye (75-99%), merah (>=100% / over budget). Warna aksen ditulis inline
     karena dipilih dinamis sesuai persentase.

     PENTING (dark mode): view ini dirender sebagai RAW HTML (Html::make),
     sehingga utility Tailwind (termasuk varian `dark:*`) TIDAK ikut ter-
     compile ke tema bawaan Filament. Warna netral (track bar, teks sekunder)
     karenanya memakai design token var(--cb-...) — didefinisikan + di-
     override html.dark di AdminPanelProvider — supaya ikut berubah saat
     toggle dark mode dipakai.

     RESPONSIF: struktur layout (flex baris periode, tinggi/radius batang,
     ukuran font) juga ditulis inline — identik dengan nilai class Tailwind-nya
     — supaya tampilan tidak bergantung pada class yang tidak dijamin ter-
     compile untuk konten raw HTML. flex-wrap menjaga baris "Periode + %"
     tetap rapi di layar sangat sempit (<360px): label persen pindah ke baris
     sendiri alih-alih menumpuk. --}}
@if ($budget)
    @php
        $barColor = match (true) {
            $percent >= 100 => '#EF4444',
            $percent >= 75 => '#F59E0B',
            default => '#10B981',
        };
        $labelColor = $percent >= 100 ? '#EF4444' : ($percent >= 75 ? '#D97706' : '#059669');
    @endphp
    <div style="width: 100%;">
        {{-- Layout baris periode+persen ditulis inline (bukan hanya class
             Tailwind) agar tetap benar saat dirender sebagai raw HTML. --}}
        <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.5rem; font-size: 0.875rem; line-height: 1.25rem;">
            <span style="font-weight: 500; color: var(--cb-muted);">Periode {{ $period }}</span>
            <span style="font-weight: 700; color: {{ $labelColor }};">{{ $percent }}%</span>
        </div>

        {{-- Lebar batang di-clamp 2-100% agar tetap terlihat pada nilai ekstrem.
             Track bar memakai var(--cb-track): terang di light mode, gelap di
             dark mode (dulu class `bg-gray-200 dark:bg-gray-700` tidak pernah
             ter-compile sehingga track sama sekali tidak berwarna). --}}
        <div style="height: 0.625rem; width: 100%; overflow: hidden; border-radius: 9999px; background-color: var(--cb-track);">
            <div style="height: 100%; border-radius: 9999px; width: {{ min(100, max(2, $percent)) }}%; background-color: {{ $barColor }};"></div>
        </div>

        <p style="margin-top: 0.5rem; font-size: 0.875rem; line-height: 1.25rem; color: var(--cb-muted);">
            Terpakai
            <span style="font-weight: 600; color: var(--cb-strong);">{{ \App\Support\MoneyFormatter::format($spent) }}</span>
            dari batas
            <span style="font-weight: 600; color: var(--cb-strong);">{{ \App\Support\MoneyFormatter::format($limit) }}</span>
            — sisa
            <span style="font-weight: 600; color: {{ $percent >= 100 ? '#EF4444' : '#059669' }};">
                {{ \App\Support\MoneyFormatter::format(max(0, $limit - $spent)) }}
            </span>
        </p>
    </div>
@else
    <p style="font-size: 0.875rem; line-height: 1.25rem; color: var(--cb-muted);">
        Belum ada budget untuk kategori ini. Buat lewat menu Anggaran untuk
        mulai memantau pemakaian per kategori.
    </p>
@endif

