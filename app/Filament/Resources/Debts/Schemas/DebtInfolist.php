<?php

namespace App\Filament\Resources\Debts\Schemas;

use App\Models\Debt;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

/**
 * Infolist halaman View catatan Utang Piutang: ringkasan nominal
 * (jumlah / terbayar / sisa) plus informasi pihak & tenggat.
 */
class DebtInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Informasi Utang Piutang')
                    ->columnSpan(1)
                    ->icon(Heroicon::OutlinedScale)
                    ->iconColor('success')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('type')
                            ->label('Tipe')
                            ->badge()
                            ->formatStateUsing(fn (Debt $record): string => $record->typeLabel())
                            ->color(fn (Debt $record): string => $record->typeColor())
                            ->weight(FontWeight::SemiBold),

                        TextEntry::make('counterparty_name')
                            ->label('Nama Pihak')
                            ->weight(FontWeight::SemiBold),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (Debt $record): string => $record->statusLabel())
                            ->color(fn (Debt $record): string => $record->statusColor()),

                        TextEntry::make('due_date')
                            ->label('Jatuh Tempo')
                            ->placeholder('—')
                            ->formatStateUsing(fn (Debt $record, mixed $state): ?string => $state === null
                                ? null
                                : $record->due_date->format('d M Y')
                                    .($record->isOverdue() ? ' (terlewat)' : ''))
                            ->color(fn (Debt $record): string => $record->isOverdue() ? 'danger' : 'gray'),

                        TextEntry::make('notes')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Ringkasan Nominal')
                    ->columnSpan(1)
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->iconColor('success')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('amount')
                            ->label('Jumlah')
                            ->formatStateUsing(fn (mixed $state): ?string => MoneyFormatter::format($state))
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold),

                        TextEntry::make('paid_amount')
                            ->label('Sudah Dibayar')
                            ->formatStateUsing(fn (Debt $record): string => (MoneyFormatter::format($record->paid_amount) ?? 'Rp 0')
                                .' ('.$record->paidPercent().'%)')
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->color('info'),

                        TextEntry::make('remaining')
                            ->label('Sisa')
                            ->state(fn (Debt $record): string => MoneyFormatter::format($record->remainingAmount()) ?? 'Rp 0')
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->color(fn (Debt $record): string => $record->isLunas() ? 'success' : 'danger')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
