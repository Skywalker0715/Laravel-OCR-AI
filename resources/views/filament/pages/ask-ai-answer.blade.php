{{--
    Isi modal "Jawaban AI" (action showAiAnswer di App\Filament\Pages\Dashboard).

    Modal ini menggantikan notifikasi/toast lama: jawaban AI sekarang dibuka
    sebagai modal Filament kedua lewat replaceMountedAction(), sehingga tidak
    bisa terhapus oleh race condition wire:poll widget (lihat processAskAi()).

    Data datang dari arguments modal:
      - $question : pertanyaan user apa adanya.
      - $answer   : jawaban AI yang sudah dibersihkan dari markdown
                    (AiAnswerSanitizer::stripMarkdown) — jaring terakhir.
      - $isError  : true bila FinancialInsightService melempar exception
                    (kegagalan teknis, bukan jawaban AI).
      - $isLimit  : true bila jawaban berasal dari guard rate limit harian
                    ("Batas pertanyaan harian tercapai ...").

    PENTING (dark mode): view dirender sebagai RAW HTML, sehingga utility
    Tailwind (termasuk varian dark:*) TIDAK ikut ter-compile ke tema bawaan
    Filament. Semua styling ditulis inline, warna netral memakai design token
    var(--cb-*) agar ikut berubah saat dark mode.
--}}
@php
    // Warna aksen per status: merah (gagal), oranye (kuota habis), hijau
    // (jawaban normal — senada warna brand #10B981).
    $tones = [
        'error' => [
            'title' => 'Gagal memproses pertanyaan',
            'color' => '#EF4444',
            'background' => 'rgba(239, 68, 68, 0.10)',
        ],
        'limit' => [
            'title' => 'Batas pertanyaan harian tercapai',
            'color' => '#F59E0B',
            'background' => 'rgba(245, 158, 11, 0.10)',
        ],
        'answer' => [
            'title' => 'Jawaban AI',
            'color' => '#10B981',
            'background' => 'rgba(16, 185, 129, 0.10)',
        ],
    ];

    $tone = match (true) {
        (bool) $isError => 'error',
        (bool) $isLimit => 'limit',
        default => 'answer',
    };

    $meta = $tones[$tone];
@endphp

<div style="display: flex; flex-direction: column; gap: 0.75rem;">
    {{-- Pertanyaan user — dikutip balik supaya jelas sedang dijawab apa. --}}
    <div style="border: 1px solid var(--cb-border); border-radius: 0.75rem; background-color: var(--cb-surface); padding: 0.75rem 1rem;">
        <div style="font-size: 0.75rem; line-height: 1rem; letter-spacing: 0.04em; text-transform: uppercase; color: var(--cb-muted); margin-bottom: 0.25rem;">
            Pertanyaan Anda
        </div>
        <div style="font-size: 0.875rem; line-height: 1.375rem; color: var(--cb-strong);">{{ $question }}</div>
    </div>

    {{-- Jawaban AI. `white-space: pre-line` membuat newline (\n) tampil sebagai
         baris baru tanpa harus memakai nl2br() — aman karena teks sudah di-escape
         oleh {{ }}. --}}
    <div style="border: 1px solid var(--cb-border); border-inline-start: 4px solid {{ $meta['color'] }}; border-radius: 0.75rem; background-color: {{ $meta['background'] }}; padding: 0.75rem 1rem;">
        <div style="font-size: 0.75rem; line-height: 1rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: {{ $meta['color'] }}; margin-bottom: 0.375rem;">
            {{ $meta['title'] }}
        </div>
        <div style="white-space: pre-line; font-size: 0.9375rem; line-height: 1.5rem; color: var(--cb-strong);">{{ $answer }}</div>
    </div>

    {{-- Disclaimer: jawaban dihasilkan AI, bukan angka audit. --}}
    <p style="font-size: 0.75rem; line-height: 1rem; color: var(--cb-muted);">
        Dibuat otomatis oleh AI berdasarkan data catatan Anda.
    </p>
</div>
