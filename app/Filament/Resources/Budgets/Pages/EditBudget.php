<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Budgets\Concerns\ConvertsBudgetUniqueViolation;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class EditBudget extends EditRecord
{
    use ConvertsBudgetUniqueViolation;

    protected static string $resource = BudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Backstop balapan: bila unique constraint budgets menolak UPDATE (mis. user
     * lain/request lain menyisipkan periode yang sama setelah validasi form
     * lolos), tampilkan pesan validasi ramah di field Bulan — bukan SQLSTATE.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateBudgetValidationException();
        }
    }
}
