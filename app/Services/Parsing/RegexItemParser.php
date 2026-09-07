<?php

namespace App\Services\Parsing;

use App\Services\Helper;

/**
 * Mesin regex fallback untuk mengekstrak baris ITEM dari teks OCR struk.
 * Mendukung berbagai gaya baris item struk Indonesia (kolom lengkap, tanpa
 * subtotal, format 2-baris bernomor urut, "Nama xQty Harga", harga tunggal)
 * plus guard anti item-hantu (jam, kode referensi, baris retur, footer).
 *
 * Diekstrak dari AIParserService (refactor struktural — perilaku identik).
 * State per-struk $scaledQtyStruk ("qty ber-skala ribuan", ciri grosir kecil
 * seperti Toko Abang: "4.000 Kg X ..." = qty 4) dideteksi ulang di awal setiap
 * parseItems() dan memengaruhi pembacaan qty/subtotal; nilai terakhirnya
 * diekspos lewat isScaledQtyStruk() untuk keputusan total & rekonsiliasi.
 */
class RegexItemParser
{
    private Helper $helper;

    /**
     * Batas atas nominal rupiah yang masuk akal per baris item (< Rp 1 miliar).
     * Token di atasnya hampir pasti nomor identitas struk (IDPEL, no. HP/WA,
     * kode referensi) yang salah tertangkap regex dan membuat INSERT gagal
     * SQLSTATE[22003] — baris seperti itu ditolak sebagai item.
     */
    private const MAX_PLAUSIBLE_MONEY = 999999999.0;

    /**
     * True bila struk memakai "qty ber-skala ribuan" (ciri grosir kecil seperti
     * Toko Abang: "4.000 Kg X ..." = qty 4). Deteksi per-struk, bukan hardcode,
     * agar struk nominal biasa (Indomaret, Karis Jaya) tetap dihitung normal.
     */
    private bool $scaledQtyStruk = false;

    public function __construct(?Helper $helper = null)
    {
        $this->helper = $helper ?? new Helper;
    }

    /**
     * Parse baris-baris OCR menjadi daftar item (fallback regex): baris yang
     * jelas bukan item (kontrol, identitas, footer) di-skip, sisanya dicocokkan
     * ke berbagai gaya baris item; baris DETAIL format 2-baris dipasangkan
     * dengan baris namanya di atasnya. Mendeteksi jenis normalisasi qty
     * per-struk SEBELUM memproses item.
     *
     * @param  array<int, string>  $lines  Baris OCR yang sudah di-trim & non-kosong.
     * @return array<int, array{name: string, qty: float|int, price: float, subtotal: float}>
     */
    public function parseItems(array $lines): array
    {
        $items = [];

        // Deteksi jenis normalisasi qty per-struk SEBELUM memproses item.
        // Struk tipe "qty ber-skala ribuan" (mis. Toko Abang) memakai titik
        // sebagai akhiran ribuan semu pada kolom qty ("4.000 Kg" = 4 kg);
        // struk nominal uang biasa (Indomaret, Karis Jaya) memakai koma/titik
        // sebagai pemisah ribuan sungguhan pada nilai uang.
        $this->scaledQtyStruk = $this->detectScaledQtyStruk($lines);

        // Parsing item didelegasikan ke matchItemLine() (mendukung banyak gaya
        // struk Indonesia, termasuk format 2-baris bernomor urut); baris yang
        // jelas bukan item di-skip di bawah.
        // Kata kunci baris kontrol (termasuk varian OCR "TUNAE", "KEMBALE",
        // "ANDA HEMAT", "DPP", "POIN") agar baris tsb tidak terambil sebagai item.
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

            // Lewati baris retur/diskon bernilai negatif ("-1.500", "(200)").
            // Pola dibuat sempit agar: dash antar-angka kode produk ("1-300 308")
            // dan kurung tak-tutup di depan nominal POSITIF ("BLS97.6 9700 (9,700")
            // tidak ikut dibuang — hanya kurung-minus yang jelas ("(-2.500")
            // yang selalu dilewati.
            if (preg_match('/(?:^|\s)-\d|\(-\d|\([0-9][0-9.,]*\)/', $line) === 1) {
                continue;
            }

            // Struk format 2-baris: baris "1. Indomie Goreng" + baris detail
            // "1 lusin x 36,000 Rp 36.000" = SATU item. Tanpa ini baris detail
            // salah terbaca sebagai item "1 lusin x" dan nama produknya hilang.
            // "(?!\d)" mencegah harga ber-ribuan ("225.000 x1 225.000") dianggap
            // nomor urut "225." (expense id 17).
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

        return $items;
    }

    /** Nilai $scaledQtyStruk hasil deteksi terakhir (state lintas fallback + matcher item). */
    public function isScaledQtyStruk(): bool
    {
        return $this->scaledQtyStruk;
    }

    /**
     * Cocokkan satu baris OCR dengan berbagai gaya baris item struk Indonesia
     * ("1 x Nama 10.000", kolom lengkap/tanpa subtotal, harga "@"); null bila bukan
     * produk. Nama produk wajib ber-huruf agar baris numerik murni (EAN) tak terambil.
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
     * Pasangkan baris nama bernomor urut dengan baris DETAIL di bawahnya — format
     * 2-baris ("1. Indomie Goreng" + "1 lusin x 36,000 Rp 36.000"); baris detail
     * "qty [satuan] x harga [Rp subtotal]" (opsional), null bila tak cocok pola.
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
     * Ekstrak bagian "qty [satuan] x harga [Rp subtotal]" dari sebuah baris;
     * null bila bukan baris detail. Teks setelah "x" wajib nominal — bila huruf,
     * itu item utuh 1-baris pola (A) yang tidak boleh ikut di-pairing.
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
     * Coba semua pola baris item pada teks: (D) "Nama @harga qty subtotal",
     * (B) "[barcode] Nama qty harga subtotal", (C) tanpa subtotal, (E) tanpa
     * kolom qty (qty dari subtotal/price bila bulat), (A) "qty x Nama Harga".
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

        // Koreksi misparse: angka pada kolom QTY sebenarnya bagian dari NAMA
        // produk (satuan menempel tanpa spasi, mis. "24G" terbaca "246").
        // Ciri: qty > 1 tapi subtotal tercetak = harga satuan — kembalikan
        // angka itu ke nama produk dan set qty = 1.
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
     * Susun item dari baris DETAIL format 2-baris "qty [satuan] x harga". Untuk
     * struk scaled-qty (Toko Abang) qty dibagi 1000 ("4.000 Kg" -> 4) dan subtotal
     * dihitung ulang dari qty x harga — subtotal tercetak OCR terbukti korup.
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

        // BUG 3 lanjutan: sebagian struk menulis "HARGA xQTY SUBTOTAL" ("500.000
        // x1 500.000"). Ciri: qty >= 100, harga kecil 1-9, dan qty x harga pas
        // dengan subtotal tercetak — tukar posisinya (tidak berlaku pada scaled-qty).
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

    /** Deteksi struk dengan "skala ribuan semu" pada qty ("4.000 Kg x harga" —
     * ciri Toko Abang); struk nominal biasa (Indomaret, Karis Jaya) tak terdeteksi. */
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
