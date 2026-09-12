<?php

namespace App\Services;

use App\Models\Category;
use App\Services\Parsing\RegexItemParser;
use App\Services\Parsing\TotalReconciler;
use App\Services\Parsing\VendorDetector;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Parser teks OCR struk menjadi data terstruktur (vendor, tanggal, kategori,
 * item, total, kembalian): jalur utama AI Cohere dengan fallback regex otomatis
 * bila API gagal/tak tersedia; usedFallback menandai jalur yang terpakai.
 *
 * Orkestrator tipis (facade): mesin regex item (RegexItemParser), heuristik
 * vendor (VendorDetector), dan rekonsiliasi/validasi total (TotalReconciler)
 * sudah diekstrak ke App\Services\Parsing — refactor struktural tanpa
 * perubahan perilaku. API publik (parseWithAI(), usedFallback) tidak berubah.
 */
class AIParserService
{
    private Helper $helper;

    private RegexItemParser $regexItemParser;

    private VendorDetector $vendorDetector;

    private TotalReconciler $totalReconciler;

    /**
     * Jalur parsing terakhir: true = fallback regex, false = AI (Cohere).
     * Dipakai AIParserJob untuk log diagnosa per expense.
     */
    public bool $usedFallback = false;

    public function __construct(?Helper $helper = null)
    {
        $this->helper = $helper ?? new Helper;
        $this->regexItemParser = new RegexItemParser($this->helper);
        $this->vendorDetector = new VendorDetector;
        $this->totalReconciler = new TotalReconciler;
    }

    public function parseWithAI(string $text): ?array
    {
        $this->usedFallback = false;

        Log::info('Raw OCR text for parsing: '.$text);

        $apiKey = config('services.cohere.api_key');

        $prompt = <<<PROMPT
        Analisis teks struk belanja Indonesia ini dan ekstrak informasi ke format JSON yang tepat. Hapus semua mata uang 'Rp', titik, dan koma untuk membuat nilai numerik murni. Tanggal dalam format YYYY-MM-DD. Vendor adalah nama toko di bagian atas. Items: ekstrak qty (angka), name (deskripsi), price (numerik), subtotal (qty * price jika tidak ada).
        Total: nilai total numerik. Jika ada beberapa baris yang terlihat seperti total (misal 'Jumlah', 'Subtotal', 'TOTAL BAYAR', 'Grand Total'), SELALU pilih nilai FINAL/TERAKHIR yang sudah memperhitungkan diskon/pajak/biaya tambahan - biasanya baris paling bawah sebelum info pembayaran (tunai/DP/kembalian). JANGAN pilih subtotal awal sebelum potongan.
        Change: nilai kembalian tunai. Field 'change' HANYA diisi jika teks OCR secara eksplisit mengandung kata 'Kembali', 'Kembalian', atau 'Change'. JANGAN mengisi change dari nilai DP, Uang Muka, Bayar, Sisa Bayar, atau nilai lain yang bukan kembalian tunai. Jika tidak ada baris kembalian, set change = 0.
        Category: berikan satu kategori singkat dalam Bahasa Indonesia untuk belanja ini berdasarkan nama vendor/item (misal 'Makanan & Minuman', 'Transportasi', 'Belanja Rumah Tangga', 'Kesehatan', 'Hiburan', atau 'Lainnya' bila tidak jelas).

        Format JSON yang diharapkan (hanya output JSON, tanpa teks tambahan).

        ATURAN OUTPUT YANG KETAT:
        - JANGAN gunakan markdown code fence (jangan bungkus JSON dengan ```).
        - JANGAN tambahkan kalimat pembuka atau penutup apa pun (mis. "Berikut
          hasilnya:", "Semoga membantu").
        - Mulai output LANGSUNG dengan karakter { dan akhiri dengan }.
        {
            "vendor": "Nama Toko",
            "date": "YYYY-MM-DD",
            "category": "Makanan & Minuman",
            "items": [
                { "name": "Item Name", "qty": 1, "price": 36000, "subtotal": 36000 }
            ],
            "total": 70000,
            "change": 0
        }

        Contoh teks struk — format 2-baris: nama item bernomor urut di satu
        baris, lalu qty/harganya di BARIS BERIKUTNYA. Keduanya adalah SATU item
        (nama = teks setelah nomor urut; qty & harga dari baris detailnya):
        Karis Jaya Shop
        Jl. Dr. Ir. H. Soekarno No.19, Medokan Semampir
        Surabaya
        No. Telp 0812345678
        2023-08-02 08:46:36
        1. Indomie Goreng
        1 lusin x 36,000
        2. Fruit Tea Apple
        1 500 ml x 7,000 Rp 7.000
        3. Belfood Sosis Bakar
        1 x 27,000 Rp 27.000
        Total QTY : 14
        Total Rp 70.000
        Bayar (Cash) Rp 70.000
        Kembali Rp 0

        JSON contoh:
        {
            "vendor": "Karis Jaya Shop",
            "date": "2023-08-02",
            "category": "Makanan & Minuman",
            "items": [
                { "name": "Indomie Goreng", "qty": 1, "price": 36000, "subtotal": 36000 },
                { "name": "Fruit Tea Apple", "qty": 1, "price": 7000, "subtotal": 7000 },
                { "name": "Belfood Sosis Bakar", "qty": 1, "price": 27000, "subtotal": 27000 }
            ],
            "total": 70000,
            "change": 0
        }

        Teks struk Anda:
        $text

        Output hanya JSON yang sesuai.
PROMPT;

        $model = config('services.cohere.model', 'command-r7b-12-2024');

        // Retry TERBATAS: hanya error sementara (transient) yang diulang -
        // HTTP 5xx / 429, timeout, atau koneksi putus. 4xx lain (mis. 400 key
        // salah / 401) dan respons yang JSON-nya tidak valid TIDAK di-retry
        // supaya tidak menguras kuota untuk kesalahan yang pasti terulang.
        $shouldRetry = function (\Throwable $exception): bool {
            if ($exception instanceof RequestException) {
                $status = $exception->response?->status() ?? 0;

                // 429 = rate limit (sementara), 5xx = server error (sementara).
                return $status === 429 || $status >= 500;
            }

            // cURL 7/28 (koneksi gagal / timeout) dilempar sebagai ConnectException.
            return $exception instanceof ConnectException
                || $exception instanceof ConnectionException;
        };

        try {

        $response = Http::withToken($apiKey)
            // Timeout eksplisit: prompt struk bisa panjang, default Guzzle (30s)
            // sering kepotong di tengah parsing sehingga request "gagal" padahal
            // server masih bekerja. 60s menunggu respons, 5s untuk koneksi.
            ->connectTimeout(5)
            ->timeout(60)
            // Maksimal 2x percobaan ulang (total <= 3 request) berjarak 500ms,
            // hanya bila $shouldRetry bernilai true. Argumen terakhir (false)
            // membuat respons 5xx/429 yang masih gagal setelah retry dikembalikan
            // sebagai respons biasa (bukan exception) agar alur sukses -> fallback
            // tidak terganggu.
            ->retry(2, 500, $shouldRetry, false)
            ->post('https://api.cohere.ai/v1/chat', [
                // Model 'command-light' sudah dihapus Cohere (panggilan selalu gagal
                // → fallback regex); pakai model yang masih tersedia via COHERE_MODEL,
                // mis. "command-r7b-12-2024" (default, lebih ringan/cepat) /
                // "command-a-03-2025" ("command-r" polos sudah tidak terdaftar).
                'model' => $model,
                'message' => $prompt,
                'max_tokens' => 1000,
                'temperature' => 0.1,
            ]);

        if (! $response->successful()) {
            Log::error('Cohere API request gagal (model='.$model.', status='.$response->status().'): '.$response->body());
        } else {
            // FIX ROOT CAUSE: endpoint yang dipanggil adalah v1 (/v1/chat) yang
            // meletakkan hasil di key top-level "text". Kode lama membaca
            // "messages.0.text" (format v2) sehingga SELALU kosong -> AI yang
            // sebenarnya sukses tetap dianggap gagal dan jatuh ke fallback regex.
            // Baca "text" dulu (v1 asli), lalu "message.content.0.text" sebagai
            // pengaman jika suatu saat aplikasi dipindah ke v2/chat.
            $raw = $response->json('text') ?? $response->json('message.content.0.text') ?? '';

            // Bukti diagnosis: kalau $raw masih kosong, log FULL response body
            // (bukan hanya hasil ekstraksi) supaya kegagalan di masa depan bisa
            // dianalisis tanpa menebak-nebak format aktual dari Cohere.
            if ($raw === '') {
                Log::warning(
                    'Cohere raw body kosong setelah ekstraksi pesan - full body dilampirkan untuk diagnosis',
                    ['body' => $response->body()]
                );
            }
            Log::info('Raw AI response (model='.$model.', status='.$response->status().'): '.$raw);

            $onlyJson = $this->helper->cleanCohereResponse($raw);

            if ($onlyJson) {
                Log::info('Parsing path: AI (Cohere) — respons JSON valid dipakai.');

                return $onlyJson;
            }

            Log::warning('Parsing path: AI (Cohere) tidak menghasilkan JSON valid — lanjut ke Fallback Regex.');
        }
        } catch (\Throwable $e) {
            // AGENTS.md: fitur OCR/AI WAJIB punya fallback bila API eksternal
            // (Cohere) gagal/tidak tersedia. Timeout/koneksi error di sini
            // sebelumnya lolos keluar dan melewatkan regex fallback, sehingga
            // expense bisa tersimpan dengan total korup & tanpa item.
            Log::warning('Cohere API request melempar exception — lanjut ke Fallback Regex: '.$e->getMessage());
        }



        $this->usedFallback = true;
        Log::info('Parsing path: FALLBACK REGEX — Cohere tidak dipakai (key kosong/request gagal/JSON tidak valid).');

        return $this->parseWithFallback($text);
    }

    private function parseWithFallback(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
        $helper = $this->helper;

        // Vendor: cari nama toko yang paling meyakinkan di baris-baris awal.
        // Baris pertama TIDAK otomatis dipakai — OCR sering memotong awalan
        // (mis. "CV." terbaca "ev,") atau baris pertama berisi alamat/NPWP.
        $vendor = $this->vendorDetector->detect($lines);
        $date = null;

        $items = [];
        $change = 0;

        // Ambil tanggal: telusuri tiap baris untuk substring tanggal lalu
        // normalisasi ke YYYY-MM-DD (struk bisa memuat "Terima: 19-11-2024").
        foreach ($lines as $line) {
            // Tanggal + waktu MENEMPEL pada 1 baris (mis. struk Toko Abang:
            // "No. Struk : 211 10.01.2023-10:11:07" di baris yang sama). Pola
            // DD.MM.YYYY-HH:MM:SS / DD.MM.YYYY HH:MM ditangani lebih dulu agar
            // tanggal valid tidak tertelan pola tanggal sederhana di bawah.
            if (preg_match('/\b(\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4})(?:[-\sT]\d{1,2}:\d{2}(?::\d{2})?)?\b/', $line, $m)) {
                $date = $helper->parseFlexibleDate($m[1]);
            } elseif (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $line, $m)) {
                $date = $helper->parseFlexibleDate($m[1]);
            } elseif (preg_match('/\b(\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4})\b/', $line, $m)) {
                $date = $helper->parseFlexibleDate($m[1]);
            } elseif (preg_match('/\b(\d{1,2}[-\/. ](?:jan|feb|mar|apr|mei|jun|jul|agu|agt|aug|sep|okt|oct|nov|des|dec)[a-z]{0,6}[-\/. ]\d{2,4})\b/iu', $line, $m)) {
                // BUG 5: format DD-Mon-YYYY ("05-Jan-2021", "11 Apr 2012") —
                // sebelumnya hanya format angka yang didukung, sehingga struk
                // tagihan (PLN/bank) selalu tersimpan tanpa tanggal walau
                // tanggalnya tercetak jelas.
                $date = $helper->parseFlexibleDate($m[1]);
            }

            if ($date) {
                break;
            }
        }

        // Parsing item: delegasi ke RegexItemParser (mesin regex fallback —
        // matcher item, skip baris non-item, dan deteksi scaled-qty per-struk;
        // komentar & kata kunci skip ikut pindah ke class tersebut).
        $items = $this->regexItemParser->parseItems($lines);

        // State lintas fallback+matcher item: flag scaled-qty hasil deteksi
        // RegexItemParser untuk struk ini, dipakai keputusan total & rekonsiliasi.
        $scaledQtyStruk = $this->regexItemParser->isScaledQtyStruk();

        // Total: ambil nominal paling meyakinkan (Total Belanja > baris total lain
        // > jumlah item > angka terbesar). Pada struk scaled-qty (Toko Abang),
        // baris "Subtotal"/"Bayar" OCR terbukti korup — total WAJIB dari
        // penjumlahan subtotal item.
        if ($scaledQtyStruk) {
            $sumItems = 0.0;
            foreach ($items as $item) {
                $sumItems += (float) ($item['subtotal'] ?? 0);
            }
            $total = $sumItems > 0 ? round($sumItems, 2) : $helper->extractBestTotal($lines, $items);
        } else {
            $total = $helper->extractBestTotal($lines, $items);
        }

        // BUG 4 (digit nyelip): validasi silang nominal item terhadap total
        // struk — koreksi nominal yang kemungkinan salah gabung dua angka
        // berdekatan saat OCR (mis. "Rp 1.600" terbaca "Rp 11600").
        $items = $this->totalReconciler->reconcileItemsAgainstTotal($items, $total, $scaledQtyStruk);

        foreach ($items as &$parsedItem) {
            unset($parsedItem['single_price']);
        }
        unset($parsedItem);

        // BUG residual (digit nyelip di baris TOTAL, bukan di item): total
        // tercetak divalidasi silang terhadap penjumlahan item yang SUDAH
        // lewat reconcileItemsAgainstTotal().
        $total = $this->totalReconciler->validateTotalAgainstItems($items, $total, $scaledQtyStruk);

        // Kembalian: cari baris berisi "kembali".
        foreach ($lines as $line) {
            if (mb_strpos(mb_strtolower($line), 'kembal') !== false) {
                $change = $helper->extractLargestNumber($line);
                if ($change > 0) {
                    break;
                }
            }
        }

        $itemNames = implode(' ', array_map(fn (array $i): string => $i['name'] ?? '', $items));

        $result = [
            'vendor' => $vendor,
            'date' => $date,
            'items' => $items,
            'total' => $total,
            'change' => $change,
            // Tebak kategori berdasarkan nama vendor + item (fallback saat AI gagal).
            'category' => Category::inferCategoryName($vendor.'. '.$itemNames),
        ];

        Log::info('Fallback parsed data: '.json_encode($result));

        return $result;
    }
}
