{{-- Badge "Sedang diproses..." — indikator parsing OCR/AI yang masih berjalan.
     Muncul hanya saat $isWaitingForParsing true. wire:poll.3s memanggil
     checkParsingStatus() pada page component setiap 3 detik; begitu hasil
     parsing (vendor/amount) terisi, elemen ini hilang dari DOM sehingga
     polling otomatis berhenti dan data terbaru tampil tanpa refresh manual. --}}
@if ($isWaitingForParsing)
    <div wire:poll.3s="checkParsingStatus" class="flex w-full">
        <x-filament::badge
            color="warning"
            icon="heroicon-o-arrow-path"
            class="animate-pulse"
        >
            <span class="inline-flex items-center gap-1.5">
                <x-filament::loading-indicator class="h-4 w-4" />
                Sedang diproses...
            </span>
        </x-filament::badge>
    </div>
@endif
