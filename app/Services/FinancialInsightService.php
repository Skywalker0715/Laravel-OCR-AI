<?php

namespace App\Services;

use App\Exceptions\RateLimitExceededException;
use App\Models\AiInsightQuery;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class FinancialInsightService
{
    public function ask(User $user, string $question): string
    {
        $key = 'ai-insight:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw new RateLimitExceededException(
                'Sudah mencapai batas tanya AI hari ini, coba lagi besok.'
            );
        }
        RateLimiter::hit($key, 86400);

        $summary = $this->buildSummary($user);
        $model = config('services.cohere.insight_model', config('services.cohere.model', 'command-r7b-12-2024'));

        try {
            $response = Http::withToken(config('services.cohere.api_key'))
                ->connectTimeout(5)
                ->timeout(60)
                ->retry(2, 500, $this->shouldRetry(...), false)
                ->post('https://api.cohere.ai/v1/chat', [
                    'model' => $model,
                    'message' => $this->buildPrompt($summary, $question),
                    'max_tokens' => 1000,
                    'temperature' => 0.2,
                ]);

            if (! $response->successful()) {
                Log::error('Cohere insight gagal: ' . $response->body());
                return 'Maaf, sistem sedang bermasalah. Coba lagi nanti.';
            }

            $raw = $response->json('text') ?? $response->json('message.content.0.text') ?? '';
            if ($raw === '') {
                return 'Maaf, tidak mendapat respons dari AI.';
            }

            $this->saveHistory($user, $question, trim($raw));
            return trim($raw);
        } catch (Throwable $e) {
            Log::warning('Cohere insight error: ' . $e->getMessage());
            return 'Maaf, sistem sedang bermasalah. Coba lagi nanti.';
        }
    }

    /**
     * Simpan riwayat pertanyaan ke database (di-scope per user via OwnedByUserScope).
     */
    private function saveHistory(User $user, string $question, string $answer): void
    {
        AiInsightQuery::create([
            'user_id' => $user->id,
            'question' => $question,
            'answer' => $answer,
        ]);
    }

    private function buildSummary(User $user): string
    {
        $since = now()->subDays(90)->startOfDay();
        $expenses = Expense::query()->where('date_shopping', '>=', $since)->get();

        if ($expenses->isEmpty()) {
            return 'Tidak ada data pengeluaran dalam 90 hari terakhir.';
        }

        $lines = [];
        $total = (float) $expenses->sum('amount');
        $lines[] = sprintf('Total pengeluaran 90 hari terakhir: Rp %s (%d transaksi).',
            number_format($total, 0, ',', '.'), $expenses->count());
        $lines[] = sprintf('Rata-rata pengeluaran harian: Rp %s.',
            number_format($total / 90, 0, ',', '.'));

        $lines[] = 'Rincian per kategori per bulan:';
        $monthly = $expenses->groupBy(fn ($e) => $e->date_shopping->format('Y-m'));
        foreach ($monthly as $month => $monthExp) {
            $byCat = $monthExp->groupBy(fn ($e) => $e->category?->name ?? 'Tanpa Kategori');
            $lines[] = "  Bulan $month:";
            foreach ($byCat as $catName => $catExp) {
                $lines[] = sprintf('    - %s: Rp %s (%d transaksi)',
                    $catName,
                    number_format((float) $catExp->sum('amount'), 0, ',', '.'),
                    $catExp->count());
            }
        }

        $lines[] = '5 kategori terbesar (seluruh 90 hari):';
        $byCatAll = $expenses->groupBy(fn ($e) => $e->category?->name ?? 'Tanpa Kategori');
        $topCats = $byCatAll
            ->map(fn ($items) => [
                'name' => $items->first()?->category?->name ?? 'Tanpa Kategori',
                'total' => (float) $items->sum('amount'),
            ])
            ->sortByDesc('total')
            ->take(5);
        $rank = 0;
        foreach ($topCats as $cat) {
            $rank++;
            $lines[] = sprintf('  %d. %s: Rp %s',
                $rank,
                $cat['name'],
                number_format($cat['total'], 0, ',', '.'));
        }

        $lines[] = 'Pembelanjaan per vendor (top 5):';
        $byVendor = $expenses->groupBy('vendor');
        $topVendors = $byVendor
            ->map(fn ($items) => [
                'vendor' => $items->first()->vendor,
                'total' => (float) $items->sum('amount'),
            ])
            ->sortByDesc('total')
            ->take(5);
        $rank = 0;
        foreach ($topVendors as $vendor) {
            $rank++;
            $vendorExp = $byVendor->get($vendor['vendor']);
            $lines[] = sprintf('  %d. %s: Rp %s (%d transaksi)',
                $rank,
                $vendor['vendor'] ?? 'Tanpa Nama',
                number_format($vendor['total'], 0, ',', '.'),
                $vendorExp ? $vendorExp->count() : 0);
        }

        return implode("\n", $lines);
    }

    private function buildPrompt(string $summary, string $question): string
    {
        $system = 'Kamu asisten keuangan. Jawab HANYA berdasarkan data yang diberikan, JANGAN mengarang angka yang tidak ada di data. Kalau data tidak cukup untuk menjawab, katakan dengan jujur bahwa datanya tidak tersedia.';

        return <<<PROMPT
$system

--- DATA PENGELUARAN USER (90 hari terakhir) ---
$summary
--- AKHIR DATA ---

Pertanyaan user:
$question

Jawaban:
PROMPT;
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof RequestException) {
            $status = $exception->response?->status() ?? 0;
            return $status === 429 || $status >= 500;
        }

        return $exception instanceof ConnectionException;
    }
}