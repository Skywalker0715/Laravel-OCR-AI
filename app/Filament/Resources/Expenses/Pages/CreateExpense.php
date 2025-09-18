<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Resources\Pages\CreateRecord;
use App\Services\OCRService;
use App\Services\Helper;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    private Helper $helper;
 
    protected function afterCreate(): void
    {
        $this->helper = app(Helper::class);
       try{
        $record = $this->record;

        if ($record->receipt_image) {
            $path = storage_path(path: "app/public/{$record->receipt_image}");
            $ocr = new OCRService();
            $text = $ocr->extractTextFromImage(path: $path);

            $record->note = $text;
            $record->save();

            // Dispatch the job to parse the note using AI
            dispatch(new \App\Jobs\AIParserJob(record: $record));
           
        }

       } catch (\Throwable $th) {
           throw $th;
    }
    }
}
