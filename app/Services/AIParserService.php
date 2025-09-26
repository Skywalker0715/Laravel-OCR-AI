<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIParserService extends Helper
{
    public function parseWithAI(string $text): ?array
    {
        Log::info('Raw OCR text for parsing: ' . $text);

        $apiKey = env('COHERE_API_KEY'); // simpan di .env

        $prompt = <<<PROMPT
        Analisis teks struk belanja Indonesia ini dan ekstrak informasi ke format JSON yang tepat. Hapus semua mata uang 'Rp', titik, dan koma untuk membuat nilai numerik murni. Tanggal dalam format YYYY-MM-DD. Vendor adalah nama toko di bagian atas. Items: ekstrak qty (angka), name (deskripsi), price (numerik), subtotal (qty * price jika tidak ada). Total: nilai total numerik. Change: ekstrak dari 'Kembalian' atau 'Change' (numerik, default 0 jika tidak ada).

        Format JSON yang diharapkan (hanya output JSON, tanpa teks tambahan):
        {
            "vendor": "Nama Toko",
            "date": "YYYY-MM-DD",
            "items": [
                { "name": "Item Name", "qty": 1, "price": 36000, "subtotal": 36000 }
            ],
            "total": 70000,
            "change": 0
        }

        Contoh teks struk:
        Karis Jaya Shop
        Jl. Diponegoro 1, Sby
        Telp 031-12345678
        No.03 2023-08-04 08:36
        1 Lg. Apple Rp36.000
        1.5 Kg Apel Rp7.000
        1 Bf. Sosis Bakar Rp27.000
        Total Rp70.000
        Bayar (Cash) Rp70.000
        Kembalian Rp0

        JSON contoh:
        {
            "vendor": "Karis Jaya Shop",
            "date": "2023-08-04",
            "items": [
                { "name": "Lg. Apple", "qty": 1, "price": 36000, "subtotal": 36000 },
                { "name": "Kg Apel", "qty": 1.5, "price": 7000, "subtotal": 10500 },
                { "name": "Bf. Sosis Bakar", "qty": 1, "price": 27000, "subtotal": 27000 }
            ],
            "total": 70000,
            "change": 0
        }

        Teks struk Anda:
        $text

        Output hanya JSON yang sesuai.
PROMPT;

        $response = Http::withToken($apiKey)
            ->post('https://api.cohere.ai/v1/chat', [
                'model' => 'command-light',
                'message' => $prompt,
                'max_tokens' => 1000,
                'temperature' => 0.1,
            ]);

        if (!$response->successful()) {
            Log::error('Cohere API request failed: ' . $response->body());
        } else {
            $raw = $response->json('messages.0.text') ?? '';
            Log::info('Raw AI response: ' . $raw);

            $onlyJson = $this->cleanCohereResponse($raw);

            if ($onlyJson) {
                Log::info('Using AI parsed data');
                return $onlyJson;
            }
        }

        Log::info('AI parsing failed, using fallback parser');
        return $this->parseWithFallback($text);
    }

    private function parseWithFallback(string $text): array
    {
        $lines = explode("\n", $text);
        $lines = array_filter(array_map('trim', $lines));

        $vendor = $lines[0] ?? 'Unknown Vendor';
        $date = null;
        $items = [];
        $total = 0;
        $change = 0;

        // Extract date (YYYY-MM-DD)
        foreach ($lines as $line) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', $line, $matches)) {
                $date = $matches[1];
                break;
            }
        }

        // Extract items (numbered lines with qty x price)
        $itemPattern = '/(\d+(?:\.\d+)?)\s*(.+?)\s*(?:x|\*)\s*(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/i';
        foreach ($lines as $line) {
            if (preg_match($itemPattern, $line, $matches)) {
                $qty = (float) str_replace(',', '', $matches[1]);
                $name = trim($matches[2]);
                $priceStr = str_replace(['Rp', ',', '.'], '', $matches[3]);
                $price = (float) $priceStr;
                $subtotal = $qty * $price;
                $items[] = [
                    'name' => $name,
                    'qty' => $qty,
                    'price' => $price,
                    'subtotal' => $subtotal
                ];
            }
        }

        // Extract total (Total Rp \d+)
        foreach ($lines as $line) {
            if (stripos($line, 'total') !== false && preg_match('/(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/', $line, $matches)) {
                $total = (float) str_replace(['Rp', ',', '.'], '', $matches[1]);
                break;
            }
        }

        // Extract change (Kembalian Rp \d+ or Kembali Rp \d+)
        foreach ($lines as $line) {
            if ((stripos($line, 'kembalian') !== false || stripos($line, 'kembali') !== false) && preg_match('/(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/', $line, $matches)) {
                $change = (float) str_replace(['Rp', ',', '.'], '', $matches[1]);
                break;
            }
        }

        $result = [
            'vendor' => $vendor,
            'date' => $date,
            'items' => $items,
            'total' => $total,
            'change' => $change
        ];

        Log::info('Fallback parsed data: ' . json_encode($result));

        return $result;
    }
}
