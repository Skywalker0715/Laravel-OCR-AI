<?php

namespace App\Filament\Resources\Incomes\Tables;

use App\Models\Income;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabel daftar catatan Pemasukan.
 *
 * Gaya kolom mengikuti resource lain di panel ini: sumber tegas (semi-bold),
 * nominal diformat MoneyFormatter ("Rp 5.000.000"), tanggal tampil "d M Y",
 * dan kolom timestamp tersembunyi secara default (bisa diaktifkan user).
 */
class IncomesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source')
                    ->label('Sumber')
                    ->searchable()
                    ->weight(FontWeight::SemiBold),

                TextColumn::make('amount')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (mixed $state): ?string => MoneyFormatter::format($state))
                    ->color('success')
                    ->sortable(),

                TextColumn::make('date_received')
                    ->label('Tanggal Diterima')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('notes')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->limit(50)
                    ->wrap(),

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
                Filter::make('date_received')
                    ->label('Rentang Tanggal Diterima')
                    ->schema([
                        DatePicker::make('received_from')
                            ->label('Dari'),
                        DatePicker::make('received_until')
                            ->label('Sampai'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['received_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('date_received', '>=', $date),
                            )
                            ->when(
                                $data['received_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('date_received', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['received_from'] ?? null) {
                            $indicators[] = Indicator::make('Diterima dari ' . Carbon::parse($data['received_from'])->format('d/m/Y'))
                                ->removeField('received_from');
                        }

                        if ($data['received_until'] ?? null) {
                            $indicators[] = Indicator::make('Diterima sampai ' . Carbon::parse($data['received_until'])->format('d/m/Y'))
                                ->removeField('received_until');
                        }

                        return $indicators;
                    }),
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
