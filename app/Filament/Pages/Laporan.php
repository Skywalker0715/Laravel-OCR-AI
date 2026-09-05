<?php

namespace App\Filament\Pages;

use App\Exports\LaporanExpenseExport;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Widgets\LaporanCategoryChart;
use App\Filament\Widgets\LaporanStatsOverview;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Support\MoneyFormatter;
use App\Support\ReportFilter;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman Laporan: ringkasan, grafik, tabel, dan export (PDF/Excel) dari
 * pengeluaran milik user yang sedang login untuk periode & kategori yang
 * dipilih.
 *
 * Struktur mengikuti pola resmi Filament v4:
 *  - Form filter didefinisikan sebagai schema bernama "filtersForm"
 *    (sama seperti filtersForm Dashboard), di-embed via EmbeddedSchema,
 *    dan ber-status ->live() sehingga filter langsung diterapkan.
 *  - Widget ringkasan & grafik di-embed lewat getWidgetsSchemaComponents();
 *    karena halaman ini punya properti "filters", setiap widget otomatis
 *    menerima nilai filter terbaru via properti Livewire "pageFilters"
 *    (lihat trait InteractsWithPageFilters).
 *  - Tabel daftar transaksi didefinisikan lewat table() + InteractsWithTable
 *    dan di-embed via EmbeddedTable (pola sama seperti ListRecords) sehingga
 *    dapat diurutkan per kolom & ter-paginate.
 *
 * Satu query terpusat (filteredExpensesQuery()) menjadi sumber kebenaran
 * untuk tabel, widget, dan export agar angka selalu konsisten.
 */
class Laporan extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.laporan';

    /**
     * State form filter halaman (period_mode, date_from, date_until, month,
     * year, category_ids).
     *
     * Nama properti WAJIB "filters": getWidgetsSchemaComponents() otomatis
     * meneruskannya ke setiap widget yang di-embed sebagai properti Livewire
     * "pageFilters", sehingga widget ringkasan & grafik selalu mengikuti
     * filter terbaru tanpa wiring manual.
     *
     * @var array<string, mixed>|null
     */
    public ?array $filters = null;

    /**
     * Kolom yang boleh dipakai mengurutkan hasil export (allowlist keamanan:
     * nilai sort datang dari browser, jadi tidak boleh diteruskan mentah ke
     * orderBy()).
     */
    private const SORTABLE_EXPORT_COLUMNS = [
        'title',
        'vendor',
        'date_shopping',
        'amount',
        'created_at',
        'category.name',
    ];

    public function mount(): void
    {
        // Filter dipersist di session agar tetap ada saat halaman di-refresh —
        // perilaku sama seperti filter Dashboard Filament.
        if (! count($this->filters ?? [])) {
            $this->filters = session()->get($this->getFiltersSessionKey());
        }

        $this->getFiltersForm()->fill($this->filters ?? $this->defaultFilters());
    }

    public function updatedFilters(): void
    {
        // Simpan filter + kembalikan tabel ke halaman 1 setiap filter berubah,
        // agar user tidak melihat halaman kosong dari pagination filter lama.
        session()->put($this->getFiltersSessionKey(), $this->filters);

        $this->resetPage();
    }

    public function getFiltersSessionKey(): string
    {
        return md5(static::class).'_filters';

    }

    /**
     * Nilai default form filter: mode rentang tanggal dengan kedua tanggal
     * kosong (= semua periode) dan tanpa filter kategori.
     *
     * @return array<string, mixed>
     */
    private function defaultFilters(): array
    {
        return [
            'period_mode' => ReportFilter::MODE_RANGE,
            'date_from' => null,
            'date_until' => null,
            'month' => (int) now()->format('n'),
            'year' => (int) now()->format('Y'),
            'category_ids' => [],
        ];
    }

    /**
     * Form filter periode & kategori. ->live() membuat ringkasan, grafik,
     * tabel, dan export otomatis mengikuti setiap perubahan nilai (pola sama
     * dengan filtersForm Dashboard Filament v4).
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(5)
            ->live()
            ->statePath('filters')
            ->components([
                Section::make('Filter Laporan')
                    ->description('Pilih periode dan kategori; ringkasan, grafik, tabel, serta tombol export di bawah otomatis mengikuti filter ini.')
                    ->icon(Heroicon::OutlinedFunnel)
                    ->iconColor('success')
                    ->columnSpanFull()
                    ->columns(5)
                    ->schema([
                        Radio::make('period_mode')
                            ->label('Mode Periode')
                            ->options([
                                ReportFilter::MODE_RANGE => 'Rentang Tanggal',
                                ReportFilter::MODE_MONTH => 'Bulan Tertentu',
                            ])
                            ->default(ReportFilter::MODE_RANGE)
                            ->inline()
                            ->columnSpan(1),

                        DatePicker::make('date_from')
                            ->label('Dari Tanggal')
                            ->maxDate(fn (Get $get): ?string => $get('date_until'))
                            ->visible(fn (Get $get): bool => $get('period_mode') !== ReportFilter::MODE_MONTH)
                            ->columnSpan(1),

                        DatePicker::make('date_until')
                            ->label('Sampai Tanggal')
                            ->minDate(fn (Get $get): ?string => $get('date_from'))
                            ->visible(fn (Get $get): bool => $get('period_mode') !== ReportFilter::MODE_MONTH)
                            ->columnSpan(1),

                        Select::make('month')
                            ->label('Bulan')
                            ->options(Budget::monthOptions())
                            ->default((int) now()->format('n'))
                            ->visible(fn (Get $get): bool => $get('period_mode') === ReportFilter::MODE_MONTH)
                            ->columnSpan(1),

                        Select::make('year')
                            ->label('Tahun')
                            ->options($this->yearOptions())
                            ->default((int) now()->format('Y'))
                            ->visible(fn (Get $get): bool => $get('period_mode') === ReportFilter::MODE_MONTH)
                            ->columnSpan(1),

                        Select::make('category_ids')
                            ->label('Kategori')
                            ->options($this->categoryOptions())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->placeholder('Semua kategori')
                            ->columnSpan(2),
                    ]),
            ]);
    }

    public function getFiltersForm(): Schema
    {
        // getSchema() membangun & meng-cache schema "filtersForm" saat pertama
        // dipanggil (resolusi otomatis ke method filtersForm() di atas).
        $schema = $this->getSchema('filtersForm');

        return $schema ?? $this->filtersForm($this->makeSchema());
    }

    public function getFiltersFormContentComponent(): Component
    {
        return EmbeddedSchema::make('filtersForm');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFiltersFormContentComponent(),

                // Widget menerima $this->filters sebagai "pageFilters" —
                // lihat docblock properti $filters di atas.
                ...$this->getWidgetsSchemaComponents([
                    LaporanStatsOverview::class,
                    LaporanCategoryChart::class,
                ]),

                EmbeddedTable::make(),
            ]);
    }

    /**
     * Tabel daftar transaksi hasil filter. Semua kolom dapat diurutkan
     * (sortable) dan bisa dicari; default urutan terbaru berdasarkan
     * created_at — sengaja BUKAN date_shopping karena tanggal belanja bisa
     * NULL (struk yang parsing-nya belum selesai) dan perilaku NULLS
     * FIRST/LAST berbeda antara PostgreSQL dan MySQL (pola yang sama dengan
     * Category::recentExpensesForUser()).
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->filteredExpensesQuery())
            ->description('Daftar transaksi sesuai filter di atas — klik judul kolom untuk mengurutkan, klik baris untuk membuka detail transaksi.')
            ->columns([
                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('vendor')
                    ->label('Vendor')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('date_shopping')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->badge()
                    ->color(fn (mixed $record) => $record->category?->color ?? 'gray')
                    ->placeholder('Tanpa kategori')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (mixed $state): ?string => MoneyFormatter::format($state))
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->recordUrl(fn (Model $record): ?string => ExpenseResource::getUrl('view', ['record' => $record]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('danger')
                ->action(fn (): StreamedResponse => $this->exportPdf()),

            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('success')
                ->action(fn (): StreamedResponse => $this->exportExcel()),

            Action::make('resetFilters')
                ->label('Reset Filter')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->resetReportFilters()),
        ];
    }

    public function resetReportFilters(): void
    {
        $this->filters = $this->defaultFilters();
        $this->getFiltersForm()->fill($this->filters);
        $this->resetPage();

        session()->put($this->getFiltersSessionKey(), $this->filters);
    }

    /**
     * Filter halaman sebagai objek ReportFilter — dipakai ulang oleh query,
     * widget, dan export agar logika filter selalu identik.
     */
    public function reportFilter(): ReportFilter
    {
        return new ReportFilter($this->filters ?? []);
    }

    /**
     * Query expense hasil filter — SATU sumber kebenaran untuk tabel, widget,
     * dan export. Expense dengan amount NULL (struk yang parsing-nya belum
     * selesai) tidak ikut dihitung; kepemilikan data dijaga global scope
     * OwnedByUserScope pada model Expense.
     */
    public function filteredExpensesQuery(): Builder
    {
        return $this->reportFilter()
            ->apply(Expense::query()->whereNotNull('amount'))
            ->with('category');
    }

    /**
     * Query export: filter + urutan yang SAMA dengan tabel (kolom & arah sort
     * aktif), atau default terbaru bila user belum mengurutkan.
     */
    private function orderedExportQuery(): Builder
    {
        $query = $this->filteredExpensesQuery();

        $sortColumn = $this->getTableSortColumn();
        $sortDirection = $this->getTableSortDirection() === 'desc' ? 'desc' : 'asc';

        if ($sortColumn === null || ! in_array($sortColumn, self::SORTABLE_EXPORT_COLUMNS, true)) {
            return $query->orderBy('created_at', 'desc');
        }

        if ($sortColumn === 'category.name') {
            // Kolom relasi: urutkan lewat join agar hasil export sama dengan
            // pengurutan tabel di layar.
            return $query
                ->leftJoin('categories as category_sort', 'category_sort.id', '=', 'expenses.category_id')
                ->orderBy('category_sort.name', $sortDirection)
                ->select('expenses.*');
        }

        return $query->orderBy($sortColumn, $sortDirection);
    }

    /**
     * Export hasil laporan ke PDF via barryvdh/laravel-dompdf.
     *
     * Mengembalikan StreamedResponse (bukan unduhan biasa) karena pola ini
     * paling andal dikirim dari aksi Livewire: file tetap ter-download walau
     * output buffering bawaan Livewire aktif.
     */
    public function exportPdf(): StreamedResponse
    {
        $pdf = $this->buildPdfDocument($this->orderedExportQuery()->get());

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->exportFilename('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Susun dokumen PDF laporan (kop, ringkasan, breakdown kategori, dan
     * daftar transaksi) dari expense yang sudah ter-filter & ter-urut.
     * Method terpisah agar bisa diuji langsung pada test.
     *
     * @param  Collection<int, Expense>  $expenses
     */
    public function buildPdfDocument(Collection $expenses): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('exports.laporan-pdf', [
            'periodLabel' => $this->reportFilter()->periodLabel(),
            'userName' => auth()->user()?->name ?? '—',
            'generatedAt' => now()->format('d/m/Y H:i'),
            'expenses' => $expenses,
            'summary' => $this->summarize($expenses),
            'categoryBreakdown' => $this->categoryBreakdown($expenses),
        ])
            // Paper A4 portrait ditetapkan eksplisit agar layout tidak tergantung
            // config default package. Enam kolom rincian masih lega di portrait;
            // bila kelak kolom rincian ditambah (mis. item per struk), ganti
            // menjadi setPaper('a4', 'landscape').
            ->setPaper('a4', 'portrait');
    }

    /**
     * Export hasil laporan ke Excel (.xlsx) via maatwebsite/excel.
     * Isi file = query yang sama dengan tabel di layar (filter + urutan).
     */
    public function exportExcel(): StreamedResponse
    {
        $content = (string) Excel::raw(new LaporanExpenseExport($this->orderedExportQuery()), ExcelWriter::XLSX);

        return response()->streamDownload(
            fn () => print ($content),
            $this->exportFilename('xlsx'),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Nama file export yang deskriptif, mengikuti periode filter aktif.
     */
    private function exportFilename(string $extension): string
    {
        [$from, $until] = $this->reportFilter()->period();

        $suffix = 'semua-periode';

        if ($from !== null || $until !== null) {
            $suffix = ($from?->format('Ymd') ?? 'awal').'-'.($until?->format('Ymd') ?? 'sekarang');
        }

        return "laporan-pengeluaran-{$suffix}.{$extension}";
    }

    /**
     * Ringkasan angka (total, jumlah, rata-rata) dari kumpulan expense hasil
     * filter — dipakai oleh PDF dan bisa diuji langsung.
     *
     * @param  Collection<int, Expense>  $expenses
     * @return array{total: float, count: int, average: float}
     */
    private function summarize(Collection $expenses): array
    {
        $total = (float) $expenses->sum('amount');
        $count = $expenses->count();

        return [
            'total' => $total,
            'count' => $count,
            'average' => $count > 0 ? $total / $count : 0.0,
        ];
    }

    /**
     * Breakdown pengeluaran per kategori (nama, warna, jumlah transaksi,
     * total) untuk tabel breakdown di PDF.
     *
     * @param  Collection<int, Expense>  $expenses
     * @return BaseCollection<int, array{name: string, color: string, count: int, total: float}>
     */
    private function categoryBreakdown(Collection $expenses): BaseCollection
    {
        return $expenses
            ->groupBy(fn (Expense $expense): int => (int) $expense->category_id)
            ->map(fn (Collection $group): array => [
                'name' => $group->first()->category?->name ?? 'Tanpa Kategori',
                'color' => $group->first()->category?->color ?? '#CBD5E1',
                'count' => $group->count(),
                'total' => (float) $group->sum('amount'),
            ])
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Kategori yang bisa dipilih pada filter: kategori default sistem
     * (user_id NULL) ditambah kategori milik user yang sedang login — pola
     * yang sama dengan pemakaian kategori di seluruh panel.
     *
     * @return array<int, string>
     */
    private function categoryOptions(): array
    {
        return Category::query()
            ->where(
                fn (Builder $query): Builder => $query
                    ->whereNull('user_id')
                    ->orWhere('user_id', auth()->id()),
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Opsi tahun untuk mode "Bulan Tertentu": lima tahun terakhir s/d tahun
     * depan, dibangkitkan dinamis agar template tetap relevan bertahun-tahun.
     *
     * @return array<int, int>
     */
    private function yearOptions(): array
    {
        $years = range(
            (int) now()->subYears(5)->format('Y'),
            (int) now()->addYear()->format('Y'),
        );

        return array_combine($years, $years);
    }
}
