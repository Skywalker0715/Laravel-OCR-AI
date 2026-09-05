<?php

namespace App\Filament\Resources\Budgets\Tables;

use App\Models\Budget;
use App\Support\MoneyFormatter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BudgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->badge()
                    ->color(fn (mixed $record) => $record->category?->color ?? 'gray')
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
                    ->state(fn (Budget $record): float => $record->spentAmount())
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
