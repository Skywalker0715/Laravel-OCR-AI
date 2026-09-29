<?php

namespace App\Filament\Resources\Incomes\Schemas;

use App\Support\MoneyFormatter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Form Create/Edit catatan Pemasukan.
 *
 * Field dibatasi ke informasi inti (sumber, nominal, tanggal diterima,
 * catatan) sesuai skema tabel incomes. Sumber berupa teks bebas — bukan
 * enum — supaya tetap generik untuk kebutuhan personal (gaji, bonus)
 * maupun UMKM (penjualan, komisi).
 */
class IncomeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Informasi Pemasukan')
                    ->columnSpanFull()
                    ->icon(Heroicon::OutlinedArrowTrendingUp)
                    ->iconColor('success')
                    ->columns(2)
                    ->schema([
                        TextInput::make('source')
                            ->label('Sumber')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('cth. Gaji / Freelance / Penjualan')
                            ->helperText('Tulis bebas sumber pemasukan, mis. "Gaji" atau "Penjualan".')
                            ->columnSpan(1),

                        TextInput::make('amount')
                            ->label('Jumlah')
                            ->required()
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0.01)
                            // Batas atas menyesuaikan kapasitas kolom decimal(15,2);
                            // tanpa ini nominal besar gagal saat disimpan dengan
                            // error PostgreSQL "numeric field overflow".
                            ->maxValue(MoneyFormatter::MAX_INPUT_AMOUNT)
                            ->validationMessages([
                                'max' => MoneyFormatter::maxInputMessage(),
                            ])
                            ->step(0.01)
                            ->placeholder('cth. 3500000')
                            ->columnSpan(1),

                        DatePicker::make('date_received')
                            ->label('Tanggal Diterima')
                            ->required()
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->default(today())
                            ->columnSpan(1),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->nullable()
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder('cth. Gaji bulanan / hasil penjualan pekan ini')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
