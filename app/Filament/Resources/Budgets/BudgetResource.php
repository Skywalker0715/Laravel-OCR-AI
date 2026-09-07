<?php

namespace App\Filament\Resources\Budgets;

use App\Filament\Resources\Budgets\Pages\CreateBudget;
use App\Filament\Resources\Budgets\Pages\EditBudget;
use App\Filament\Resources\Budgets\Pages\ListBudgets;
use App\Filament\Resources\Budgets\Schemas\BudgetForm;
use App\Filament\Resources\Budgets\Tables\BudgetsTable;
use App\Models\Budget;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BudgetResource extends Resource
{
    protected static ?string $model = Budget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    // Tipe mengikuti parent (UnitEnum|string|null) karena properti statis
    // wajib invariant; nilai tetap string 'Keuangan'.
    protected static \UnitEnum|string|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 3;

    /** Global Search via nama kategori (notasi "category.name" → whereHas); Budget tak punya kolom judul sendiri. */
    public static function getGloballySearchableAttributes(): array
    {
        return ['category.name'];
    }

    /**
     * Judul tiap hasil Global Search Budget. Karena budget diidentifikasi
     * oleh kombinasi kategori + periode, keduanya digabung menjadi judul
     * yang mudah dikenali, mis. "Anggaran Belanja — Januari 2026".
     */
    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        $categoryName = $record->category?->name ?? 'Semua Kategori';
        $period = trim((Budget::monthOptions()[$record->month] ?? $record->month).' '.$record->year);

        return sprintf('Anggaran %s — %s', $categoryName, $period);
    }

    /**
     * Detail hasil Global Search: periode & batas anggaran. Kategori sudah
     * eager-load di getEloquentQuery(), jadi ini tidak memicu N+1 query.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $period = trim((Budget::monthOptions()[$record->month] ?? $record->month).' '.$record->year);

        return [
            'Periode' => $period,
            'Batas Anggaran' => MoneyFormatter::format($record->amount),
        ];
    }

    /**
     * Override record title: Budget tak punya kolom judul, jadi judul & breadcrumb
     * dibangun dari kombinasi kategori + periode.
     */
    public static function hasRecordTitle(): bool
    {
        return true;
    }

    /**
     * Judul record, mis. "Anggaran Makanan & Minuman — Januari 2026"; budget umum
     * ditampilkan sebagai "Semua Kategori".
     */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if ($record === null) {
            return static::getModelLabel();
        }

        $categoryName = $record->category?->name ?? 'Semua Kategori';
        $period = trim((Budget::monthOptions()[$record->month] ?? $record->month).' '.$record->year);

        return sprintf('Anggaran %s — %s', $categoryName, $period);
    }

    /**
     * Query dasar resource; eager-load 'category' agar kolom kategori di tabel
     * tidak memicu N+1 query saat daftar anggaran dirender.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('category');
    }

    public static function form(Schema $schema): Schema
    {
        return BudgetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BudgetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBudgets::route('/'),
            'create' => CreateBudget::route('/create'),
            'edit' => EditBudget::route('/{record}/edit'),
        ];
    }
}
