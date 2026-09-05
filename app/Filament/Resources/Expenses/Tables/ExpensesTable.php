<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Models\Expense;
use App\Support\MoneyFormatter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->badge()
                    ->color(fn (mixed $record) => $record->category?->color ?? 'gray')
                    ->placeholder('Tanpa kategori')
                    ->sortable(),

                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('date_shopping')
                    ->date('Y-m-d')
                    ->searchable(),
                TextColumn::make('change')
                    ->label('Kembalian')
                    ->formatStateUsing(fn (mixed $state): ?string => MoneyFormatter::format($state))
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Total')
                    ->formatStateUsing(fn (mixed $state): ?string => MoneyFormatter::format($state))
                    ->sortable(),
                // Foto struk kini di disk privat 'receipts' (temuan audit #2)
                // — URL preview & link dibangun lewat route terotorisasi
                // /receipt-image/{expense} (pemilik saja). Vendor ImageColumn
                // meneruskan full-URL state apa adanya (lihat ImageColumn::
                // getImageUrl()), jadi ->disk('public') tidak diperlukan lagi.
                ImageColumn::make('receipt_image')
                    ->getStateUsing(fn (Expense $record) => filled($record->receipt_image) ? route('receipt-image.show', ['expense' => $record]) : null)
                    ->url(fn (Expense $record) => filled($record->receipt_image) ? route('receipt-image.show', ['expense' => $record]) : null)
                    ->square()
                    ->size(100),
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
                ViewAction::make(),
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
