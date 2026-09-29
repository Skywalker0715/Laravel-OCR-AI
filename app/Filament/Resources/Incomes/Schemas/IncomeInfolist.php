<?php

namespace App\Filament\Resources\Incomes\Schemas;

use App\Models\Income;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

/**
 * Infolist halaman View catatan Pemasukan: nominal menonjol (gaya sama
 * dengan Ringkasan Nominal pada resource lain) plus detail sumber,
 * tanggal diterima, dan catatan.
 */
class IncomeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Nominal Pemasukan')
                    ->columnSpan(1)
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->iconColor('success')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('amount')
                            ->label('Jumlah')
                            ->formatStateUsing(fn (mixed $state): ?string => MoneyFormatter::format($state))
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->color('success'),
                    ]),

                Section::make('Informasi Pemasukan')
                    ->columnSpan(1)
                    ->icon(Heroicon::OutlinedArrowTrendingUp)
                    ->iconColor('success')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('source')
                            ->label('Sumber')
                            ->weight(FontWeight::SemiBold),

                        TextEntry::make('date_received')
                            ->label('Tanggal Diterima')
                            ->formatStateUsing(fn (Income $record): string => $record->date_received->format('d M Y')),

                        TextEntry::make('notes')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
