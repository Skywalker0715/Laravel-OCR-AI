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

    public function extractSpecialFieldsAndCleanItems(array $items): array
    {
        $extractedFields = ['kembalian' => 0];
        $cleanedItems = [];

        foreach ($items as $item) {
            $name = strtolower($item['name'] ?? '');
            if (str_contains($name, 'kembalian') || str_contains($name, 'change') || str_contains($name, 'kembali')) {
                // Extract numeric value from name, subtotal, or price
                $value = 0;
                if (isset($item['subtotal'])) {
                    $value = (float) str_replace(['Rp', ',', '.'], '', $item['subtotal']);
                } elseif (isset($item['price'])) {
                    $value = (float) str_replace(['Rp', ',', '.'], '', $item['price']);
                } elseif (preg_match('/(\d+(?:,\d+)?)/', $name, $matches)) {
                    $value = (float) str_replace(',', '', $matches[1]);
                }
                $extractedFields['kembalian'] = $value;
                // Skip adding to cleaned items
                continue;
            }
            $cleanedItems[] = $item;
        }

        return [
            'extractedFields' => $extractedFields,
            'items' => $cleanedItems
        ];
    }

}
