<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Parser teks OCR struk menjadi data terstruktur (vendor, tanggal, kategori,
 * item, total, kembalian).
 *
 * Jalur utama memakai AI Cohere; bila API tidak tersedia/gagal, otomatis jatuh
 * ke parser regex bawaan (parseWithFallback) agar hasil tetap tersimpan. Flag
 * usedFallback memberi tahu pemanggil jalur mana yang terpakai.
 */
class AIParserService
{
    private Helper $helper;

    /**
     * Menandai jalur parsing yang benar-benar dipakai pada pemanggilan
     * terakhir: true = fallback regex, false = AI (Cohere). Dipakai AIParserJob
     * untuk menulis log yang jelas per expense, agar masalah parsing mudah
     * didiagnosis tanpa harus berulang kali bertanya ke developer.
     */
    public bool $usedFallback = false;

    /**
     * Batas atas angka yang masih masuk akal sebagai nominal rupiah pada satu
     * baris item struk (< Rp 1 miliar).
     *
     * Token angka OCR yang melebihi batas ini hampir pasti BUKAN nominal,
     * melainkan nomor identitas struk yang salah tertangkap regex — contoh
     * nyata (investigasi 2026-09-03, expense id 16-20):
     *  - "IDPEL : 211011471074"  → 211 miliar (nomor pelanggan PLN)
     *  - "O81253112427"          → 81 miliar (no. HP)
     *  - "SMS/WA: 061110640888"  → 61 miliar (no. WA)
     *  - kode referensi 30 digit → 2.1e+27
     * Baris-baris itu membuat INSERT expense_items gagal SQLSTATE[22003]
     * "numeric field overflow" sehingga hasil parsing tidak pernah tersimpan.
     * Baris dengan harga di atas batas ini ditolak sebagai item.
     */
    private const MAX_PLAUSIBLE_MONEY = 999999999.0;

    /**
     * True bila struk ini memakai format "qty ber-skala ribuan" pada baris
     * detail "qty satuan x harga" (ciri struk grosir/sembako kecil seperti
     * Toko Abang: "4.000 Kg X ..." harus dibaca qty = 4, "1600 Kg x ..." = 1.6,
     * "800 Box X ..." = 0.8). Deteksi dilakukan PER-STRUK (bukan hardcode), agar
     * struk nominal biasa (Indomaret, Karis Jaya) tetap memakai hitungan
     * standard qty dan pemisah ribuan biasa.
     */
    private bool $scaledQtyStruk = false;

    public function __construct(?Helper $helper = null)
    {
        $this->helper = $helper ?? new Helper;
    }

    public function parseWithAI(string $text): ?array
    {
        $this->usedFallback = false;

        Log::info('Raw OCR text for parsing: '.$text);

        $apiKey = config('services.cohere.api_key');

        $prompt = <<<PROMPT
        Analisis teks struk belanja Indonesia ini dan ekstrak informasi ke format JSON yang tepat. Hapus semua mata uang 'Rp', titik, dan koma untuk membuat nilai numerik murni. Tanggal dalam format YYYY-MM-DD. Vendor adalah nama toko di bagian atas. Items: ekstrak qty (angka), name (deskripsi), price (numerik), subtotal (qty * price jika tidak ada). Total: nilai total numerik. Change: total dari 'Kembalian' atau 'Change' (numerik, default 0 jika tidak ada). Category: berikan satu kategori singkat dalam Bahasa Indonesia untuk belanja ini berdasarkan nama vendor/item (misal 'Makanan & Minuman', 'Transportasi', 'Belanja Rumah Tangga', 'Kesehatan', 'Hiburan', atau 'Lainnya' bila tidak jelas).

        Format JSON yang diharapkan (hanya output JSON, tanpa teks tambahan):
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

        $model = config('services.cohere.model', 'command-r-08-2024');

        try {

        $response = Http::withToken($apiKey)
            ->post('https://api.cohere.ai/v1/chat', [
                // Model 'command-light' sudah dihapus oleh Cohere sejak
                // 15 September 2025 (lihat storage/logs/laravel.log), sehingga
                // tiap panggilan AI gagal & selalu jatuh ke fallback regex.
                // Pakai model yang masih tersedia & configurable lewat COHERE_MODEL.
                // Catatan: nama model "command-r" (tanpa tanggal) saat ini tidak
                // lagi terdaftar; gunakan "command-r-08-2024" atau
                // "command-a-03-2025".
                'model' => $model,
                'message' => $prompt,
                'max_tokens' => 1000,
                'temperature' => 0.1,
            ]);

        if (! $response->successful()) {
            Log::error('Cohere API request gagal (model='.$model.', status='.$response->status().'): '.$response->body());
        } else {
            $raw = $response->json('messages.0.text') ?? '';
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

        // Deteksi jenis normalisasi qty per-struk SEBELUM memproses item.
        // Struk tipe "qty ber-skala ribuan" (mis. Toko Abang) memakai titik
        // sebagai akhiran ribuan semu pada kolom qty ("4.000 Kg" = 4 kg);
        // struk nominal uang biasa (Indomaret, Karis Jaya) memakai koma/titik
        // sebagai pemisah ribuan sungguhan pada nilai uang.
        $this->scaledQtyStruk = $this->detectScaledQtyStruk($lines);

        // Vendor: cari nama toko yang paling meyakinkan di baris-baris awal.
        // Baris pertama TIDAK otomatis dipakai — OCR sering memotong awalan
        // (mis. "CV." terbaca "ev,") atau baris pertama berisi alamat/NPWP.
        $vendor = $this->extractVendor($lines);
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

        // Parsing item didelegasikan ke matchItemLine() — mendukung banyak gaya
        // struk Indonesia (qty x harga, kolom lengkap/tanpa subtotal, barcode di
        // awal baris, harga "@", serta format 2-baris bernomor urut "N. Nama"
        // + "qty satuan x harga" pada baris berikutnya). Baris yang jelas BUKAN
        // item tetap di-skip agar tidak mematahkan hasil (total, disc,
        // kembalian, pajak, header kolom, retur negatif, baris identifikasi
        // NPWP/telp).
        //
        // Kata kunci baris kontrol struk. Termasuk varian OCR yang sering salah
        // terbaca (mis. "TUNAE" utk Tunai, "KEMBALE" utk Kembalian, "ANDA HEMAT"
        // = Anda Hemat, "DPP", "POIN"), agar baris-baris itu tidak terambil
        // sebagai item produk.
        $skipKeywords = ['total', 'disc', 'diskon', 'retur', 'kembal', 'tunai', 'tunae',
            'tunal', 'kas', 'bayar', 'ppn', 'pajak', 'dpp', 'item', 'qty', 'uang', 'harga',
            'jumlah', 'subtotal', 'anda', 'hemat', 'jual', 'stamp', 'lucky',
            'apps', 'tanggal', 'tgl', 'terima', 'waktu',
            // Baris identitas struk PLN/telekomunikasi yang angkanya BUKAN
            // nominal (IDPEL, stand meter, no. referensi/struk, no. WA/SMS,
            // tarif/daya). Tanpa ini barisnya lolos ke matcher item dan angkanya
            // menjadi harga item palsu — bisa ratusan miliar (overflow kolom
            // expense_items, SQLSTATE[22003]) atau minimal data sampah.
            'idpel', 'stand meter', 'bl/th', 'tarif', 'daya',
            'no. struk', 'no struk', 'no. rest', 'no rest',
            'no. ref', 'no ref', 'sms/wa', 'sms wa'];

        $lineCount = count($lines);

        // Footer struk biasanya diawali "Terima kasih" / "Thank you". SEMUA
        // baris setelahnya (watermark/logo OCR seperti "Pass Wit 173458",
        // "pawoonpos", tautan e-receipt, dsb.) bukan item belanja — tanpa ini
        // watermark ber-nomor ikut ter-parse jadi item dengan harga ngarang.
        $sawThanks = false;

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            $lower = mb_strtolower($line);

            if (! $sawThanks && preg_match('/terima\s*kasih|terimakasih|thank\s*you/iu', $lower) === 1) {
                $sawThanks = true;
            }

            if ($sawThanks) {
                continue;
            }

            // BUG 2: baris JAM/WAKTU ("13:56:30", "10:14:01 WIB") bukan item —
            // dulu lolos dan tersimpan sebagai item dengan harga ngarang.
            if (preg_match('/\b\d{1,2}:\d{2}(?::\d{2})?(?:\s*(?:wib|wita|wit))?\b/iu', $line) === 1) {
                continue;
            }

            // BUG 2: baris nomor referensi/kode transaksi ("WSI REF 1 CCAE...",
            // "NO REF", "OMAS2105095132000000000787258383", EAN panjang) bukan
            // item. Cirinya: ada token alfanumerik >= 12 karakter yang memuat
            // digit, atau baris diawali kode >= 5 digit tanpa nama produk.
            if (preg_match('/\b(?=[a-z0-9]*\d)[a-z0-9]{12,}\b/iu', $line) === 1
                || (preg_match('/^\d{5,}[.,]?\s+\S/u', $line) === 1
                    && preg_match('/[a-zA-Z]{3,}/u', $line) !== 1)) {
                continue;
            }

            // Lewati baris kontrol (total, diskon, kembalian, pajak, header kolom).
            if ($this->lineHasAnyKeyword($lower, $skipKeywords)) {
                continue;
            }

            // "POIN" (poin loyalti) HANYA sebagai kata utuh — pencocokan
            // substring membuang nama produk "Pointer" (expense id 17) karena
            // memuat "poin" di awalnya.
            if (preg_match('/\bpoin\b/iu', $lower) === 1) {
                continue;
            }

            // Lewati baris identifikasi (tanggal, NPWP/NPUP, telepon) maupun
            // baris alamat/administrasi (KEC/KAB/KEL/RT/RW/JL/DESA) — bukan item.
            if (preg_match('/(npwp|npup|telp|telepon|\bno\.?\s*\d)/i', $lower) === 1
                || preg_match('/^(kec|kab|kel|ds|desa|dusun|rt|rw|jl|jln|jalan|kota|blok|gang|perum|komplek|alamat)\b/i', $lower) === 1) {
                continue;
            }

            // Lewati baris retur/diskon bervalue negatif (mis. "-1.500" / "(200)")
            // agar tidak terambil sebagai item positif yang salah. Pola dibuat
            // sempit (minus di awal/awal-kata, atau angka dalam kurung TERTUTUP
            // penuh) sehingga:
            //  - dash antar-angka pada kode produk (mis. "1-300 308") tidak ikut
            //    dianggap baris negatif;
            //  - kurung yang TIDAK tertutup akibat artefak OCR di depan nominal
            //    POSITIF (mis. "ROMA WFR CHO BLS97.6 9700 (9,700" — subtotal
            //    dalam kurung yang terbaca sebagian) tidak ikut dibuang.
            //    Hanya kurung-minus ("(-2.500") yang TETAP dilewati karena itu
            //    ciri kuat nilai retur walau kurung tutup ikut terkikis OCR.
            if (preg_match('/(?:^|\s)-\d|\(-\d|\([0-9][0-9.,]*\)/', $line) === 1) {
                continue;
            }

            // Struk format 2-baris (mis. struk "Karis Jaya Shop"): nama produk
            // ada di baris bernomor urut, sementara qty/harganya di BARIS
            // BERIKUTNYA:
            //
            //     1. Indomie Goreng
            //        1 lusin x 36,000              Rp 36.000
            //
            // Keduanya adalah SATU kesatuan item — nama diambil dari teks
            // setelah nomor urut, qty/harga dari baris detail di bawahnya.
            // Tanpa ini, baris detail salah tertangkap sebagai item ber-nama
            // "1 lusin x" dan nama produk aslinya justru hilang.
            // "(?!\d)" setelah tanda titik/kurung mencegah harga ber-ribuan
            // salah terbaca sebagai nomor urut — "225.000 x1 225.000" bukan
            // "item nomor 225." (expense id 17); hanya "1. Nama" yang cocok.
            if (preg_match('/^\d{1,3}\s*[.)]\s*(?!\d)(\S.*)$/u', $line, $seqMatch) === 1) {
                $item = $this->tryMatchTwoLineItem(
                    name: $seqMatch[1],
                    detailLine: $lines[$i + 1] ?? null,
                );

                if ($item !== null) {
                    $items[] = $item;
                    $i++; // Baris detail sudah habis dipakai — jangan diproses lagi.

                    continue;
                }

                // Bukan pasangan 2-baris (baris berikutnya bukan qty/harga):
                // buang nomor urutnya agar nama item bersih, lalu proses baris
                // ini seperti format 1-baris biasa — tidak mengubah perilaku
                // parsing struk 1-baris yang sudah benar.
                $line = trim($seqMatch[1]);
            }

            $item = $this->matchItemLine($line);

            // Struk format 2-baris TANPA nomor urut (mis. struk "Toko Abang"):
            // baris nama produk ("Beras") diikuti baris DETAIL "qty satuan x
            // harga" ("4.000 Kg X 12.500 Rp. 50.000.000") pada baris berikutnya.
            // Keduanya SATU item. Baris nama yang sudah lengkap (baris item
            // 1-baris) tidak akan dipasangkan lagi.
            if ($item === null
                && isset($lines[$i + 1])
                && $this->looksLikeProductName($line)
                && ($detail = $this->extractQtyUnitXPriceParts($lines[$i + 1])) !== null
            ) {
                $item = $this->buildDetailItemFromParts(
                    name: $line,
                    qtyRaw: $detail['qty'],
                    priceRaw: $detail['price'],
                    subtotalRaw: $detail['subtotal'],
                );

                if ($item !== null) {
                    $items[] = $item;
                    $i++; // Baris detail sudah dipakai — jangan diproses lagi.

                    continue;
                }
            }

            if ($item !== null) {
                $items[] = $item;
            }
        }

        // Total: ambil nominal paling meyakinkan (Total Belanja > baris total lain
        // > jumlah item > angka terbesar di teks). Robust terhadap baris retur &
        // diskon karena baris-baris itu tidak ikut dijumlah sebagai item.
        // Khusus struk tipe "qty ber-skala ribuan" (Toko Abang): baris
        // "Subtotal"/"Bayar" pada OCR mentah terbukti korup / tidak konsisten,
        // sehingga total WAJIB dihitung dari penjumlahan subtotal item.
        if ($this->scaledQtyStruk) {
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
        $items = $this->reconcileItemsAgainstTotal($items, $total);

        foreach ($items as &$parsedItem) {
            unset($parsedItem['single_price']);
        }
        unset($parsedItem);

        // BUG residual (digit nyelip di baris TOTAL, bukan di item): total
        // tercetak divalidasi silang terhadap penjumlahan item yang SUDAH
        // lewat reconcileItemsAgainstTotal().
        $total = $this->validateTotalAgainstItems($items, $total);

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

    /**
     * Cocokkan satu baris teks OCR dengan berbagai gaya baris item struk
     * Indonesia. Mengembalikan array item terstruktur bila cocok, atau null
     * bila baris ini bukan produk.
     *
     * Gaya yang didukung:
     *  - "1 x Nama 10.000" / "1*Nama 10.000"
     *  - kolom lengkap "[barcode] Nama qty harga subtotal"
     *  - kolom tanpa subtotal "[barcode] Nama qty harga"
     *  - harga satuan "Nama @10.000 2 20.000"
     *
     * Nama produk wajib mengandung huruf, sehingga baris numerik murni (EAN,
     * "2 5.000 10.000" tanpa nama) tidak salah terambil sebagai item.
     */
    private function matchItemLine(string $line): ?array
    {
        $helper = $this->helper;

        // Hapus prefiks mata uang agar pola angka lebih mudah cocok
        // ("Rp15.000" → "15.000").
        $line = trim((string) preg_replace('/\b(?:Rp\.?|IDR\.?)\s*/i', '', $line));

        if ($line === '') {
            return null;
        }

        // Pass 1: pola standar pada teks apa adanya.
        $item = $this->tryMatchItemPatterns($line);
        if ($item !== null) {
            return $item;
        }

        // Pass 2: OCR sering menyambung qty & harga dengan dash ("1-300"). Coba
        // normalisasi lintas-digit hanya bila Pass 1 gagal, agar baris normal
        // (mis. nama produk yang memang memuat dash) tidak ikut berubah.
        $normalized = (string) preg_replace('/(\d)-(\d)/', '$1 $2', $line);
        if ($normalized !== $line) {
            $item = $this->tryMatchItemPatterns($normalized);
            if ($item !== null) {
                // OCR acap menyambung qty & harga dengan dash ("1-300"). Pada
                // kondisi itu, subtotal tercetak di baris yang sama kadang
                // korup / tidak konsisten dengan qty x harga (mis. "1-300 308"
                // -> qty=1, harga=300 -> subtotal yang benar 300). Untuk qty
                // kecil, hitung ulang subtotal dari qty x harga.
                $qty = (float) ($item['qty'] ?? 1);
                $price = (float) ($item['price'] ?? 0);
                $subtotal = (float) ($item['subtotal'] ?? 0);
                if ($qty <= 5 && $price > 0 && abs($subtotal - round($price * $qty, 2)) > 0.01) {
                    $item['subtotal'] = round($price * $qty, 2);
                }

                return $item;
            }
        }

        // Pass 3: baris "Nama [junk] <harga>" dengan satu nominal di ujung
        // (qty=1). Nominal harus money-like (>=4 digit atau ber-ribuan) agar
        // nomor kecil (rumah/jumlah) tidak terambil sebagai item.
        return $this->tryMatchSinglePriceLine($line);
    }

    /**
     * Pasangkan baris nama produk bernomor urut dengan baris DETAIL di
     * bawahnya — format struk 2-baris (mis. struk "Karis Jaya Shop"):
     *
     *     1. Indomie Goreng
     *        1 lusin x 36,000
     *
     *     2. Fruit Tea Apple
     *        1 500 ml x 7,000              Rp 7.000
     *
     * Baris detail berpola "qty [satuan] x harga [Rp subtotal]"; satuan,
     * kata "Rp"/"IDR", maupun nominal subtotal semuanya opsional. Bila baris
     * detail tidak cocok pola itu, kembalikan null — pemanggil lalu memproses
     * kedua baris secara terpisah seperti format 1-baris biasa.
     */
    private function tryMatchTwoLineItem(string $name, ?string $detailLine): ?array
    {
        if ($detailLine === null) {
            return null;
        }

        $detail = $this->extractQtyUnitXPriceParts(trim($detailLine));

        if ($detail === null) {
            return null;
        }

        return $this->buildDetailItemFromParts(
            name: $name,
            qtyRaw: $detail['qty'],
            priceRaw: $detail['price'],
            subtotalRaw: $detail['subtotal'],
        );
    }

    /**
     * Ekstrak bagian "qty [satuan] x harga [Rp subtotal]" dari sebuah baris.
     * Mengembalikan bagian mentahnya (qty/price/subtotal) untuk diproses
     * buildItemFromParts(), atau null bila baris bukan baris detail itu.
     *
     * Teks SETELAH "x" wajib berupa nominal — bila berupa huruf, baris itu
     * adalah item utuh 1-baris bergaya "1 x Nama Harga" (pola (A)) yang justru
     * tidak boleh ikut di-pairing dengan baris di atasnya.
     */
    private function extractQtyUnitXPriceParts(string $line): ?array
    {
        // BUG 3: struk MR.DIY mencetak KODE PRODUK di baris detail sebelum qty
        // ("9058730 1 X 13,500 13,500"). Tanpa dibuang, angka 7 digit itu
        // menyebar ke kolom qty (regex menelan "9058" dari "9058730"). Kode
        // hanya dibuang bila pola qty-x-harga JADI cocok pada sisanya, sehingga
        // baris lain (mis. "4.000 Kg X 12.500") tidak terpengaruh.
        if (preg_match('/^\d{5,}/', $line) === 1) {
            $stripped = (string) preg_replace('/^\d{5,}[.,]?\s+/', '', $line);
            if ($stripped !== $line
                && preg_match($this->qtyUnitXPricePattern(), $stripped) === 1) {
                $line = $stripped;
            }
        }

        $matched = preg_match(
            $this->qtyUnitXPricePattern(),
            $line,
            $m,
        );

        if ($matched !== 1) {
            return null;
        }

        return [
            'qty' => $m['qty'],
            'price' => $m['price'],
            'subtotal' => $m['subtotal'] ?? null,
        ];
    }

    /**
     * Pola regex baris DETAIL format 2-baris: "qty [satuan] x harga [subtotal]".
     * Dipisah ke method sendiri karena dipakai dua kali: percobaan asli dan
     * percobaan setelah kode produk awalan dibuang (BUG 3, struk MR.DIY).
     */
    private function qtyUnitXPricePattern(): string
    {
        return '/^(?<qty>\d{1,4}(?:[.,]\d+)?)\s*'
            .'(?:(?<unit>[a-zA-Z0-9][a-zA-Z0-9\s.]*?)\s*)?'
            .'[x*×]\s*'
            .'(?:(?:Rp\.?|IDR\.?)\s*)?'
            .'(?<price>\d+(?:[.,]\d+)*)'
            .'(?:\s+(?:(?:Rp\.?|IDR\.?)\s*)?(?<subtotal>\d+(?:[.,]\d+)*))?'
            .'\s*$/iu';
    }

    /**
     * Coba semua pola baris item pada sebuah jalur teks:
     *  - (D) "Nama @harga qty subtotal"
     *  - (B) "[barcode] Nama qty harga subtotal"
     *  - (C) "[barcode] Nama qty harga"
     *  - (E) "[barcode] Nama harga subtotal" (tanpa kolom qty; qty diturunkan
     *        dari subtotal/price bila kelipatan bulat, selain itu 1)
     *  - (A) "qty x Nama Harga" / "qty*Nama Harga"
     */
    private function tryMatchItemPatterns(string $line): ?array
    {
        $helper = $this->helper;
        $qtyTok = '\d{1,3}(?:[.,]\d{1,2})?';
        $numTok = '\d+(?:[.,]\d+)*';

        // (D) "Nama @harga qty subtotal" — paling spesifik, dicoba duluan.
        if (preg_match('/^(.+?)\s+@\s*('.$numTok.')\s+('.$qtyTok.')\s+('.$numTok.')\s*$/iu', $line, $m)) {
            $item = $this->buildItemFromParts(trim($m[1]), $m[3], $m[2], $m[4]);
            if ($item !== null) {
                return $item;
            }
        }

        // (B) kolom lengkap: "[barcode] Nama qty harga subtotal".
        if (preg_match('/^(?:(\d{6,})\s+)?(.+?)\s+('.$qtyTok.')\s+('.$numTok.')\s+('.$numTok.')\s*$/u', $line, $m)) {
            $item = $this->buildItemFromParts(trim($m[2]), $m[3], $m[4], $m[5]);
            if ($item !== null) {
                return $item;
            }
        }

        // (C) kolom tanpa subtotal: "[barcode] Nama qty harga".
        if (preg_match('/^(?:(\d{6,})\s+)?(.+?)\s+('.$qtyTok.')\s+('.$numTok.')\s*$/u', $line, $m)) {
            $item = $this->buildItemFromParts(trim($m[2]), $m[3], $m[4], null);
            if ($item !== null) {
                return $item;
            }
        }

        // (E) kolom tanpa qty: "[barcode] Nama harga subtotal" — sering muncul
        //     pada struk di mana qty tidak dicetak. Tolerir nama yang masih
        //     memuat kode/warna (mis. "COKLAT200,") lewat cleanItemName.
        if (preg_match('/^(?:(\d{6,})\s+)?(.+?)\s+('.$numTok.')\s+('.$numTok.')\s*$/u', $line, $m)) {
            $name = $this->cleanItemName(trim($m[2]));
            if (! $this->looksLikeProductName($name)) {
                return null;
            }

            // Nama yang tersisa hanyalah "qty [satuan] x" berarti baris ini
            // adalah baris DETAIL item format 2-baris yang baris namanya tidak
            // terbaca OCR — bukan nama produk, jangan disimpan sebagai item
            // (mis. "1 lusin x" / "1 500 ml x" / "1 x").
            if (preg_match('/^\d+(?:[.,]\d+)?(?:\s+[a-zA-Z0-9.]+)*\s*[x*×]$/iu', $name) === 1) {
                return null;
            }
            $price = $helper->parseMoneyNumber($m[3]);
            $subtotal = $helper->parseMoneyNumber($m[4]);
            if ($price <= 0) {
                return null;
            }
            // Guard harga tidak masuk akal (nomor identitas tertangkap sebagai
            // harga) — lihat konstanta MAX_PLAUSIBLE_MONEY.
            if ($price > self::MAX_PLAUSIBLE_MONEY) {
                return null;
            }
            if ($subtotal < $price) {
                $subtotal = $price;
            }
            $qty = 1;
            if ($subtotal > $price) {
                $ratio = $subtotal / $price;
                if (abs($ratio - round($ratio)) < 0.01 && round($ratio) <= 999) {
                    $qty = (int) round($ratio);
                }
            }

            return ['name' => $name, 'qty' => $qty, 'price' => $price, 'subtotal' => $subtotal];
        }

        // (A) "1 x Nama Harga" / "1*Nama Harga".
        if (preg_match('/^('.$numTok.')\s*[x*×]\s*(.+?)\s+('.$numTok.')\s*$/iu', $line, $m)) {
            $item = $this->buildItemFromParts(trim($m[2]), $m[1], $m[3], null);
            if ($item !== null) {
                return $item;
            }
        }

        // (F) "Nama xQty Harga" — format POS modern (Pawoon, GoFood, dsb.):
        // "Martabak Original x2 40,000" = 2 @ Rp 20.000, total baris Rp 40.000.
        // Angka setelah "x" adalah QTY (1-3 digit) dan nominal terakhir adalah
        // TOTAL baris, bukan harga satuan.
        if (preg_match('/^(.+?)\s+[x*×]\s*(\d{1,3})\s+('.$numTok.')\s*$/iu', $line, $m)) {
            $item = $this->buildItemFromParts(trim($m[1]), $m[2], $m[3], null);
            if ($item !== null) {
                $qty = (float) $item['qty'];
                $lineTotal = (float) $item['price'];
                if ($qty > 1 && $lineTotal > 0) {
                    $unit = $lineTotal / $qty;
                    if ($unit >= 1 && abs(round($unit) - $unit) < 0.01) {
                        $item['price'] = round($unit, 2);
                    }
                    $item['subtotal'] = round($qty * (float) $item['price'], 2);
                }

                return $item;
            }
        }

        return null;
    }

    /**
     * Baris "Nama <harga>" tanpa qty & subtotal tercetak (qty=1, subtotal=harga).
     * Hanya dipakai sebagai upaya terakhir dan hanya untuk nominal money-like,
     * agar baris address/barcode tidak salah tertangkap.
     */
    private function tryMatchSinglePriceLine(string $line): ?array
    {
        $helper = $this->helper;

        // Money-like: minimal 4 digit (mis. "15500") ATAU ber-ribuan ("15.500").
        $money = '(?:[1-9]\d{3,}|\d{1,3}(?:[.,]\d{3}){1,3})(?:[.,]\d{1,2})?';

        // Ambil nominal money-like TERAKHIR; sisanya (sebelum nominal) jadi nama
        // produk. Ini toleran terhadap kode/berat yang menyambung sebelum harga
        // (mis. "658 _24.=«15,500" → harga 15.500), dan tidak akan memakan digit
        // milik nominal itu sendiri (bedanya dengan pendekatan separator greedy).
        if (! preg_match_all('/'.$money.'/u', $line, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $last = end($matches[0]);
        $token = $last[0];
        $offset = $last[1];

        // Nominal harus jadi terminator baris — selainnya hanya junk/spasi
        // (boleh kosong), bukan ada angka lain di belakangnya (mis. "35372/HASAN/02").
        $tail = substr($line, $offset + strlen($token));
        if (preg_match('/^[\s«»<>=~^|_*.\-]*$/u', $tail) !== 1) {
            return null;
        }

        $name = $this->cleanItemName(trim(substr($line, 0, $offset)));
        if (! $this->looksLikeProductName($name)) {
            return null;
        }

        $price = $helper->parseMoneyNumber($token);
        if ($price <= 0) {
            return null;
        }

        // Guard harga tidak masuk akal. Ini jalur yang paling sering salah
        // menangkap nomor identitas struk sebagai harga ("IDPEL : 211011471074",
        // "O81253112427", "SMS/WA: 061110640888", kode referensi 30 digit) —
        // lihat konstanta MAX_PLAUSIBLE_MONEY.
        if ($price > self::MAX_PLAUSIBLE_MONEY) {
            return null;
        }

        // Flag jalur asal: dipakai reconcileItemsAgainstTotal() untuk menandai
        // item yang TIDAK punya subtotal tercetak sendiri (hanya satu nominal
        // di baris), sehingga jadi kandidat koreksi digit-nyelip (BUG 4).
        return ['name' => $name, 'qty' => 1, 'price' => $price, 'subtotal' => $price, 'single_price' => true];
    }

    /**
     * Bersihkan nama produk dari artefak OCR: koma/titik menjuntai, token di
     * ujung yang tidak mengandung huruf (kode, berat, atau garbage OCR seperti
     * "_24.="), serta normalisasi spasi ganda.
     */
    private function cleanItemName(string $name): string
    {
        $name = trim($name);
        $name = rtrim($name, ".,;:'\"");

        $parts = preg_split('/\s+/u', $name);
        while ($parts && preg_match('/[a-zA-Z\x{00C0}-\x{017F}]/u', end($parts)) === 0) {
            array_pop($parts);
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Susun array item dari bagian-bagian yang cocok; null bila nama bukan
     * produk atau harga tidak valid. Subtotal boleh kosong (dihitung qty*harga).
     */
    private function buildItemFromParts(string $name, string $qtyRaw, string $priceRaw, ?string $subtotalRaw): ?array
    {
        $helper = $this->helper;

        $name = $this->cleanItemName($name);
        if (! $this->looksLikeProductName($name)) {
            return null;
        }

        $qty = max(0.0, $helper->parseMoneyNumber($qtyRaw));
        $price = max(0.0, $helper->parseMoneyNumber($priceRaw));

        if ($price <= 0) {
            return null;
        }

        // Guard harga tidak masuk akal (nomor identitas tertangkap sebagai
        // harga) — lihat konstanta MAX_PLAUSIBLE_MONEY.
        if ($price > self::MAX_PLAUSIBLE_MONEY) {
            return null;
        }

        $subtotal = $subtotalRaw !== null ? $helper->parseMoneyNumber($subtotalRaw) : 0.0;
        if ($subtotal <= 0) {
            $subtotal = round($price * ($qty > 0 ? $qty : 1), 2);
        }

        // Koreksi misparse khas OCR struk: angka pada kolom QTY sesungguhnya
        // bagian dari NAMA produk (satuan gram/kemasan) yang menempel tanpa
        // spasi — mis. "MLKITA CNOY BTES 24G" terbaca "MLKITA CNOY BTES 246",
        // sehingga token "24G/246" sempat dianggap qty. Ciri-cirinya: qty
        // terekstrak > 1, namun subtotal tercetak = harga satuan (bukan
        // qty * harga) => qty yang benar adalah 1 dan angka tsb dikembalikan
        // ke nama produk.
        if ($qty > 1 && $price > 0 && abs($subtotal - $price) <= 1.0
            && abs($subtotal - $price * $qty) > 1.0
        ) {
            $name = trim($name.' '.trim($qtyRaw));
            $qty = 1;
            $subtotal = $price;
        }

        return [
            'name' => $name,
            'qty' => $qty > 0 ? $qty : 1,
            'price' => $price,
            'subtotal' => $subtotal,
        ];
    }

    /**
     * Nama produk wajib mengandung huruf, supaya baris numerik murni tidak
     * terambil sebagai item.
     */
    /**
     * Susun item dari baris DETAIL format 2-baris "qty [satuan] x harga".
     *
     * Beda dengan buildItemFromParts: untuk struk tipe "qty ber-skala ribuan"
     * (Toko Abang) qty dibagi 1000 ("4.000 Kg" -> 4, "1600 Kg" -> 1.6, "800 Box"
     * -> 0.8) dan subtotal DIHITUNG ULANG dari qty x harga — subtotal yang
     * tercetak pada OCR mentah terbukti korup/tidak konsisten (bandingkan
     * "22,400,000" vs "7,800,000" yang formatnya tidak seragam).
     */
    private function buildDetailItemFromParts(string $name, string $qtyRaw, string $priceRaw, ?string $subtotalRaw): ?array
    {
        $helper = $this->helper;

        $name = $this->cleanItemName($name);
        if (! $this->looksLikeProductName($name)) {
            return null;
        }

        $qty = max(0.0, $helper->parseMoneyNumber($qtyRaw));
        $price = max(0.0, $helper->parseMoneyNumber($priceRaw));

        if ($price <= 0) {
            return null;
        }

        // Guard harga tidak masuk akal (nomor identitas tertangkap sebagai
        // harga) — lihat konstanta MAX_PLAUSIBLE_MONEY.
        if ($price > self::MAX_PLAUSIBLE_MONEY) {
            return null;
        }

        // BUG 3 lanjutan: sebagian struk menulis "HARGA xQTY SUBTOTAL" (bukan
        // "QTY x HARGA") — mis. "500.000 x1 500.000" = Rp 500.000 x 1. Tanpa
        // koreksi ini terbaca qty=500.000 & harga=1. Cirinya: qty bernilai
        // besar (>= 100), harga kecil 1-9, dan qty x harga pas dengan subtotal
        // tercetak — maka tukar posisinya. (Tidak berlaku pada struk scaled-qty
        // seperti Toko Abang yang memakai harga satuan besar juga.)
        if (! $this->scaledQtyStruk && $subtotalRaw !== null) {
            $subtotalVal = $helper->parseMoneyNumber($subtotalRaw);
            $looksReversed = $qty >= 100
                && $price >= 1
                && $price <= 9
                && abs($qty * $price - $subtotalVal) <= 1.0;

            if ($looksReversed) {
                [$qty, $price] = [$price, $qty];
            }
        }

        if ($this->scaledQtyStruk) {
            $qty = $qty / 1000;
            $subtotal = round($qty * $price, 2);
        } else {
            $subtotal = $subtotalRaw !== null ? $helper->parseMoneyNumber($subtotalRaw) : 0.0;
            if ($subtotal <= 0) {
                $subtotal = round($price * ($qty > 0 ? $qty : 1), 2);
            }
        }

        return [
            'name' => $name,
            'qty' => $qty > 0 ? $qty : 1,
            'price' => $price,
            'subtotal' => $subtotal,
        ];
    }

    /**
     * Deteksi per-struk apakah kolom qty memakai "skala ribuan semu" — cirinya
     * ada baris detail berpola "qty berakhiran ribuan (mis. 4.000) [satuan] x
     * harga" (struk Toko Abang). Struk nominal biasa (Indomaret memakai koma
     * ribuan, Karis Jaya qty kecil tanpa titik) tidak terdeteksi.
     */
    private function detectScaledQtyStruk(array $lines): bool
    {
        foreach ($lines as $line) {
            // Harga setelah "x" WAJIB ber-ribuan ("X 12.500") — ciri struk
            // grosir dengan qty ber-skala ribuan. Tanpa itu, baris "HARGA xQTY
            // SUBTOTAL" seperti "500.000 x1 500.000" (qty kecil setelah x)
            // ikut terdeteksi sebagai scaled dan seluruh qty struk terbagi
            // 1000 — salah kategori data (expense id 17).
            if (preg_match('/^\d{1,4}[.,]\d{3}\s*(?:[a-zA-Z0-9]+(?:\s+[a-zA-Z0-9]+)*\s*)?[x×*]\s*\d{1,3}(?:[.,]\d{3})/iu', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeProductName(string $name): bool
    {
        return preg_match('/[a-zA-Z\x{00C0}-\x{017F}]/u', $name) === 1;
    }

    /**
     * Pilih nama vendor/toko dari baris-baris awal teks OCR.
     *
     * Baris pertama TIDAK otomatis dipakai: OCR kadang memotong awalan (mis.
     * "CV." terbaca "ev,") atau baris pertama berisi alamat/NPWP. Baris
     * kandidat di-skor (mengandung CV/UD/PT/Toko lebih disukai, baris
     * ber-identifiers disingkirkan), lalu dibersihkan dari artefak OCR.
     *
     * BUG 1: kandidat HANYA diambil dari region HEADER struk (sebelum baris
     * nominal/kode pertama) dan baris jam/tanggal/label-field/referensi
     * di-exclude keras — dulu vendor bisa terisi "10:14:01 WIB, 05-Jan-2021",
     * alamat, atau nama barang ("Pulse Sensor").
     */
    private function extractVendor(array $lines): ?string
    {
        $regionLimit = $this->vendorHeaderRegionLimit($lines);
        $candidates = [];

        foreach (array_slice($lines, 0, $regionLimit) as $index => $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line) < 3) {
                continue;
            }

            $lower = mb_strtolower($line);

            // Baris identifikasi/header yang jelas bukan nama toko. "bayar"
            // memakai lookbehind agar nama toko yang MEMUAT kata itu (mis.
            // "griyabayar" pada struk listrik, expense id 16) tidak ikut
            // terbuang — yang disaring hanya "bayar" sebagai kata sendiri;
            // baris judul "STRUK PEMBAYARAN ..." tersaring lewat \bstruk\b.
            if (preg_match(
            '/(npwp|npup|telp|telepon|no\.?\s*\d|\bno\.?|\bsturk\b|\bstruk\b|\bnomor\b|\d{4}[-_\/]?\d{2}[-_\/]?\d{2}|^jl\.|^jalan|^rt\s|^rw\s|total|kembal|tunai|(?<![a-z])bayar|retur|diskon|\bdisc\b|subtotal|jumlah|ppn|pajak|uang|poin|\bitem\b|\bqty\b|\bharga\b|blok|kav|perum)/i',
                $lower
            ) === 1) {
                continue;
            }

            // BUG 1: baris JAM ("10:14:01 WIB") dan TANGGAL ("05-Jan-2021",
            // "11-04-2012") bukan nama toko — dulu lolos karena posisinya di
            // atas dan mengandung huruf (WIB).
            if (preg_match('/\b\d{1,2}:\d{2}(?::\d{2})?(?:\s*(?:wib|wita|wit))?\b/iu', $lower) === 1
                || preg_match('/\b\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}\b/', $lower) === 1
                || preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $lower) === 1
                || preg_match('/\b\d{1,2}[-\/. ](?:jan|feb|mar|apr|mei|jun|jul|agu|agt|aug|sep|okt|oct|nov|des|dec)[a-z]{0,6}[-\/. ]\d{2,4}\b/iu', $lower) === 1) {
                continue;
            }

            // BUG 1: baris ber-label "FIELD :" (IDPEL :, NAMA :, Kasir :,
            // Pelanggan ;, Kode Struk:, No. Maja:) adalah data terstruktur,
            // bukan nama toko.
            if (preg_match('/^[a-z0-9][a-z0-9.\/]{0,11}(?:\s+[a-z0-9.\/]{1,11})?[:;]/iu', $lower) === 1) {
                continue;
            }

            // BUG 1: run digit panjang (>= 5) = IDPEL/no. HP/kode pos/nomor
            // struk, bukan brand.
            if (preg_match('/\d{5,}/', $lower) === 1) {
                continue;
            }

            // BUG 1: baris nomor referensi/resi.
            if (preg_match('/\b(?:ref|referensi|resi|wsref|ws ref|vst ref)\b/iu', $lower) === 1) {
                continue;
            }

            // Baris item punya minimal 2 nominal (harga + subtotal), mis.
            // "SUSU ULTRA 1L 2 13.500 27.000" — jelas bukan nama toko.
            if (preg_match_all('/\d{2,}[.,]\d{3}/', $line) >= 2) {
                continue;
            }

            // Barcode/nomor murni (mis. EAN) bukan nama toko.
            if (preg_match('/^\d{6,}$/', $line) === 1) {
                continue;
            }

            // Wajib mengandung huruf (nama toko selalu punya huruf).
            if (preg_match('/[a-zA-Z\x{00C0}-\x{017F}]/u', $line) !== 1) {
                continue;
            }

            $score = 0;

            if (preg_match('/\b(cv|ud|pt|toko|store|minimarket|swalayan|mart|market|fresh|super|grosir|resto|restoran|rumah makan|cafe|kafe|warung|waroeng|shop|apotek|bakery|plaza|sentra|bank|hotel|laundry|klinik)\b/i', $line)) {
                $score += 40;
            }
            // Prefiks badan usaha yang terkikis OCR (mis. "CV. ANUGERAH" terbaca
            // "eV, ANUGERAK" / "ev,") diunggulkan setara "CV" — baris ini jelas
            // memuat nama toko, bukan baris alamat seperti "LLRAYA WALEKUKUN".
            if ($this->looksLikeEntityPrefixLine($line)) {
                $score += 40;
            }
            if (preg_match('/^[A-Z0-9]/u', $line)) {
                $score += 10;
            }
            if (mb_strlen($line) >= 5 && mb_strlen($line) <= 40) {
                $score += 10;
            }

            // Penalti: simbol junk OCR ("ajuobauar®") dan kata kota/region
            // ("Kuningan, Jakarta Selatan") bukan brand toko.
            if (preg_match_all('/[^\p{L}\p{N}\s.,&\-\'\/()]/u', $line) >= 1) {
                $score -= 15;
            }
            if (preg_match('/(jakarta|surabaya|bandung|medan|semarang|bekasi|depok|tangerang|bogor|cirebon|malang|palembang|makassar|yogyakarta|denpasar|cimahi|magetan|ngawi|nganjuk|madiun|kudus|jepara|klaten|sragen|jawa|sumatera|kalimantan|sulawesi|papua|indonesia)/iu', $lower)) {
                $score -= 25;
            }

            $score += max(0, 8 - $index); // baris paling atas diunggulkan tipis

            $candidates[] = ['line' => $line, 'score' => $score];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $this->cleanVendor($candidates[0]['line']);
    }

    /**
     * Batas region HEADER struk untuk kandidat vendor: baris ke-0 s.d. SEBELUM
     * baris nominal/kode pertama (nominal ber-ribuan, kode >= 5 digit, atau
     * baris detail "qty x harga"). Minimal 2 baris dipertimbangkan, maksimal
     * 12 — setelah harga muncul, baris tersisa adalah nama produk/alamat/
     * footer, bukan nama toko (mis. "Pulse Sensor" pada struk elektronik).
     */
    private function vendorHeaderRegionLimit(array $lines): int
    {
        $limit = count($lines);

        foreach ($lines as $index => $line) {
            $isMoneyOrCode = preg_match('/\d{1,3}[.,]\d{3}/', $line) === 1
                || preg_match('/\d{5,}/', $line) === 1
                || preg_match('/^\d{1,4}\s*[x×*]\s*\d/iu', $line) === 1;

            if ($index >= 2 && $isMoneyOrCode) {
                $limit = $index;
                break;
            }
        }

        return min(max($limit, 2), 12);
    }

    /**
     * Bersihkan vendor dari artefak OCR umum: fragmen awalan 1-3 huruf diikuti
     * koma (mis. "ev, ANUGERAK" dari "CV. ANUGERAH" yang terpotong), trailing
     * koma/titik/bintang, serta fragmen pendek menjuntai di akhir baris.
     */
    private function cleanVendor(string $line): string
    {
        $line = trim($line);

        // BUG 1: buang junk non-alfanumerik di awal baris — logo/ikon yang
        // salah terbaca OCR ("®) rastpay SSBNI" menjadi "rastpay SSBNI").
        $line = (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $line);

        // 1) Rekonstruksi prefiks badan usaha yang terkikis OCR. "CV. ANUGERAH"
        //    sering terbaca "eV, ANUGERAK" / "ev," / "e V," — deteksi fragmen
        //    awalan 1-3 huruf yang diikuti koma/titik + nama, lalu kembalikan
        //    bentuk bakunya ("CV."). Fragmen ber-alamat (JL/JLN/RT/RW/NO/dll)
        //    sengaja TIDAK diubah agar "JL, ..." tidak jadi "CV. ...".
        // Pemulihan prefiks badan usaha yang terkikis OCR hanya dilakukan bila
        // fragmen 1-3 huruf benar-benar diikuti koma/titik ("eV, ANUGERAH",
        // "UD. BERKAH"), ATAU prefiks utuh yang sudah dikenal (CV/UD/PT/PD/FA/
        // TOKO/WARUNG). Tanpa bukti pemisah itu, baris seperti "GROSIR SEMBAKO
        // DAN BERAS" TIDAK boleh berubah jadi "CV. SIR ...". Fragmen ber-alamat
        // (JL/JLN/RT/RW/NO/dll) sengaja TIDAK diubah.
        if (preg_match('/^([a-zA-Z]{1,3})\s*[.,]\s+([A-Za-z].*)$/u', $line, $m)
            || preg_match('/^(cv|ud|pt|pd|fa|toko|warung)\b[.,]?\s+([A-Za-z].*)$/iu', $line, $m)) {
            $frag = mb_strtoupper(rtrim($m[1], '.'));
            $nonEntity = ['JL', 'JLN', 'JALAN', 'J', 'RT', 'RW', 'NO', 'NP', 'NPW',
                'TEL', 'TELP', 'KAB', 'KEC', 'KEL', 'DS', 'DESA', 'KOTA', 'LT'];
            if (strlen($frag) <= 3 && ! in_array($frag, $nonEntity, true)) {
                $entity = in_array($frag, ['CV', 'UD', 'PT', 'PD', 'FA'], true) ? $frag : 'CV';
                $line = $entity.'. '.trim($m[2]);
            }
        }

        // Buang tanda baca/simbol di ujung baris.
        $line = (string) preg_replace('/[\s,.*=~\-]+$/', '', $line);

        // Buang fragmen pendek (1-2 huruf) yang menjuntai di akhir.
        $line = (string) preg_replace('/\s+[a-zA-Z]{1,2},?$/u', '', $line);

        // Normalisasi spasi ganda.
        return trim((string) preg_replace('/\s+/', ' ', $line));
    }

    /**
     * True bila baris diawali fragmen pendek (1-3 huruf, boleh terpisah spasi)
     * yang diikuti koma/titik sebelum nama — ciri khas prefiks badan usaha yang
     * terkikis OCR (mis. "eV, ANUGERAK", "ev,", "UD. BERKAH"), bukan baris
     * alamat/barcode biasa.
     */
    private function looksLikeEntityPrefixLine(string $line): bool
    {
        return preg_match('/^[a-zA-Z]{1,3}(\s*[a-zA-Z]{1,3})?\s*[.,]\s*[A-Z]/u', $line) === 1;
    }

    /**
     * BUG 4 — koreksi "digit nyelip" dengan validasi silang terhadap total
     * struk. Kasus nyata: struk BNI mencetak "ADMIN BANK : Rp 1.600" tetapi
     * OCR menyambung junk di depannya sehingga terbaca "Rp 11600" (nyelip satu
     * digit di depan). Karena baris itu tidak punya subtotal tercetak sendiri
     * (hanya satu nominal), satu-satunya bukti kebenaran adalah TOTAL struk:
     * nominal benar adalah varian hapus-satu-digit yang membuat jumlah item
     * paling mendekati total.
     *
     * Aturan agar struk yang SUDAH benar tidak ikut terkoreksi:
     *  - hanya berlaku bila total > 0 dan jumlah item TIDAK sama dengan total;
     *  - hanya item dari jalur single-price (tanpa subtotal kembar di baris)
     *    yang boleh dikoreksi — item ber-"harga + subtotal" tercetak sudah
     *    punya bukti internal, sehingga struk diskon (Indomaret: selisih =
     *    "ANDA HEMAT") tidak tersentuh;
     *  - koreksi diterima hanya bila selisih baru <= 30% selisih semula dan
     *    hanya SATU item yang disentuh.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function reconcileItemsAgainstTotal(array $items, float $total): array
    {
        if ($this->scaledQtyStruk || $total <= 0 || count($items) < 2) {
            return $items;
        }

        $sum = 0.0;
        foreach ($items as $item) {
            $sum += (float) ($item['subtotal'] ?? 0);
        }

        $gap = abs($sum - $total);
        if ($gap < 0.01) {
            return $items;
        }

        $best = null;

        foreach ($items as $index => $item) {
            if (($item['single_price'] ?? false) !== true) {
                continue;
            }

            $price = (float) ($item['price'] ?? 0);
            $digits = (string) (int) $price;

            // Nominal harus bilangan bulat >= 4 digit agar varian hapus-digit
            // bermakna (mis. "11600" -> "1600"), bukan pecahan/ber-ribuan.
            if ($price < 1000 || abs($price - (float) $digits) > 0.01 || strlen($digits) < 4) {
                continue;
            }

            foreach ($this->singleDigitDeletionVariants($digits) as $variant) {
                $newPrice = (float) $variant;
                if ($newPrice < 100) {
                    continue;
                }

                $newSum = $sum - $price + $newPrice;
                $newGap = abs($newSum - $total);

                if ($newGap < $gap * 0.3 && ($best === null || $newGap < $best['gap'])) {
                    $best = [
                        'index' => $index,
                        'gap' => $newGap,
                        'price' => $newPrice,
                    ];
                }
            }
        }

        if ($best === null) {
            return $items;
        }

        Log::warning(sprintf(
            'BUG 4 digit-nyelip terkoreksi pada item "%s": %s -> %s (validasi silang total struk).',
            $items[$best['index']]['name'] ?? '?',
            $items[$best['index']]['price'] ?? '?',
            $best['price'],
        ));

        $items[$best['index']]['price'] = $best['price'];
        $items[$best['index']]['subtotal'] = $best['price'];

        return $items;
    }

    /**
     * Semua nilai unik hasil menghapus SATU digit dari token angka
     * ("11600" -> {"1600", "1100", "1160"}).
     *
     * @return array<int, string>
     */
    private function singleDigitDeletionVariants(string $digits): array
    {
        $variants = [];

        for ($i = 0, $n = strlen($digits); $i < $n; $i++) {
            $variant = substr($digits, 0, $i).substr($digits, $i + 1);
            if ($variant !== '' && ! in_array($variant, $variants, true)) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    /**
     * BUG residual — validasi silang field TOTAL terhadap penjumlahan item
     * yang SUDAH direkonsiliasi (reconcileItemsAgainstTotal). Item-level
     * lebih dipercaya karena sudah tervalidasi silang; total tercetak belum
     * pernah punya pemeriksaan digit-nyelip.
     *
     * Kasus nyata (struk BNI, expense id 18): struk mencetak
     * "TOTAL BAYAR : Rp 146.975" tetapi OCR salah baca SATU digit menjadi
     * "Rp 148.975", padahal TAG PLN 145.375 + ADMIN BANK 1.600 = tepat
     * 146.975.
     *
     * Agar selisih yang SAH tidak ikut tertimpa (diskon "ANDA HEMAT" pada
     * struk Indomaret, PPN pada struk resto, retur), penjumlahan item hanya
     * menggantikan total tercetak bila identik setelah koreksi digit tunggal:
     *  - Pola 1: sama panjang digit, beda TEPAT satu posisi yang BUKAN digit
     *    terdepan (salah baca satu digit: 146.975 terbaca 148.975);
     *  - Pola 2: total kelebihan SATU digit nyelip — menghapus satu digit
     *    total menghasilkan tepat penjumlahan item (146.975 terbaca
     *    1.146.975), persis pola "nyelip" pada sisi item.
     *
     * Di luar dua pola itu total tercetak DIPERTAHANKAN dan mismatch hanya
     * dicatat ke log agar terlihat saat diagnosis.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function validateTotalAgainstItems(array $items, float $total): float
    {
        if ($this->scaledQtyStruk || $total <= 0 || $items === []) {
            return $total;
        }

        $sumItems = 0.0;
        foreach ($items as $item) {
            $sumItems += (float) ($item['subtotal'] ?? 0);
        }

        if ($sumItems <= 0 || abs($sumItems - $total) < 0.01) {
            return $total;
        }

        Log::warning(sprintf(
            'Fallback: jumlah subtotal item (%s) != total tercetak (%s), selisih %s. Total tercetak tetap dipakai kecuali lolos uji digit-nyelip.',
            number_format($sumItems, 2, ',', '.'),
            number_format($total, 2, ',', '.'),
            number_format($total - $sumItems, 2, ',', '.'),
        ));

        $sumDigits = (string) (int) round($sumItems);
        $totalDigits = (string) (int) round($total);

        $adopted = false;

        // Pola 1: salah baca SATU digit di posisi bukan terdepan
        // ("146975" terbaca "148975" — beda tepat di posisi ke-3).
        if (strlen($sumDigits) === strlen($totalDigits)) {
            $diff = 0;
            $firstDiffAt = -1;
            for ($i = 0, $n = strlen($totalDigits); $i < $n; $i++) {
                if ($sumDigits[$i] !== $totalDigits[$i]) {
                    $diff++;
                    $firstDiffAt = $firstDiffAt === -1 ? $i : $firstDiffAt;
                }
            }

            // Digit terdepan sengaja dikecualikan: beda digit terdepan
            // mengubah besar nilai drastis (>= ~10%) dan lebih mungkin
            // selisih komposisi yang sah daripada salah baca OCR.
            $relativeDiff = $total > 0 ? abs($total - $sumItems) / $total : 1.0;
            $adopted = $diff === 1 && $firstDiffAt >= 1 && $relativeDiff <= 0.25;
        }

        // Pola 2: total kelebihan satu digit nyelip di depan/tengah.
        if (! $adopted) {
            foreach ($this->singleDigitDeletionVariants($totalDigits) as $variant) {
                if ($variant === $sumDigits) {
                    $adopted = true;
                    break;
                }
            }
        }

        if (! $adopted) {
            return $total;
        }

        Log::warning(sprintf(
            'Digit-nyelip pada baris TOTAL terkoreksi: %s -> %s (dipakai penjumlahan item yang sudah tervalidasi silang).',
            $totalDigits,
            $sumDigits,
        ));

        return round($sumItems, 2);
    }

    /**
     * Cek apakah sebuah baris mengandung salah satu kata kontrol (great untuk
     * meng-skip baris non-item seperti "Disc.", "Total", "Kembalian", dll).
     */
    private function lineHasAnyKeyword(string $lowerLine, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($lowerLine, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
