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

    /**
     * Atribut yang dicari oleh Global Search (search bar di atas panel).
     *
     * Budget tidak punya kolom judul sendiri, jadi pencarian dilakukan pada
     * nama kategori terkait. Notasi titik ("category.name") otomatis
     * diterjemahkan Filament menjadi whereHas('category') sehingga hasilnya
     * hanya budget yang kategorinya cocok dengan kata kunci.
     *
     * @return array<string>
     */
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
     * Detail tambahan pada tiap hasil Global Search Budget: periode berlaku
     * dan nominal batas anggaran. Kategori sudah eager-load lewat
     * getEloquentQuery() sehingga judul di atas tidak memicu N+1 query.
     *
     * @return array<string, string>
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
     * Budget tidak punya kolom judul sendiri (seperti `title` pada Expense),
     * jadi `$recordTitleAttribute` tidak dipakai. Method ini dioverride untuk
     * tetap mengaktifkan "record title" agar judul & breadcrumb halaman Edit
     * informatif — konsisten dengan resource Expenses yang menampilkan judul
     * belanja di breadcrumb.
     */
    public static function hasRecordTitle(): bool
    {
        return true;
    }

    /**
     * Judul record Budget, mis. "Anggaran Makanan & Minuman — Januari 2026".
     * Dipakai pada judul & breadcrumb halaman Edit serta label aksi record di
     * tabel. Budget tanpa kategori (anggaran umum) ditampilkan sebagai
     * "Semua Kategori".
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
     * Query dasar seluruh halaman resource (list, create, edit, delete).
     *
     * Isolasi per-user ditangani oleh global scope OwnedByUserScope pada
     * model Budget. Eager load 'category' agar kolom kategori di tabel
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
