<?php

namespace App\Support;

/**
 * Status hasil satu pemanggilan fitur "Tanya AI".
 *
 * Dulu "apakah ini pesan limit / error atau jawaban AI biasa?" dijawab di
 * sisi UI dengan cara membaca TEKS jawaban (str_contains). Itu rapuh: begitu
 * copy pesan diubah sedikit, atau AI kebetulan menulis kalimat yang mirip,
 * label ikon/warna di modal ikut salah. Karena itu statusnya sekarang jadi
 * data, bukan hasil tebakan dari string.
 *
 * belongedTo: App\Services\FinancialInsightService (produsen),
 * App\Filament\Pages\Dashboard (konsumen).
 */
enum AiInsightStatus: string
{
    /** Cohere menjawab dan jawabannya lolos guard. */
    case Answered = 'answered';

    /** Cohere menjawab, tapi jawabannya rusak (loop token / kosong) → pesan fallback. */
    case InvalidAnswer = 'invalid_answer';

    /** Kuota harian user habis ( limiter 'harian'). */
    case RateLimited = 'rate_limited';

    /** Limiter anti-spam per menit habis — user menekan tombol terlalu cepat. */
    case TooManyRequests = 'too_many_requests';

    /** Cohere mengembalikan 5xx / timeout / gagal koneksi. */
    case ProviderError = 'provider_error';

    /** Cohere menjawab 2xx tapi body-nya kosong. */
    case EmptyResponse = 'empty_response';

    /**
     * Pesan ini menampilkan penanda "limit" (ikon jam / warna oranye) di modal
     * Jawaban AI — sama untuk kuota harian maupun limiter per menit.
     */
    public function isLimitNotice(): bool
    {
        return $this === self::RateLimited || $this === self::TooManyRequests;
    }

    /** Gangguan sisi penyedia (server/timeout) — BUKAN salah input user. */
    public function isProviderFailure(): bool
    {
        return $this === self::ProviderError || $this === self::EmptyResponse;
    }
}
