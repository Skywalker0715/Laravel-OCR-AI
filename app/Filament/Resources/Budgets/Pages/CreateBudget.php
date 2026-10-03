<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Budgets\Concerns\ConvertsBudgetUniqueViolation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateBudget extends CreateRecord
{
    use ConvertsBudgetUniqueViolation;

    protected static string $resource = BudgetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Setiap budget baru otomatis menjadi milik user yang sedang login.
        // (Model juga punya safety net serupa di hook creating.)
        $data['user_id'] = auth()->id();

        return $data;
    }

    /**
     * Backstop balapan: bila unique constraint budgets menolak INSERT (duplikat
     * tersisip setelah validasi form lolos), tampilkan pesan validasi ramah di
     * field Bulan — bukan SQLSTATE mentah.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateBudgetValidationException();
        }
    }
}
