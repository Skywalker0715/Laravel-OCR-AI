<?php

namespace App\Services;
use Illuminate\Support\Facades\Log;


Class Helper {

    public function cleanCohereResponse(string $responseText): ?array
{
    // Hapus teks di awal sebelum karakter { atau [
    $cleaned = trim(string: preg_replace(pattern: '/^[^{[]+/', replacement: '', subject: $responseText));

    // Optional: hapus trailing teks setelah JSON ditutup
    $closingPos = strrpos(haystack: $cleaned, needle: '}');
    if ($closingPos !== false) {
        $cleaned = substr(string: $cleaned, offset: 0, length: $closingPos + 1);
    }

    try {
        return json_decode(json: $cleaned, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        Log::warning(message: 'Gagal decode JSON AI: ' . $e->getMessage(), context: [
            'response' => $responseText,
            'cleaned' => $cleaned,
        ]);

        return null;
    }
}

}