<?php

namespace App\Services\Parsing;

/**
 * Heuristik deteksi nama vendor/toko dari baris-baris awal OCR struk:
 * kandidat di-skor (CV/UD/PT/Toko lebih disukai), baris jam/tanggal/identitas
 * di-exclude, lalu dibersihkan dari artefak OCR.
 *
 * Diekstrak dari AIParserService (refactor struktural — perilaku identik).
 */
class VendorDetector
{
    /**
     * Pilih nama vendor/toko dari baris-baris awal OCR: baris pertama tak otomatis
     * dipakai (bisa alamat/NPWP); kandidat di-skor (CV/UD/PT/Toko lebih disukai),
     * baris jam/tanggal/identitas di-exclude, lalu dibersihkan dari artefak OCR.
     */
    public function detect(array $lines): ?string
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
     * Batas region vendor = baris sebelum nominal/kode pertama (min 2, maks 12);
     * setelah harga muncul sisanya produk/alamat/footer, bukan nama toko.
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

    /** True bila baris diawali fragmen 1-3 huruf + koma/titik sebelum nama — ciri
     * prefiks badan usaha terkikis OCR ("eV, ANUGERAK"), bukan baris alamat. */
    private function looksLikeEntityPrefixLine(string $line): bool
    {
        return preg_match('/^[a-zA-Z]{1,3}(\s*[a-zA-Z]{1,3})?\s*[.,]\s*[A-Z]/u', $line) === 1;
    }
}
