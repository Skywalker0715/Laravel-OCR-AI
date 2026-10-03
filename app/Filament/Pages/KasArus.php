<?php

namespace App\Filament\Pages;

use App\Exports\KasArusExport;
use App\Filament\Widgets\KasArusChart;
use App\Filament\Widgets\KasArusStatsOverview;
use App\Models\Budget;
use App\Support\KasArusReport;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman "Kas Arus": ringkasan (Total Pemasukan, Total Pengeluaran, Saldo),
 * grafik batang Pemasukan vs Pengeluaran per bulan, tabel breakdown per bulan,
 * dan export PDF/Excel — semuanya mengikuti filter periode (rentang tanggal /
 * bulan tertentu) milik user yang login.
 *
 * Halaman BARU yang terpisah penuh dari Laporan: tidak ada satu pun kode di
 * app/Filament/Pages/Laporan.php maupun app/Filament/Resources/Expenses/ yang
 * dimodifikasi, dan export memakai class (KasArusExport) serta template PDF
 * (exports/kas-arus-pdf) sendiri.
 *
 * Pola Filament v4 sama dengan Laporan: filtersForm live → pageFilters ke
 * widget; ringkasan/tabel/export membaca satu sumber kebenaran KasArusReport.
 */
class KasArus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $navigationLabel = 'Kas Arus';

    /** Menu sidebar digabung ke grup "Keuangan" — sejajar dengan resource Pemasukan, Utang Piutang, dst. */
    protected static \UnitEnum|string|null $navigationGroup = 'Keuangan';

    protected static ?string $title = 'Kas Arus';

    /** Nilai sama dengan Laporan (90): urutan tampil sejajar/berurutan dengan menu Laporan. */
    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.kas-arus';

    /**
     * State form filter; nama properti WAJIB "filters" agar widget ter-embed
     * otomatis menerimanya sebagai $pageFilters (pola sama dengan Laporan).
     */
    public ?array $filters = null;

    /**
     * Instance KasArusReport yang dipakai selama satu request, plus sidik
     * filter yang dipakai saat instance itu dibuat.
     *
     * Sifatnya SENGJAJA tidak dipersist Livewire (private, bukan public):
     * tujuannya murni cache satu render, dan filter yang di-cache selalu
     * dicocokkan ulang lewat filtersSignature() supaya tidak pernah ada
     * laporan yang tampil memakai filter lama.
     */
    private ?KasArusReport $kasArusReportInstance = null;

    private ?string $kasArusReportSignature = null;

    public function mount(): void
    {
        // Filter dipersist di session agar tetap ada saat halaman di-refresh —
        // perilaku sama dengan halaman Laporan.
        if (! count($this->filters ?? [])) {
            $this->filters = session()->get($this->getFiltersSessionKey());
        }

        $this->getFiltersForm()->fill($this->filters ?? $this->defaultFilters());
    }

    public function updatedFilters(): void
    {
        // Simpan filter; render berikutnya otomatis memakai nilai terbaru.
        session()->put($this->getFiltersSessionKey(), $this->filters);

        $this->resetPage();
    }

    public function getFiltersSessionKey(): string
    {
        return md5(static::class).'_filters';
    }

    /** Nilai default filter: mode rentang tanggal tanpa batas (semua periode), konsisten dengan Laporan. */
    private function defaultFilters(): array
    {
        return [
            'period_mode' => ReportFilter::MODE_RANGE,
            'date_from' => null,
            'date_until' => null,
            'month' => (int) now()->format('n'),
            'year' => (int) now()->format('Y'),
        ];
    }

    /**
     * Form filter periode. ->live() membuat ringkasan, chart, tabel breakdown,
     * serta tombol export otomatis mengikuti setiap perubahan nilai.
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(5)
            ->live()
            ->statePath('filters')
            ->components([
                Section::make('Filter Kas Arus')
                    ->description('Pilih periode; ringkasan, grafik, tabel breakdown per bulan, dan tombol export di bawah otomatis mengikuti filter ini.')
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
                    KasArusStatsOverview::class,
                    KasArusChart::class,
                ]),
            ]);
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
     * Sumber kebenaran laporan — dipakai oleh tabel breakdown (blade view),
     * ringkasan, maupun export PDF/Excel sehingga angkanya selalu identik.
     *
     * Instance di-MEMOIZE per request (lihat $kasArusReportInstance): halaman
     * ini memanggil method ini dari beberapa tempat sekaligus pada satu
     * render — blade ($this->monthlyBreakdown() + $this->totals()), export,
     * dan widget. Tanpa memoize, tiap pemanggilan membuat instance baru,
     * cache breakdown di dalam KasArusReport jadi tidak pernah terpakai, dan
     * satu render memicu query berulang (2 per panggilan, bukan 2 total).
     */
    public function kasArusReport(): KasArusReport
    {
        // Filter bisa berubah di tengah request (mis. lewat updatedFilters),
        // jadi instance harus dibangun ulang bila state filter berbeda.
        $signature = $this->filtersSignature();

        if ($this->kasArusReportInstance === null || $this->kasArusReportSignature !== $signature) {
            $this->kasArusReportInstance = new KasArusReport($this->filters ?? []);
            $this->kasArusReportSignature = $signature;
        }

        return $this->kasArusReportInstance;
    }

    /**
     * Filter halaman sebagai objek ReportFilter (periode & label-nya saja).
     * Dialihkan lewat instance KasArusReport yang sama supaya halaman hanya
     * punya satu sumber parsing filter.
     */
    public function reportFilter(): ReportFilter
    {
        return $this->kasArusReport()->reportFilter();
    }

    /**
     * Sidik state filter untuk mengecek apakah instance KasArusReport yang
     * di-cache masih cocok. Hash dari filter ternormalisasi supaya perubahan
     * kecil (mis. urutan key) tidak membangun ulang instance.
     */
    private function filtersSignature(): string
    {
        $filters = $this->filters ?? [];
        ksort($filters);

        // serialize() (bukan json_encode) dipilih karena tidak pernah gagal
        // untuk state form: halaman harus tetap ter-render meski ada nilai
        // filter yang tak terduga, dan nilai tak terduga itu akan tetap
        // menghasilkan sidik yang berbeda antar nilai.
        return md5(serialize($filters));
    }

    /**
     * Tabel breakdown per bulan: Bulan | Pemasukan | Pengeluaran | Saldo.
     *
     * @return Collection<int, array{key: string, label: string, income: float, expense: float, saldo: float}>
     */
    public function monthlyBreakdown(): Collection
    {
        return $this->kasArusReport()->monthlyBreakdown();
    }

    /**
     * Ringkasan seluruh periode filter.
     *
     * @return array{income: float, expense: float, saldo: float}
     */
    public function totals(): array
    {
        return $this->kasArusReport()->totals();
    }

    /** Opsi tahun "Bulan Tertentu": 5 tahun ke belakang s/d tahun depan (pola sama dengan Laporan). */
    private function yearOptions(): array
    {
        $years = range(
            (int) now()->subYears(5)->format('Y'),
            (int) now()->addYear()->format('Y'),
        );

        return array_combine($years, $years);
    }

    /**
     * Export PDF laporan Kas Arus via dompdf; memakai StreamedResponse agar
     * andal dikirim dari aksi Livewire (pola sama dengan Laporan).
     */
    public function exportPdf(): StreamedResponse
    {
        $pdf = $this->buildPdfDocument();

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->exportFilename('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Susun dokumen PDF Kas Arus (kop, ringkasan 3 kartu, tabel breakdown per
     * bulan, total periode) dari data filter aktif; method terpisah agar bisa
     * diuji langsung tanpa melalui HTTP.
     */
    public function buildPdfDocument(): \Barryvdh\DomPDF\PDF
    {
        $report = $this->kasArusReport();

        return Pdf::loadView('exports.kas-arus-pdf', [
            'periodLabel' => $report->periodLabel(),
            'userName' => auth()->user()?->name ?? '—',
            'generatedAt' => now()->format('d/m/Y H:i'),
            'rows' => $report->monthlyBreakdown(),
            'summary' => $report->totals(),
        ])
            // A4 portrait ditetapkan eksplisit agar layout tidak tergantung
            // config default package; 4 kolom angka tetap lega di portrait.
            ->setPaper('a4', 'portrait');
    }

    /**
     * Export laporan Kas Arus ke Excel (.xlsx) via maatwebsite/excel —
     * isi file = breakdown + ringkasan dari data yang SAMA dengan layar.
     *
     * Memakai Excel::download() (BinaryFileResponse yang di-stream dari
     * temporary file) bukan Excel::raw() + print(): raw() menahan SELURUH
     * file .xlsx sebagai string di memori. Breakdown sudah kecil karena
     * teragregasi per bulan, tapi jalur ini dipakai bersama export lain
     * dan tidak menambah biaya memori sama sekali.
     */
    public function exportExcel(): BinaryFileResponse
    {
        $report = $this->kasArusReport();

        return Excel::download(
            new KasArusExport($report->monthlyBreakdown(), $report->totals()),
            $this->exportFilename('xlsx'),
            ExcelWriter::XLSX,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** Nama file export yang deskriptif, mengikuti periode filter aktif. */
    private function exportFilename(string $extension): string
    {
        [$from, $until] = $this->reportFilter()->period();

        $suffix = 'semua-periode';

        if ($from !== null || $until !== null) {
            $suffix = ($from?->format('Ymd') ?? 'awal').'-'.($until?->format('Ymd') ?? 'sekarang');
        }

        return "kas-arus-{$suffix}.{$extension}";
    }
}
