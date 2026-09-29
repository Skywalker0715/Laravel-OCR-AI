<?php

namespace App\Filament\Resources\Debts\Actions;

use App\Models\Debt;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Kumpulan action khusus catatan Utang Piutang, dipakai bersama oleh tabel
 * daftar (per baris) dan header halaman View supaya perilakunya identik di
 * kedua tempat:
 *
 *  - markAsPaid()     : "Tandai Lunas" (isi paid_amount = amount).
 *  - recordPayment()  : "Catat Pembayaran Sebagian" (modal input nominal).
 *
 * Keduanya hanya mengubah `paid_amount`; kolom `status` dihitung ulang oleh
 * model Debt (hook saving) sehingga tidak pernah ada status yang menyimpang
 * dari nominalnya.
 */
class DebtActions
{
    /** Aksi "Tandai Lunas" untuk satu catatan yang belum lunas. */
    public static function markAsPaid(): Action
    {
        return Action::make('markAsPaid')
            ->label('Tandai Lunas')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Tandai catatan ini lunas?')
            ->modalDescription(fn (Debt $record): string => sprintf(
                '%s "%s" akan dianggap terbayar penuh — sisa %s langsung dihitung lunas.',
                $record->typeLabel(),
                $record->counterparty_name,
                MoneyFormatter::format($record->remainingAmount()) ?? 'Rp 0',
            ))
            ->modalSubmitActionLabel('Ya, tandai lunas')
            // Catatan yang sudah lunas tidak perlu ditandai lagi.
            ->visible(fn (Debt $record): bool => $record->isActive())
            ->action(function (Debt $record): void {
                $record->markAsPaid();
            })
            ->successNotification(fn (Debt $record): Notification => Notification::make()
                ->success()
                ->title('Ditandai lunas')
                ->body(sprintf(
                    '%s "%s" sudah lunas (%s terbayar penuh).',
                    $record->typeLabel(),
                    $record->counterparty_name,
                    MoneyFormatter::format($record->amount) ?? 'Rp 0',
                )));
    }

    /**
     * Aksi "Catat Pembayaran Sebagian": input nominal pembayaran/penerimaan,
     * dijumlahkan ke paid_amount, lalu status dihitung ulang oleh model
     * (sebagian → lunas bila sudah menutup seluruh sisa).
     */
    public static function recordPayment(): Action
    {
        return Action::make('recordPayment')
            ->label('Catat Pembayaran')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('info')
            ->modalHeading('Catat Pembayaran')
            ->modalDescription(fn (Debt $record): string => sprintf(
                'Catat pembayaran untuk %s "%s". Sisa saat ini %s.',
                strtolower($record->typeLabel()),
                $record->counterparty_name,
                MoneyFormatter::format($record->remainingAmount()) ?? 'Rp 0',
            ))
            ->modalSubmitActionLabel('Simpan Pembayaran')
            ->schema([
                TextInput::make('payment_amount')
                    ->label('Nominal Pembayaran')
                    ->required()
                    ->numeric()
                    ->prefix('Rp')
                    ->minValue(0.01)
                    ->step(0.01)
                    // Tidak boleh melebihi sisa: pelunasan penuh lewat aksi
                    // "Tandai Lunas", sisanya di-clamp model.
                    ->maxValue(fn (Debt $record): float => $record->remainingAmount())
                    ->helperText(fn (Debt $record): string => sprintf(
                        'Maksimal %s (sisa tagihan).',
                        MoneyFormatter::format($record->remainingAmount()) ?? 'Rp 0',
                    )),
            ])
            // Nilai awal = sisa tagihan, sehingga pelunasan penuh cukup sekali
            // klik; user tinggal mengubahnya untuk pembayaran sebagian.
            ->fillForm(fn (Debt $record): array => [
                'payment_amount' => $record->remainingAmount(),
            ])
            ->visible(fn (Debt $record): bool => $record->isActive())
            ->action(function (Debt $record, array $data): void {
                $record->recordPayment((float) ($data['payment_amount'] ?? 0));
            })
            ->successNotification(fn (Debt $record): Notification => Notification::make()
                ->success()
                ->title('Pembayaran tercatat')
                ->body(sprintf(
                    'Status "%s": %s. Sisa %s.',
                    $record->counterparty_name,
                    $record->statusLabel(),
                    MoneyFormatter::format($record->remainingAmount()) ?? 'Rp 0',
                )));
    }
}
