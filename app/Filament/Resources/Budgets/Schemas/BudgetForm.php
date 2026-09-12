<?php

namespace App\Filament\Resources\Budgets\Schemas;

use App\Models\Budget;
use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class BudgetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Tata letak form Budget mengikuti pola ExpenseForm: seluruh field
            // dibungkus satu Section ber-icon agar Create/Edit Budget tampil
            // sebagai card yang rapi & konsisten dengan resource lain.
            ->columns(2)
            ->components([
                Section::make('Informasi Anggaran')
                    ->columnSpanFull()
                    ->icon(Heroicon::OutlinedWallet)
                    ->iconColor('success')
                    ->columns(2)
                    ->schema([
                        Select::make('category_id')
                            ->label('Kategori')
                            ->options(fn (): array => Category::query()
                                ->where(function (Builder $query): void {
                                    $query->whereNull('user_id')
                                          ->orWhere('user_id', Auth::id());
                                })
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder('Semua kategori')
                            ->columnSpan(1),
                            // Filter opsi kategori: hanya tampilkan kategori default
                            // sistem (user_id NULL) dan kategori milik user login.
                            // Pola sama dengan ExpenseForm — tanpa scope, model Category
                            // tanpa OwnedByUserScope akan menampilkan kategori milik user lain.

                        TextInput::make('amount')
                            ->label('Jumlah Anggaran')
                            ->required()
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0)
                            ->placeholder('cth. 2000000')
                            ->columnSpan(1),

                        Select::make('month')
                            ->label('Bulan')
                            ->options(Budget::monthOptions())
                            ->searchable()
                            ->required()
                            ->default(now()->month)
                            // Cegah duplikat: satu user hanya boleh punya satu
                            // anggaran untuk kombinasi kategori + bulan + tahun
                            // yang sama. Nilai tahun & kategori dibaca dari
                            // STATE FORM (bukan record lama) sehingga validasi
                            // tetap akurat saat user mengubahnya di form Edit.
                            ->scopedUnique(
                                modifyQueryUsing: function (Builder $query, Get $get): Builder {
                                    // Batasi eksplisit ke user yang sedang login
                                    // (lapisan tambahan di atas global scope
                                    // OwnedByUserScope pada model Budget) agar
                                    // budget milik user lain tidak dianggap
                                    // duplikat.
                                    $query->where('user_id', Auth::id());

                                    $query->where('year', (int) ($get('year') ?? 0));

                                    // Kategori NULL berarti anggaran umum
                                    // ("Semua kategori") — dua anggaran umum
                                    // pada periode sama juga dianggap duplikat.
                                    $categoryId = $get('category_id');

                                    return filled($categoryId)
                                        ? $query->where('category_id', $categoryId)
                                        : $query->whereNull('category_id');
                                },
                            )
                            // Rule duplikat dipasang pada field Bulan, jadi
                            // pesan errornya muncul tepat di bawah field ini.
                            ->validationMessages([
                                'unique' => 'Sudah ada anggaran untuk kategori dan periode (bulan + tahun) ini.',
                            ])
                            ->columnSpan(1),

                        TextInput::make('year')
                            ->label('Tahun')
                            ->required()
                            ->numeric()
                            ->minValue(2000)
                            ->maxValue(2100)
                            ->default(now()->year)
                            ->columnSpan(1),
                    ]),
            ]);
    }
}
