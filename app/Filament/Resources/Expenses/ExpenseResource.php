<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\Pages\ViewExpenseItems;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static \UnitEnum|string|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * Badge jumlah SELURUH transaksi expense milik user (semua waktu), muncul
     * di menu sidebar.
     *
     * Tidak ada filter periode (bulan/tahun berjalan) di sini — filter tersebut
     * hanya akan diterapkan di halaman Laporan ke depannya. Query memakai model
     * Expense yang ter-scope per-user lewat OwnedByUserScope, jadi angka ini
     * selalu milik user yang sedang login.
     */
    public static function getNavigationBadge(): ?string
    {
        return (string) Expense::query()->count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'success';
    }

    /**
     * Atribut yang dicari oleh Global Search (search bar di atas panel):
     * judul belanja dan nama vendor/toko pada struk.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'vendor'];
    }

    /**
     * Detail tambahan pada tiap hasil Global Search Expense agar preview
     * lebih informatif: vendor asal struk, tanggal belanja, dan total.
     * Format Rupiah mengikuti konvensi tampilan di tabel & infolist.
     *
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Vendor' => filled($record->vendor) ? $record->vendor : '—',
            'Tanggal Belanja' => filled($record->date_shopping)
                ? Carbon::parse($record->date_shopping)->format('d M Y')
                : '—',
            'Total' => MoneyFormatter::format($record->amount) ?? '—',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    /**
     * Build the read-only infolist shown on the View Expense page.
     *
     * When this method returns a schema with components, Filament renders it on
     * the View page instead of the (mostly hidden) form, so every useful bit of
     * the record — vendor, shopping date, totals, kembalian, the receipt photo,
     * and the itemised list — becomes visible.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            // Layout grid 2 kolom di-set langsung pada objek Schema (pendekatan native
            // Filament, bukan membungkus Section lewat komponen Grid terpisah). Dengan
            // begini 4 Section berikut otomatis tersusun 2x2 dan masing-masing mengisi
            // penuh ~50% lebar konten (tidak menyisakan ruang kosong putih di kanan):
            //   Baris 1 : "Informasi Belanja" + "Ringkasan Pembayaran" (berdampingan)
            //   Baris 2 : "Foto Struk" + "Daftar Item Belanja" (berdampingan)
            ->columns(2)
            ->components([
                // Peringatan mismatch item vs Total: SUM(subtotal item) yang
                // tersimpan tidak cocok dengan kolom `amount` (Total) dan
                // selisihnya tidak dijelaskan diskon/PPN/biaya manapun —
                // hampir pasti karena OCR salah membaca salah satu item
                // (mis. dua baris item terbaca identik). Berbeda dari notice
                // fallback di bawahnya, banner ini muncul TERLEPAS dari jalur
                // parsing (AI maupun fallback regex) karena masalah salah
                // baca item bisa terjadi di kedua jalur.
                Section::make('Peringatan: jumlah item tidak cocok dengan Total')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->iconColor('warning')
                    ->secondary()
                    ->compact()
                    ->columnSpanFull()
                    ->visible(fn (Expense $record): bool => (bool) $record->items_mismatch)
                    ->schema([
                        TextEntry::make('items_mismatch_notice')
                            ->hiddenLabel()
                            ->state('Jumlah item tidak sama dengan Total — kemungkinan ada kesalahan baca OCR pada salah satu item, mohon periksa manual.')
                            ->color('warning')
                            ->weight(FontWeight::Medium),
                    ]),

                // Notice informasi kecil bila struk ini diproses oleh parser
                // FALLBACK REGEX (bukan AI Cohere) — ditandai flag
                // `used_fallback` di tabel expenses. Warna info (biru/abu-abu
                // netral) sengaja dipilih, bukan warning, agar terkesan
                // sebagai bagian normal dari alur kerja, bukan tanda error.
                Section::make('Catatan otomatis')
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->iconColor('info')
                    ->secondary()
                    ->compact()
                    ->columnSpanFull()
                    ->visible(fn (Expense $record): bool => (bool) $record->used_fallback)
                    ->schema([
                        TextEntry::make('used_fallback_notice')
                            ->hiddenLabel()
                            ->state('Data ini diproses otomatis oleh sistem OCR. Mohon cek sekilas kelengkapannya, dan koreksi lewat tombol Edit jika ada yang kurang pas.')
                            ->color('info')
                            ->weight(FontWeight::Medium),
                    ]),

                Section::make('Informasi Belanja')
                    ->columnSpan(1)
                    ->icon(Heroicon::OutlinedShoppingBag)
                    ->iconColor('success')
                    ->schema([
                        TextEntry::make('title')
                            ->label('Judul Belanja')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('vendor')
                            ->label('Vendor')
                            ->placeholder('—')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('date_shopping')
                            ->label('Tanggal Belanja')
                            ->placeholder('—'),
                    ])
                    ->columns(1),

                Section::make('Ringkasan Pembayaran')
                    ->columnSpan(1)
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->iconColor('success')
                    ->schema([
                        TextEntry::make('amount')
                            ->label('Total')
                            ->formatStateUsing(fn (TextEntry $entry, mixed $state): ?string => MoneyFormatter::format($state))
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->color('#10B981'),
                        TextEntry::make('change')
                            ->label('Kembalian')
                            ->placeholder('—')
                            ->formatStateUsing(fn (TextEntry $entry, mixed $state): ?string => MoneyFormatter::format($state))
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->color('#10B981'),
                    ])
                    ->columns(2),

                Section::make('Foto Struk')
                    ->icon(Heroicon::OutlinedPhoto)
                    ->iconColor('success')
                    ->columnSpan(1)
                    ->schema([
                        // Foto struk kini di disk privat 'receipts' (temuan
                        // audit #2) — URL gambar dibangun lewat route
                        // terotorisasi /receipt-image/{expense} (pemilik
                        // saja). ImageEntry meneruskan full-URL state apa
                        // adanya, jadi ->disk('public') tidak diperlukan lagi.
                        ImageEntry::make('receipt_image')
                            // ->hiddenLabel() menghapus total label field (baik
                            // "Foto Struk" maupun "Receipt image"), jadi hanya
                            // judul Section yang tampil, langsung diikuti gambar.
                            ->hiddenLabel()
                            ->state(fn (Expense $record) => filled($record->receipt_image) ? route('receipt-image.show', ['expense' => $record]) : null)
                            ->extraImgAttributes([
                                'style' => 'object-fit: contain; width: auto; max-width: 100%; height: auto; max-height: 500px; margin-inline: auto; border-radius: 0.75rem; border: 1px solid #e5e7eb;',
                            ]),
                    ]),

                Section::make('Daftar Item Belanja')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->iconColor('success')
                    ->columnSpan(1)
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label(null)
                            ->schema([
                                TextEntry::make('name')
                                    ->label('Nama Item')
                                    ->weight(FontWeight::Medium),
                                TextEntry::make('qty')
                                    ->label('Qty')
                                    ->formatStateUsing(fn (TextEntry $entry, mixed $state): ?string => MoneyFormatter::number($state)),
                                TextEntry::make('price')
                                    ->label('Harga')
                                    ->formatStateUsing(fn (TextEntry $entry, mixed $state): ?string => MoneyFormatter::format($state)),
                                TextEntry::make('subtotal')
                                    ->label('Subtotal')
                                    ->formatStateUsing(fn (TextEntry $entry, mixed $state): ?string => MoneyFormatter::format($state)),
                            ]),
                    ]),
    ]);
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
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
            'items' => ViewExpenseItems::route('/{record}/items'),
        ];
    }

    /**
     * Query dasar untuk seluruh halaman resource (list, view, edit, delete).
     *
     * Isolasi per-user ditangani oleh global scope OwnedByUserScope pada model
     * Expense: query apa pun — termasuk route binding untuk halaman view/edit
     * dan aksi delete/bulk delete — otomatis hanya memuat/menemukan expense
     * milik user yang sedang login. Record milik user lain tidak bisa diakses,
     * diedit, maupun dihapus oleh user selain pemiliknya.
     */
    public static function getEloquentQuery(): Builder
    {
        // The builder below is shared by the List (index), View, and ViewExpenseItems pages.
        //
        // `withCount('items')` computes each row's item count with one grouped query, so the
        // List page never triggers an N+1 per row. The View / ViewExpenseItems pages show items
        // for a single record only, so they hydrate the relation lazily with one extra query at
        // most (again no N+1). We deliberately do NOT add `with('items')` here: the index page
        // doesn't render the items relation, and eagerly hydrating every row's items on the
        // list would only add unused data/query time to the page users load most often.
        return parent::getEloquentQuery()->withCount(relations: ['items']);
    }
}
