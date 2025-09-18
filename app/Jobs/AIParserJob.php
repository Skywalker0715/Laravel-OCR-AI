<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\Expense;
use App\Services\AIParserService;
use App\Services\Helper;

class AIParserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private Expense $record;
    private Helper $helper;

    /**
     * Create a new job instance.
     */
    public function __construct(Expense $record)
    {
        $this->record = $record;
    }
  
    public function handle(): void
    {
        Log::info('AIParserJob started for record id: ' . $this->record->id);
        $this->helper = app(Helper::class);
        $ai = new AIParserService();
        $parsed = $ai->parseWithAI(text: $this->record->note);

        Log::info('Parsed data from AIParserService: ' . json_encode($parsed));

        if (!$parsed) {
            Log::error('AIParserService returned null or invalid data for record id: ' . $this->record->id);
            return;
        }

        $this->record->date_shopping = $parsed['date'] ?? null;
        $this->record->amount = $parsed['total'] ?? 0;
        $this->record->parsed_data = $parsed['items'] ?? [];

        $dataClean = $this->helper->extractSpecialFieldsAndCleanItems(items: $parsed['items'] ?? []);
        $this->record->change = $dataClean['extractedFields']['kembalian'] ?? 0;

        try {
            $this->record->save();
            Log::info('Record saved successfully for id: ' . $this->record->id);
        } catch (\Throwable $e) {
            Log::error('Error saving record id ' . $this->record->id . ': ' . $e->getMessage());
        }

        foreach ($parsed['items'] ?? [] as $item) {
            try {
                $this->record->items()->create(attributes: $item);
            } catch (\Throwable $e) {
                Log::error('Error creating item for record id ' . $this->record->id . ': ' . $e->getMessage());
            }
        }
        Log::info('AIParserJob completed for record id: ' . $this->record->id);
    }
}
