<?php

namespace App\Filament\Resources\Debts\Tables;

use App\Filament\Resources\Debts\Actions\DebtActions;
use App\Models\Debt;
use App\Support\MoneyFormatter;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabel daftar catatan Utang Piutang.
 *
 * Kode warna sengaja konsisten di semua kolom:
 *  - Tipe   : Utang = merah (danger), Piutang = hijau (success)
 *  - Status : Belum Lunas = oranye (warning), Sebagian = biru (info),
 *             Lunas = hijau (success)
 *  - Sisa   : merah selama masih ada sisa, hijau bila sudah lunas
 *  - Jatuh tempo: merah bila sudah terlewat & belum lunas
 */
class DebtsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn (Debt $record): string => $record->typeLabel())
                    ->color(fn (Debt $record): string => $record->typeColor())
                    ->sortable(),

                TextColumn::make('counterparty_name')
                    ->label('Nama Pihak')
                    ->searchable()
                    ->weight(FontWeight::SemiBold),

                TextColumn::make('amount')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (mixed $state): ?string => MoneyFormatter::format($state))
                    ->sortable(),

                TextColumn::make('paid_amount')
                    ->label('Dibayar')
                    ->state(fn (Debt $record): float => (float) $record->paid_amount)
                    ->formatStateUsing(fn (mixed $state): ?string => MoneyFormatter::format($state))
                    ->description(fn (Debt $record): string => $record->paidPercent().'%')
                    ->color(fn (Debt $record): string => $record->paidPercent() > 0 ? 'info' : 'gray'),

                TextColumn::make('remaining')
                    ->label('Sisa')
                    ->state(fn (Debt $record): float => $record->remainingAmount())
                    ->formatStateUsing(fn (mixed $state): ?string => MoneyFormatter::format($state) ?? 'Rp 0')
                    ->badge()
                    ->color(fn (Debt $record): string => $record->isLunas() ? 'success' : 'danger'),

                TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (Debt $record): string => $record->isOverdue() ? 'danger' : 'gray')
                    ->description(fn (Debt $record): ?string => $record->isOverdue() ? 'Terlewat' : null)
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (Debt $record): string => $record->statusLabel())
                    ->color(fn (Debt $record): string => $record->statusColor())
                    ->sortable(),

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
                SelectFilter::make('type')
                    ->label('Tipe')
                    ->options(Debt::typeOptions()),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Debt::statusOptions()),

                Filter::make('due_date')
                    ->label('Rentang Jatuh Tempo')
                    ->schema([
                        DatePicker::make('due_from')
                            ->label('Jatuh Tempo Dari'),
                        DatePicker::make('due_until')
                            ->label('Jatuh Tempo Sampai'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['due_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('due_date', '>=', $date),
                            )
                            ->when(
                                $data['due_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('due_date', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['due_from'] ?? null) {
                            $indicators[] = Indicator::make('Jatuh tempo dari ' . Carbon::parse($data['due_from'])->format('d/m/Y'))
                                ->removeField('due_from');
                        }

                        if ($data['due_until'] ?? null) {
                            $indicators[] = Indicator::make('Jatuh tempo sampai ' . Carbon::parse($data['due_until'])->format('d/m/Y'))
                                ->removeField('due_until');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DebtActions::markAsPaid(),
                DebtActions::recordPayment(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
