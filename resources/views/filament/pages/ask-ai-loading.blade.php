{{--
    Indikator loading untuk modal "Tanya AI" (dirinya dirender lewat
    ->modalContentFooter() pada askAiAction).

    Tampil HANYA selama request Livewire `callMountedAction` (submit
    pertanyaan) sedang berjalan, sehingga user tahu AI sedang bekerja tanpa
    harus menebak apakah tombol "Tanya" sempat diproses.

    Kenapa memakai inline style, bukan utility Tailwind?
    ----------------------------------------------------
    View ini dirender sebagai RAW HTML di dalam modal Filament, sedangkan tema
    bawaan Filament TIDAK memindai view aplikasi — jadi class seperti `hidden`
    / `flex` / `text-gray-500` tidak ikut ter-compile (lihat catatan serupa di
    resources/views/filament/categories/budget-progress.blade.php). Warna netral
    memakai design token var(--cb-*) yang otomatis berbalik saat dark mode
    (didefinisikan + di-override di public/css/admin-panel.css).

    Mekanisme show/hide:
      - `wire:loading.flex`  → element jadi display:flex saat request berjalan,
                               dan display:none saat idle.
      - `wire:target="callMountedAction"` → HANYA request submit yang memicunya,
                               sehingga indikator tidak ikut kedip saat widget
                               dashboard melakukan wire:poll.
      - `style="display:none"` awal hanya mencegah kilatan (flash) sebelum
                               JavaScript Livewire sempat menginisialisasi
                               directive-nya.
--}}
<div
    wire:loading.flex
    wire:target="callMountedAction"
    style="display: none; align-items: center; gap: 0.5rem; margin-top: 0.75rem; font-size: 0.875rem; line-height: 1.25rem; color: var(--cb-muted);"
>
    <x-filament::loading-indicator style="width: 1rem; height: 1rem; flex-shrink: 0;" />
    <span>AI sedang menjawab...</span>
</div>
