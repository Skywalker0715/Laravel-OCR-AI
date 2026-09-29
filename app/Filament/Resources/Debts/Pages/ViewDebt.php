<?php

namespace App\Filament\Resources\Debts\Pages;

use App\Filament\Resources\Debts\Actions\DebtActions;
use App\Filament\Resources\Debts\DebtResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman detail catatan Utang Piutang.
 *
 * Action "Tandai Lunas" & "Catat Pembayaran Sebagian" dipasang juga di header
 * (selain per baris di tabel). Karena action memutasi instance model yang
 * sama dengan $this->record, infolist langsung menampilkan nominal & status
 * terbaru tanpa reload manual.
 */
class ViewDebt extends ViewRecord
{
    protected static string $resource = DebtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DebtActions::markAsPaid(),
            DebtActions::recordPayment(),
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return 'Detail '.$this->record->typeLabel().': '.$this->record->counterparty_name;
    }
}
