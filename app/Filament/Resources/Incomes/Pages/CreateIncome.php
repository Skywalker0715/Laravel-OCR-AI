<?php

namespace App\Filament\Resources\Incomes\Pages;

use App\Filament\Resources\Incomes\IncomeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIncome extends CreateRecord
{
    protected static string $resource = IncomeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Setiap catatan pemasukan baru otomatis milik user yang sedang
        // login (model juga punya safety net serupa di hook creating).
        $data['user_id'] = auth()->id();

        return $data;
    }
}
