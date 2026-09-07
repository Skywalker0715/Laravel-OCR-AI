{{-- Bar progress pemakaian budget kategori pada halaman View Category
     (infolist CategoryResource); angka (spent, limit, percent, period)
     dihitung di CategoryResource::infolist(), view ini hanya menampilkan.
     Warna batang: hijau (<75%), oranye (75-99%), merah (>=100%).

     PENTING (dark mode): view dirender sebagai RAW HTML (Html::make), sehingga
     utility Tailwind (termasuk varian dark:*) TIDAK ikut ter-compile ke tema
     bawaan Filament. Semua styling karenanya ditulis inline, dan warna netral
     memakai design token var(--cb-...) (didefinisikan + di-override html.dark
     di AdminPanelProvider) agar tetap benar dan ikut berubah saat dark mode. --}}
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
        <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.5rem; font-size: 0.875rem; line-height: 1.25rem;">
            <span style="font-weight: 500; color: var(--cb-muted);">Periode {{ $period }}</span>
            <span style="font-weight: 700; color: {{ $labelColor }};">{{ $percent }}%</span>
        </div>

        {{-- Clamp 2-100% agar batang tetap terlihat pada nilai ekstrem;
             track memakai var(--cb-track) karena class Tailwind tidak
             ter-compile untuk raw HTML (lihat catatan atas). --}}
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

