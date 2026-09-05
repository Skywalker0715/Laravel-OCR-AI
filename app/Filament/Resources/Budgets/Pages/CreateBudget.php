<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Resources\Budgets\BudgetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBudget extends CreateRecord
{
    protected static string $resource = BudgetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Setiap budget baru otomatis menjadi milik user yang sedang login.
        // (Model juga punya safety net serupa di hook creating.)
        $data['user_id'] = auth()->id();

        return $data;
    }
}
