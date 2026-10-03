<?php

namespace App\Filament\Pages;

use App\Exports\LaporanExpenseExport;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Widgets\LaporanCategoryChart;
use App\Filament\Widgets\LaporanStatsOverview;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
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
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as BaseCollection;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman Laporan: ringkasan, grafik, tabel, dan export PDF/Excel dari expense milik
 * user yang login sesuai filter periode & kategori. Pola Filament v4: filtersForm
 * live → pageFilters ke widget; tabel via InteractsWithTable.
 */
class Laporan extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.laporan';

    /** State form filter; nama properti WAJIB "filters" agar widget ter-embed otomatis menerimanya sebagai $pageFilters. */
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

    /** Nilai default filter: mode rentang tanggal (semua periode), tanpa kategori terpilih. */
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
     * Tabel transaksi hasil filter. Sort default created_at (bukan date_shopping,
     * yang bisa NULL dan NULLS-FIRST berbeda antar driver database).
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
                    ->color(fn (mixed $record) => auth()->user() instanceof User
                        ? $record->category?->displayColorFor(auth()->user()) ?? 'gray'
                        : $record->category?->color ?? 'gray')
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
                ->action(fn (): BinaryFileResponse => $this->exportExcel()),

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
     * Query expense hasil filter — sumber kebenaran tabel, widget, dan export.
     *
     * 'category' beserta override tampilan milik user login ikut eager-load:
     * kolom Kategori di tabel dan breakdown PDF sama-sama memanggil
     * displayColorFor(), jadi tanpa eager-load tiap kategori memicu satu query
     * ke category_appearance_overrides (N+1).
     */
    public function filteredExpensesQuery(): Builder
    {
        return $this->reportFilter()
            ->apply(Expense::query()->whereNotNull('amount'))
            ->with([
                'category' => fn (Builder|Relation $query): Builder|Relation => $query
                    ->withAppearanceOverridesFor(auth()->id()),
            ]);
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
     * Batas baris DETAIL yang dicetak di PDF.
     *
     * Ringkasan (total, jumlah transaksi, rata-rata) dan breakdown kategori
     * TIDAK terpengaruh batas ini — keduanya dihitung dari agregat SQL atas
     * SELURUH data hasil filter, bukan dari baris yang dicetak. Batas ini
     * hanya menjaga supaya PDF tidak memblenderi ribuan baris (yang membuat
     * dompdf berat dan file bisa membesar tak terkendali), dengan catatan
     * truncation yang tercetak jelas di PDF.
     *
     * Export Excel tidak dibatasi: file .xlsx di-stream jauh lebih ringan dan
     * pengguna yang butuh semua baris tetap bisa mendapatkannya lewat sana.
     */
    private const PDF_DETAIL_ROW_LIMIT = 1000;

    /**
     * Export PDF via dompdf; memakai StreamedResponse agar andal dikirim dari aksi Livewire.
     *
     * Baris detail dibatasi PDF_DETAIL_ROW_LIMIT, sedangkan ringkasan tetap
     * dihitung dari seluruh data (lihat summarizeFromQuery()).
     */
    public function exportPdf(): StreamedResponse
    {
        $query = $this->orderedExportQuery();

        // Jumlah baris SESUNGGUHNYA hasil filter — dipakai untuk ringkasan
        // dan catatan truncation, dihitung dari SQL (bukan dari baris yang
        // dicetak) supaya angka di PDF tetap jujur meski baris dipotong.
        $totalRowCount = $this->countFilteredExpenses();

        $pdf = $this->buildPdfDocument(
            $query->limit(self::PDF_DETAIL_ROW_LIMIT)->get(),
            $totalRowCount,
        );

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->exportFilename('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** Jumlah seluruh expense hasil filter — satu COUNT di SQL, bukan count() collection. */
    private function countFilteredExpenses(): int
    {
        return (int) $this->filteredExpensesQuery()
            ->toBase()
            ->count();
    }

    /**
     * Susun dokumen PDF laporan (kop, ringkasan, breakdown kategori, daftar transaksi)
     * dari expense ter-filter & ter-urut; method terpisah agar bisa diuji langsung.
     *
     * @param  Collection<int, Expense>  $expenses  Baris detail yang DICETAK (sudah mungkin dipotong).
     * @param  int|null  $totalRowCount  Jumlah seluruh baris hasil filter; null = semua baris tercetak.
     */
    public function buildPdfDocument(Collection $expenses, ?int $totalRowCount = null): \Barryvdh\DomPDF\PDF
    {
        $totalRowCount ??= $expenses->count();

        return Pdf::loadView('exports.laporan-pdf', [
            'periodLabel' => $this->reportFilter()->periodLabel(),
            'userName' => auth()->user()?->name ?? '—',
            'generatedAt' => now()->format('d/m/Y H:i'),
            'expenses' => $expenses,
            // Ringkasan & breakdown dihitung dari agregat SQL atas SELURUH data
            // hasil filter — bukan dari $expenses yang mungkin dipotong.
            'summary' => $this->summarizeFromQuery(),
            'categoryBreakdown' => $this->categoryBreakdown($expenses),
            'detailLimit' => self::PDF_DETAIL_ROW_LIMIT,
            'isTruncated' => $expenses->count() < $totalRowCount,
            'totalRowCount' => $totalRowCount,
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
     *
     * Memakai Excel::download() (BinaryFileResponse yang di-stream dari
     * temporary file) bukan Excel::raw() + print(): laporan pengeluaran
     * bisa berisi ribuan baris, dan raw() akan menahan seluruh file .xlsx
     * sebagai satu string di memori PHP. Isi file tidak berubah.
     */
    public function exportExcel(): BinaryFileResponse
    {
        return Excel::download(
            new LaporanExpenseExport($this->orderedExportQuery()),
            $this->exportFilename('xlsx'),
            ExcelWriter::XLSX,
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
     * Ringkasan angka (total, jumlah, rata-rata) expense hasil filter.
     *
     * Dihitung dari SATU query agregat SQL atas SELURUH data hasil filter —
     * bukan dari collection baris detail. Ini penting karena baris detail PDF
     * dibatasi 1000 baris: kalau ringkasan dijumlahkan dari collection itu,
     * total di PDF akan ikut terpotong dan menjadi tidak jujur.
     *
     * @return array{total: float, count: int, average: float}
     */
    private function summarizeFromQuery(): array
    {
        $amount = $this->filteredExpensesQuery()->getModel()->qualifyColumn('amount');

        $aggregate = $this->filteredExpensesQuery()
            ->toBase()
            ->reorder()
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->selectRaw("COALESCE(SUM({$amount}), 0) AS aggregate_total")
            ->first();

        $total = (float) $aggregate->aggregate_total;
        $count = (int) $aggregate->aggregate_count;

        return [
            'total' => $total,
            'count' => $count,
            'average' => $count > 0 ? $total / $count : 0.0,
        ];
    }

    /**
     * Breakdown pengeluaran per kategori (nama, warna, jumlah, total) untuk PDF.
     *
     * Sama seperti ringkasan, dihitung dari SQL GROUP BY atas SELURUH data
     * hasil filter (bukan dari baris detail yang mungkin dipotong), lalu nama
     * & warna tiap kategori diambil dalam satu query kategori + satu query
     * override — bukan satu query per kategori (N+1).
     *
     * @param  Collection<int, Expense>  $detailRows  Baris detail yang sudah di-eager-load kategori.
     * @return BaseCollection<int, array{name: string, color: string, count: int, total: float}>
     */
    private function categoryBreakdown(Collection $detailRows): BaseCollection
    {
        $model = $this->filteredExpensesQuery()->getModel();
        $amount = $model->qualifyColumn('amount');
        $categoryId = $model->qualifyColumn('category_id');

        $rows = $this->filteredExpensesQuery()
            ->toBase()
            ->reorder()
            ->selectRaw("{$categoryId} AS category_id")
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->selectRaw("COALESCE(SUM({$amount}), 0) AS aggregate_total")
            ->groupBy($categoryId)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $categories = $this->categoriesForBreakdown(
            $rows->pluck('category_id')->filter()->unique()->values(),
            $detailRows,
        );

        return $rows
            ->map(function (object $row) use ($categories): array {
                $category = $row->category_id !== null
                    ? $categories->get((int) $row->category_id)
                    : null;

                return [
                    'name' => $category?->name ?? 'Tanpa Kategori',
                    'color' => auth()->user() instanceof User
                        ? $category?->displayColorFor(auth()->user()) ?? '#CBD5E1'
                        : $category?->color ?? '#CBD5E1',
                    'count' => (int) $row->aggregate_count,
                    'total' => (float) $row->aggregate_total,
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Map kategori untuk breakdown: nama + override milik user login.
     *
     * Memakai ULANG kategori yang sudah eager-load pada $detailRows, lalu
     * hanya mengambil kategori yang belum ada di sana. Gunanya: breakdown
     * dihitung dari SELURUH data (bisa memuat kategori yang tidak lagi ada di
     * baris detail bila detail dipotong), sementara query tambahan tetap
     * maksimal satu — bukan satu per kategori.
     *
     * @param  BaseCollection<int, int>  $categoryIds  Semua kategori id dari agregat SQL.
     * @param  Collection<int, Expense>  $detailRows  Baris detail (sudah eager-load `category`).
     * @return BaseCollection<int, Category>
     */
    private function categoriesForBreakdown(BaseCollection $categoryIds, Collection $detailRows): BaseCollection
    {
        // Pastikan override milik user login sudah ter-muat untuk kategori
        // mana pun yang sudah ada di baris detail (biar displayColorFor() tidak
        // memicu query satu per kategori).
        $this->loadAppearanceOverridesFor($detailRows);

        $loaded = $detailRows
            ->map(fn (Expense $expense): ?Category => $expense->category)
            ->filter()
            ->keyBy('id');

        $missing = $categoryIds->reject(fn (int $id): bool => $loaded->has($id));

        if ($missing->isEmpty()) {
            return $loaded;
        }

        return $loaded->merge(
            Category::query()
                ->whereIn('id', $missing->values()->all())
                ->withAppearanceOverridesFor(auth()->id())
                ->get()
                ->keyBy('id')
        );
    }

    /**
     * Pastikan setiap kategori di $expenses punya relasi appearanceOverrides
     * milik user login yang SUDAH dimuat — satu query untuk seluruh kategori.
     *
     * Kalau relasi category belum eager-load sama sekali, kategori diambil
     * satu query (jumlah kategori berbeda), lalu override-nya satu query lagi.
     * Dipisah agar jelas mana yang lazy-load dan mana yang sudah eager.
     *
     * @param  Collection<int, Expense>  $expenses
     */
    private function loadAppearanceOverridesFor(Collection $expenses): void
    {
        // Jalur normal: filteredExpensesQuery() sudah eager-load kategori
        // beserta override-nya, jadi tidak ada yang perlu diunduh lagi.
        if (! $expenses->contains(
            fn (Expense $expense): bool => $expense->category_id !== null
                && ! $expense->relationLoaded('category')
        )) {
            return;
        }

        // Relasi category belum eager-load: ambil kategorinya sekaligus (1 query)
        // lengkap dengan override milik user login (1 query), bukan satu per
        // kategori seperti sebelumnya.
        $categories = Category::query()
            ->whereIn('id', $expenses->pluck('category_id')->filter()->unique()->values()->all())
            ->withAppearanceOverridesFor(auth()->id())
            ->get()
            ->keyBy('id');

        if ($categories->isEmpty()) {
            return;
        }

        // Pasang kategori yang sudah ter-eager-load ke setiap expense supaya
        // displayColorFor() tidak perlu query lagi.
        $expenses->each(function (Expense $expense) use ($categories): void {
            $category = $categories->get($expense->category_id);

            if ($category !== null) {
                $expense->setRelation('category', $category);
            }
        });
    }

    /** Opsi kategori filter: default sistem (user_id NULL) + milik user login — pola sama dengan seluruh panel. */
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

    /** Opsi tahun "Bulan Tertentu": 5 tahun ke belakang s/d tahun depan, dibangkitkan dinamis. */
    private function yearOptions(): array
    {
        $years = range(
            (int) now()->subYears(5)->format('Y'),
            (int) now()->addYear()->format('Y'),
        );

        return array_combine($years, $years);
    }
}
