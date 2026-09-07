<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Pages\ViewCategory;
use App\Models\Budget;
use App\Models\Category;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ColorEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

use function Filament\Support\generate_icon_html;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static \UnitEnum|string|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    /** Global Search mencocokkan kategori lewat namanya. */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    /** Detail Global Search: jumlah transaksi; pakai expenses_count (withCount) bila tersedia agar hemat query. */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Jumlah Transaksi' => (string) ($record->expenses_count ?? $record->expenses()->count()),
        ];
    }

    /** Pilihan [ikon Heroicon, label] kategori, termasuk kebutuhan UMKM; nama case
     * wajib valid di enum Heroicon — typo membuat ikon tidak dirender.
     *
     * @var array<int, array{0: Heroicon, 1: string}>
     */
    private const ICON_CHOICES = [
        [Heroicon::OutlinedShoppingCart, 'Makanan & Minuman'],
        [Heroicon::OutlinedTruck, 'Transportasi & Bensin'],
        [Heroicon::OutlinedShoppingBag, 'Belanja'],
        [Heroicon::OutlinedHeart, 'Kesehatan'],
        [Heroicon::OutlinedFilm, 'Hiburan'],
        [Heroicon::OutlinedBanknotes, 'Keuangan'],
        [Heroicon::OutlinedHomeModern, 'Rumah Tangga'],
        [Heroicon::OutlinedWrenchScrewdriver, 'Perbaikan'],
        [Heroicon::OutlinedAcademicCap, 'Pendidikan'],
        [Heroicon::OutlinedGift, 'Hadiah'],
        [Heroicon::OutlinedEllipsisHorizontal, 'Lainnya'],

        // Utilitas rumah tangga: listrik, air, gas, internet, pulsa, iuran
        // lingkungan, sewa, dan langganan — kategori bulanan yang paling
        // sering dicari lewat kolom pencarian ikon.
        [Heroicon::OutlinedBolt, 'Listrik'],
        [Heroicon::OutlinedBeaker, 'Air & PDAM'],
        [Heroicon::OutlinedFire, 'Gas & LPG'],
        [Heroicon::OutlinedWifi, 'Internet & Wifi'],
        [Heroicon::OutlinedSignal, 'Kuota & Sinyal'],
        [Heroicon::OutlinedPhone, 'Telepon & Pulsa'],
        [Heroicon::OutlinedDevicePhoneMobile, 'Pulsa & Paket Data'],
        [Heroicon::OutlinedShieldCheck, 'Iuran & Keamanan'],
        [Heroicon::OutlinedHome, 'Rumah & Sewa'],
        [Heroicon::OutlinedTv, 'TV & Langganan'],

        // Ikon tambahan untuk kebutuhan UMKM & gaya hidup.
        [Heroicon::OutlinedBuildingStorefront, 'Toko & UMKM'],
        [Heroicon::OutlinedReceiptPercent, 'Pajak & Retribusi'],
        [Heroicon::OutlinedCake, 'Pesta & Ulang Tahun'],
    ];

    /** Opsi Select ikon: kunci = value Heroicon (kolom `categories.icon`), nilai = pratinjau SVG + label (allowHtml). */
    public static function iconOptions(): array
    {
        $options = [];

        foreach (self::ICON_CHOICES as [$icon, $label]) {
            $preview = generate_icon_html($icon, size: IconSize::Small)?->toHtml() ?? '';

            $options[$icon->value] = trim($preview.' '.$label);
        }

        return $options;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            // Field form dibungkus satu Section ber-icon (pola sama dengan
            // ExpenseForm) agar Create/Edit Kategori tampil sebagai card rapi,
            // bukan deretan field polos.
            ->components([
                Section::make('Informasi Kategori')
                    ->icon(Heroicon::OutlinedTag)
                    ->iconColor('success')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Nama Kategori')
                            ->placeholder('cth. Makanan & Minuman')
                            ->columnSpanFull(),

                        Select::make('icon')
                            ->label('Ikon')
                            ->options(self::iconOptions())
                            ->searchable()
                            ->allowHtml()
                            ->placeholder('Pilih ikon')
                            ->columnSpan(1),

                        ColorPicker::make('color')
                            ->label('Warna')
                            ->columnSpan(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('icon')
                    ->label('Ikon')
                    ->icon(fn (string $state): ?Heroicon => filled($state) ? Heroicon::tryFrom($state) : Heroicon::OutlinedTag)
                    ->color('primary'),

                TextColumn::make('name')
                    ->label('Nama Kategori')
                    ->searchable()
                    ->sortable(),

                // Swatch warna visual (ColorColumn) — user langsung melihat
                // warna aslinya tanpa membayangkan dari kode hex. Klik swatch
                // menyalin kode warna ke clipboard.
                ColorColumn::make('color')
                    ->label('Warna')
                    ->tooltip(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null)
                    ->copyable()
                    ->copyMessage('Kode warna disalin')
                    ->placeholder('—'),

                // Kode hex tetap ditampilkan sebagai teks di samping swatch.
                // Catatan: ColorColumn hanya merender kotak warna (tanpa teks),
                // dan kolom Filament di-index per nama sehingga tidak boleh ada
                // dua kolom bernama "color" — makanya dipakai nama pseudo
                // "color_hex" yang state-nya diisi manual dari kolom color.
                TextColumn::make('color_hex')
                    ->label('Kode Hex')
                    ->getStateUsing(fn (Category $record): string => filled($record->color)
                        ? strtoupper($record->color)
                        : '—')
                    ->color('gray'),

                TextColumn::make('expenses_count')
                    ->label('Jumlah Transaksi')
                    ->counts('expenses')
                    ->sortable()
                    ->alignEnd(),
            ])
            // Aksi baris & bulk konsisten dengan tabel Expense: kategori bisa
            // dilihat detailnya (View), diedit, dan dihapus langsung dari
            // halaman list.
            ->defaultSort('name')
            // Kategori default sistem (user_id NULL) read-only: tombol Edit/Delete
            // disembunyikan; guard keras di getEdit/DeleteAuthorizationResponse().
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Category $record): bool => $record->user_id !== null),
                DeleteAction::make()
                    ->visible(fn (Category $record): bool => $record->user_id !== null),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // authorizeIndividualRecords(): record yang ter-seleksi
                    // difilter per-record lewat getDeleteAuthorizationResponse()
                    // (resolver default halaman untuk DeleteBulkAction) —
                    // kategori default yang ikut ter-seleksi DILEWATI, bukan
                    // ikut terhapus.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords(),
                ]),
            ]);
    }

    /**
     * Infolist halaman View. Statistik dihitung khusus user yang login — kategori
     * default dipakai lintas user, tanpa pembatasan ini angka akan tercampur.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            // Grid responsif: 1 kolom di mobile, 2 kolom mulai breakpoint md
            // (Filament ->columns(2) berarti ['lg' => 2], baru efektif >=1024px).
            ->columns(['default' => 1, 'md' => 2])
            ->components([
                Section::make('Informasi Kategori')
                    ->icon(Heroicon::OutlinedTag)
                    ->iconColor('success')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nama Kategori')
                            ->weight(FontWeight::SemiBold),
                        IconEntry::make('icon')
                            ->label('Ikon')
                            ->color('primary')
                            ->placeholder('—'),
                        ColorEntry::make('color')
                            ->label('Warna')
                            ->copyable()
                            ->copyMessage('Kode warna disalin')
                            ->placeholder('—'),
                        TextEntry::make('pemilik')
                            ->label('Pemilik')
                            ->state(fn (Category $record): string => $record->user?->name
                                ?? 'Default sistem (dipakai semua user)'),
                    ]),

                Section::make('Ringkasan Pengeluaran')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->iconColor('success')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('total_amount')
                            ->label('Total Pengeluaran')
                            ->state(fn (Category $record): ?string => MoneyFormatter::format(
                                $record->totalExpenseAmountForUser(Auth::id()),
                            ))
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->color('#10B981'),
                        TextEntry::make('transactions_count')
                            ->label('Jumlah Transaksi')
                            ->state(fn (Category $record): string => (string) $record->expenseCountForUser(Auth::id()))
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold),
                    ]),

                Section::make('5 Transaksi Terakhir')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->iconColor('success')
                    ->columnSpanFull()
                    ->schema([
                        // Tabel kecil (judul, tanggal, jumlah) dirender lewat
                        // blade custom — mekanisme yang sama dengan badge
                        // "Sedang diproses..." pada halaman View Expense.
                        Html::make(fn (Category $record): string => view('filament.categories.recent-expenses', [
                            'expenses' => $record->recentExpensesForUser(Auth::id()),
                        ])->render()),
                    ]),

                Section::make('Budget Aktif')
                    ->icon(Heroicon::OutlinedWallet)
                    ->iconColor('success')
                    ->columnSpanFull()
                    ->schema([
                        Html::make(function (Category $record): string {
                            $budget = $record->latestBudgetForUser(Auth::id());

                            if ($budget === null) {
                                return view('filament.categories.budget-progress', [
                                    'budget' => null,
                                ])->render();
                            }

                            $limit = (float) $budget->amount;
                            $spent = $budget->spentAmount();
                            // Persentase label boleh >100 (over budget tetap
                            // informatif); lebar batang di-clamp di blade.
                            $percent = $limit > 0
                                ? (int) round(($spent / $limit) * 100)
                                : ($spent > 0 ? 100 : 0);

                            return view('filament.categories.budget-progress', [
                                'budget' => $budget,
                                'spent' => $spent,
                                'limit' => $limit,
                                'percent' => $percent,
                                'period' => trim((Budget::monthOptions()[$budget->month] ?? $budget->month).' '.$budget->year),
                            ])->render();
                        }),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'view' => ViewCategory::route('/{record}'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }

    /**
     * Batasi kategori yang tampil: kategori default sistem (user_id NULL)
     * serta kategori milik user yang sedang login. Kategori milik user lain
     * tidak akan pernah muncul di daftar.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function (Builder $query): void {
                $query->whereNull('user_id');

                if (Auth::check()) {
                    $query->orWhere('user_id', Auth::id());
                }
            });
    }

    /**
     * Kategori default sistem (user_id NULL) read-only untuk semua user; kedua
     * method ini adalah satu-satunya titik otorisasi Filament v4 yang perlu
     * di-override (visibility action, hard-check halaman Edit, dan bulk delete).
     */
    public static function getEditAuthorizationResponse(Model $record): Response
    {
        if ($record->user_id === null) {
            return Response::deny('Kategori default sistem bersifat read-only dan tidak dapat diubah.');
        }

        return parent::getEditAuthorizationResponse($record);
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        if ($record->user_id === null) {
            return Response::deny('Kategori default sistem bersifat read-only dan tidak dapat dihapus.');
        }

        return parent::getDeleteAuthorizationResponse($record);
    }
}
