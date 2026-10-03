<?php

namespace App\Filament\Resources\Budgets\Tables;

use App\Models\Budget;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class BudgetsTable
{
    public static function configure(Table $table): Table
    {
        // Peta id budget -> nominal terpakai untuk SELURUH baris halaman ini,
        // dihitung lazily dengan SATU query agregat (Budget::spentAmountsFor).
        // Variabel ini milik instance tabel pada request berjalan, jadi hasil
        // agregat dihitung satu kali lalu dipakai ulang semua baris — menambah
        // baris tidak menambah query. Versi lama memanggil $record->spentAmount()
        // per baris (satu query per baris).
        $spentMap = null;

        $spentFor = function (Budget $record) use ($table, &$spentMap): float {
            if ($spentMap === null) {
                $records = $table->getRecords();

                $budgets = match (true) {
                    $records instanceof Paginator, $records instanceof CursorPaginator => $records->getCollection(),
                    default => $records,
                };

                $spentMap = Budget::spentAmountsFor(collect($budgets));
            }

            return (float) ($spentMap[$record->getKey()] ?? 0.0);
        };

        return $table
            // Kategori ikut eager-load override tampilan milik user login:
            // kolom Kategori memanggil displayColorFor() untuk tiap baris, jadi
            // tanpa eager-load tiap baris memicu satu query appearanceOverrides.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                // Tipe argumen nested eager-load adalah Builder ATAU Relation,
                // tergantung bentuk relasi — karena itu tipe keduanya.
                'category' => fn (Builder|Relation $categoryQuery): Builder|Relation => $categoryQuery
                    ->withAppearanceOverridesFor(auth()->id()),
            ]))
            ->columns([
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->badge()
                    ->color(fn (mixed $record) => auth()->user() instanceof User
                        ? $record->category?->displayColorFor(auth()->user()) ?? 'gray'
                        : $record->category?->color ?? 'gray')
                    ->placeholder('Semua kategori')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Anggaran')
                    ->formatStateUsing(fn (mixed $state): ?string => MoneyFormatter::format($state))
                    ->sortable(),

                TextColumn::make('period')
                    ->label('Periode')
                    ->badge()
                    ->color('info')
                    ->state(fn (Budget $record): string => trim(
                        (Budget::monthOptions()[$record->month] ?? $record->month).' '.$record->year
                    )),

                // Progress sederhana tanpa progress bar: badge "terpakai"
                // berisi nominal + persentase. Warna badge sebagai indikator
                // status: hijau aman, oranye mendekati batas, merah over.
                TextColumn::make('spent')
                    ->label('Terpakai')
                    ->state($spentFor)
                    ->formatStateUsing(function (mixed $state, Budget $record): string {
                        $percent = $record->amount > 0
                            ? (int) round(((float) $state / (float) $record->amount) * 100)
                            : 0;

                        return MoneyFormatter::format($state)
                            .' ('.$percent.'%)';
                    })
                    ->badge()
                    ->color(function (mixed $state, Budget $record): string {
                        $ratio = $record->amount > 0
                            ? (float) $state / (float) $record->amount
                            : 0.0;

                        return match (true) {
                            $ratio >= 1 => 'danger',
                            $ratio >= 0.75 => 'warning',
                            default => 'success',
                        };
                    }),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
