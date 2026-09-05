<x-filament-panels::page>
    {{-- Seluruh isi halaman Laporan (form filter, widget ringkasan & grafik,
         serta tabel transaksi) didefinisikan sebagai schema "content" di
         App\Filament\Pages\Laporan — view ini hanya me-render-nya. --}}
    {{ $this->content }}
</x-filament-panels::page>
