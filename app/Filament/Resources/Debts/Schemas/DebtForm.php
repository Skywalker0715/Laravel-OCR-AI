<?php

namespace App\Filament\Resources\Debts\Schemas;

use App\Models\Debt;
use App\Support\MoneyFormatter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Form Create/Edit catatan Utang Piutang.
 *
 * Field sengaja dibatasi ke informasi inti (tipe, nama pihak, jumlah, jatuh
 * tempo opsional, catatan). Kolom `status` dan `paid_amount` TIDAK bisa
 * diisi bebas dari sini: status selalu diturunkan model Debt dari nominal
 * terbayar, dan pelunasan dicatat lewat action "Tandai Lunas" /
 * "Catat Pembayaran Sebagian" agar konsisten.
 */
class DebtForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Informasi Utang Piutang')
                    ->columnSpanFull()
                    ->icon(Heroicon::OutlinedScale)
                    ->iconColor('success')
                    ->columns(2)
                    ->schema([
                        Select::make('type')
                            ->label('Tipe')
                            ->options(Debt::typeOptions())
                            ->default(Debt::TYPE_UTANG)
                            ->required()
                            // Select native HTML memakai key opsi apa adanya;
                            // rule `in` menjaga nilai tetap utang/piutang walau
                            // request dimanipulasi (kolom enum di DB hanya
                            // berfungsi di PostgreSQL, sedangkan test memakai
                            // SQLite).
                            ->native(false)
                            ->rules(['in:'.implode(',', array_keys(Debt::typeOptions()))])
                            ->helperText('Pilih Utang bila Anda meminjam, Piutang bila Anda meminjamkan.')
                            ->columnSpan(1),

                        TextInput::make('counterparty_name')
                            ->label('Nama Pihak')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('cth. Supplier Beras / Budi')
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
                            ->placeholder('cth. 1500000')
                            ->columnSpan(1),

                        DatePicker::make('due_date')
                            ->label('Jatuh Tempo')
                            ->nullable()
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->helperText('Opsional — kosongkan bila tidak ada tenggat.')
                            ->columnSpan(1),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->nullable()
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder('cth. Pinjaman modal usaha untuk kulakan')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
