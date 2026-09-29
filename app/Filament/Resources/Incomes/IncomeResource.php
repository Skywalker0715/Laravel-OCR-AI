<?php

namespace App\Filament\Resources\Incomes;

use App\Filament\Resources\Incomes\Pages\CreateIncome;
use App\Filament\Resources\Incomes\Pages\EditIncome;
use App\Filament\Resources\Incomes\Pages\ListIncomes;
use App\Filament\Resources\Incomes\Pages\ViewIncome;
use App\Filament\Resources\Incomes\Schemas\IncomeForm;
use App\Filament\Resources\Incomes\Schemas\IncomeInfolist;
use App\Filament\Resources\Incomes\Tables\IncomesTable;
use App\Models\Income;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Resource admin panel untuk pencatatan PEMASUKAN (uang masuk).
 *
 * Terpisah total dari Resource Expenses — tidak ada file di
 * app/Filament/Resources/Expenses/ yang disentuh. Gaya navigasi, form,
 * tabel, dan infolist mengikuti pola umum resource lain di panel ini
 * (Budget/Category/Debt): warna, spacing, dan komponen Filament bawaan.
 *
 * Isolasi per-user ditangani global scope OwnedByUserScope pada model
 * Income, jadi route binding (view/edit/delete) otomatis hanya menemukan
 * catatan milik user yang sedang login.
 */
class IncomeResource extends Resource
{
    protected static ?string $model = Income::class;

    // Ikon panah naik = arus masuk; berbeda dari Banknotes (Expenses),
    // Tag (Categories), Wallet (Budgets), dan Scale (Utang Piutang).
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    // Tipe mengikuti parent (UnitEnum|string|null) karena properti statis
    // wajib invariant; nilai tetap string 'Keuangan'.
    protected static \UnitEnum|string|null $navigationGroup = 'Keuangan';

    // Urutan sidebar setelah Expenses (1), Categories (2), Budgets (3),
    // dan Utang Piutang (4) — tanpa mengubah navigationSort resource lain.
    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Pemasukan';

    protected static ?string $modelLabel = 'Pemasukan';

    protected static ?string $pluralModelLabel = 'Pemasukan';

    /**
     * Badge sidebar: jumlah catatan pemasukan milik user login (semua waktu),
     * konsisten dengan badge pada menu Expenses.
     */
    public static function getNavigationBadge(): ?string
    {
        return (string) Income::query()->count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'info';
    }

    /** Global Search mencocokkan sumber pemasukan + catatan. */
    public static function getGloballySearchableAttributes(): array
    {
        return ['source', 'notes'];
    }

    /** Detail hasil Global Search: sumber, tanggal diterima, dan nominal. */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Sumber' => (string) $record->source,
            'Tanggal Diterima' => $record->date_received instanceof \DateTimeInterface
                ? Carbon::instance($record->date_received)->format('d M Y')
                : '—',
            'Jumlah' => MoneyFormatter::format($record->amount) ?? '—',
        ];
    }

    /**
     * Resource ini memakai judul record kustom (sumber pemasukan), bukan
     * kolom judul khusus — beri tahu Filament agar breadcrumb/hasil global
     * search memakai getRecordTitle() di bawah.
     */
    public static function hasRecordTitle(): bool
    {
        return true;
    }

    /**
     * Judul record, mis. "Pemasukan — Gaji", karena Income tidak punya
     * kolom judul sendiri (pola sama dengan judul kustom Debt/Budget).
     */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if ($record === null) {
            return static::getModelLabel();
        }

        return sprintf('Pemasukan — %s', $record->source);
    }

    public static function form(Schema $schema): Schema
    {
        return IncomeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return IncomeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IncomesTable::configure($table);
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
            'index' => ListIncomes::route('/'),
            'create' => CreateIncome::route('/create'),
            'view' => ViewIncome::route('/{record}'),
            'edit' => EditIncome::route('/{record}/edit'),
        ];
    }

    /**
     * Query dasar seluruh halaman resource. Isolasi per-user ditangani
     * global scope OwnedByUserScope pada model Income, jadi list, view,
     * edit, maupun delete otomatis hanya menyentuh data milik user login.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}
