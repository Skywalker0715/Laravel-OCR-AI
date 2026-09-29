<?php

namespace App\Filament\Resources\Debts\Pages;

use App\Filament\Resources\Debts\DebtResource;
use App\Models\Debt;
use Filament\Resources\Pages\CreateRecord;

class CreateDebt extends CreateRecord
{
    protected static string $resource = DebtResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Setiap catatan baru otomatis milik user yang sedang login (model juga
        // punya safety net serupa di hook creating).
        $data['user_id'] = auth()->id();

        // Catatan baru selalu mulai dari nol pembayaran; model menghitung
        // status ('belum_lunas') dari pasangan amount & paid_amount ini.
        $data['paid_amount'] = $data['paid_amount'] ?? 0;
        $data['status'] = Debt::STATUS_BELUM_LUNAS;

        return $data;
    }
}
