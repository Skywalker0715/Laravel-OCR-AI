<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;

class OCRService
{
    public function extractTextFromImage(string $path): string
    {
        return (new TesseractOCR(image: $path))
            ->lang('ind', 'eng')
            ->run();
    }
}