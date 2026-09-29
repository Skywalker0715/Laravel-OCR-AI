<?php

namespace App\Filament\Resources\Debts;

use App\Filament\Resources\Debts\Pages\CreateDebt;
use App\Filament\Resources\Debts\Pages\EditDebt;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Filament\Resources\Debts\Pages\ViewDebt;
use App\Filament\Resources\Debts\Schemas\DebtForm;
use App\Filament\Resources\Debts\Schemas\DebtInfolist;
use App\Filament\Resources\Debts\Tables\DebtsTable;
use App\Models\Debt;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Resource admin panel untuk pencatatan Utang Piutang (fitur UMKM).
 *
 * Terpisah total dari Resource Expenses — tidak ada file di
 * app/Filament/Resources/Expenses/ yang disentuh.
 */
class DebtResource extends Resource
{
    protected static ?string $model = Debt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    // Tipe mengikuti parent (UnitEnum|string|null) karena properti statis
    // wajib invariant; nilai tetap string 'Keuangan'.
    protected static \UnitEnum|string|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Utang Piutang';

    protected static ?string $modelLabel = 'Utang Piutang';

    protected static ?string $pluralModelLabel = 'Utang Piutang';

    /**
     * Badge sidebar: jumlah catatan yang masih berjalan (belum lunas) milik
     * user login — angka ini yang biasanya perlu ditindaklanjuti.
     */
    public static function getNavigationBadge(): ?string
    {
        return (string) Debt::query()->active()->count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    /** Global Search mencocokkan nama pihak terkait + catatan. */
    public static function getGloballySearchableAttributes(): array
    {
        return ['counterparty_name', 'notes'];
    }

    /** Detail hasil Global Search: tipe, sisa tagihan, dan status. */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Tipe' => $record->typeLabel(),
            'Sisa' => MoneyFormatter::format($record->remainingAmount()) ?? 'Rp 0',
            'Status' => $record->statusLabel(),
        ];
    }

    /**
     * Resource ini memakai judul record kustom (tipe + nama pihak), bukan
     * kolom database — beri tahu Filament agar breadcrumb/hasil pencarian
     * memakai getRecordTitle() di atas.
     */
    public static function hasRecordTitle(): bool
    {
        return true;
    }

    /**
     * Judul record menggabungkan tipe + nama pihak, mis.
     * "Utang — Supplier Beras", karena Debt tidak punya kolom judul sendiri.
     */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if ($record === null) {
            return static::getModelLabel();
        }

        return sprintf('%s — %s', $record->typeLabel(), $record->counterparty_name);
    }

    public static function form(Schema $schema): Schema
    {
        return DebtForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DebtInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DebtsTable::configure($table);
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
            'index' => ListDebts::route('/'),
            'create' => CreateDebt::route('/create'),
            'view' => ViewDebt::route('/{record}'),
            'edit' => EditDebt::route('/{record}/edit'),
        ];
    }

    /**
     * Isolasi per-user ditangani global scope OwnedByUserScope pada model Debt,
     * jadi route binding (view/edit/delete) otomatis hanya menemukan catatan
     * milik user yang sedang login.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}
