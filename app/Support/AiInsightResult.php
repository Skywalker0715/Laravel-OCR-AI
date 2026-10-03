<?php

namespace App\Support;

/**
 * Hasil terstruktur dari fitur "Tanya AI" (App\Services\FinancialInsightService).
 *
 * Objek ini Replacing string polos sebagai nilai balik ask(): UI (Dashboard)
 * hanya perlu memeriksa $result->status untuk menentukan gaya modal, bukan
 * menebak-nebak dari isi kalimatnya.
 *
 * Dipakai DTO (bukan array) karena:
 *  - kunci array mudah salah ketik diam-diam dan tidak dicek oleh IDE;
 *  - named constructor (::answered(), ::rateLimited(), ...) membuat intention
 *    di call-site jelas dan menjaga tiap status wajib punya teks pesan yang
 *    cocok — teks untuk user tetap persis sama seperti sebelumnya.
 */
final readonly class AiInsightResult
{
    /**
     * @param  string  $text  Teks yang ditampilkan ke user (tanpa markdown).
     * @param  AiInsightStatus  $status  Status terstruktur hasil pemanggilan.
     * @param  int|null  $retryAfterSeconds  Sisa detik sampai penghitung limiter terkait
     *                                       kadaluarsa (null bila tidak relevan).
     */
    private function __construct(
        public string $text,
        public AiInsightStatus $status,
        public ?int $retryAfterSeconds = null,
    ) {}

    /** Jawaban AI valid. */
    public static function answered(string $text): self
    {
        return new self($text, AiInsightStatus::Answered);
    }

    /** Jawaban AI sudah sampai, tapi tidak layak tampil (loop token / kosong). */
    public static function invalidAnswer(string $text): self
    {
        return new self($text, AiInsightStatus::InvalidAnswer);
    }

    /** Kuota harian habis. */
    public static function rateLimited(string $text, int $retryAfterSeconds): self
    {
        return new self($text, AiInsightStatus::RateLimited, $retryAfterSeconds);
    }

    /** Limiter anti-spam per menit habis. */
    public static function tooManyRequests(string $text, int $retryAfterSeconds): self
    {
        return new self($text, AiInsightStatus::TooManyRequests, $retryAfterSeconds);
    }

    /** Gangguan sisi penyedia (5xx / timeout / koneksi). */
    public static function providerError(string $text): self
    {
        return new self($text, AiInsightStatus::ProviderError);
    }

    /** Respons 2xx tapi body kosong. */
    public static function emptyResponse(string $text): self
    {
        return new self($text, AiInsightStatus::EmptyResponse);
    }

    /** Tampilkan gaya "limit" (kuning/oranye) di modal Jawaban AI? */
    public function isLimitNotice(): bool
    {
        return $this->status->isLimitNotice();
    }

    /**
     * Gangguan teknis sehingga tidak ada jawaban sama sekali?
     *
     * PENTING: Dashboard TIDAK memakai ini untuk flag isError — modal memakai
     * isError hanya untuk exception yang belum tertangani, sedangkan gangguan
     * Cohere sengaja ditampilkan sebagai jawaban biasa supaya user tidak
     * bingung dengan stack trace.
     */
    public function isProviderFailure(): bool
    {
        return $this->status->isProviderFailure();
    }
}
