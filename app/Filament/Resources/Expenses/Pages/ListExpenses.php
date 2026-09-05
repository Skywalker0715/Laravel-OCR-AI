<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\Concerns\PollsParsingStatus;
use App\Models\Expense;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;

class ListExpenses extends ListRecords
{
    use PollsParsingStatus;

    protected static string $resource = ExpenseResource::class;

    public function mount(): void
    {
        parent::mount();

        // Badge "Sedang diproses..." + polling aktif hanya bila masih ada
        // expense milik user yang menunggu hasil parsing AIParserJob (mis.
        // baru saja dibuat via Create atau baru saja diganti fotonya via Edit).
        $this->isWaitingForParsing = $this->hasPendingParsingResults();
    }

    /**
     * Sisipkan badge "Sedang diproses..." di atas tabel selama parsing OCR/AI
     * masih berjalan di queue. Badge membawa wire:poll.3s yang memanggil
     * checkParsingStatus() — begitu hasil parsing tersedia, polling berhenti
     * dan tabel otomatis menampilkan data terbaru tanpa perlu refresh manual
     * dari user.
     *
     * Konten badge dibungkus closure yang dievaluasi Filament SAAT RENDER
     * (bukan saat schema di-cache sebelum method polling dieksekusi), sehingga
     * statusnya selalu terkini: begitu $isWaitingForParsing menjadi false,
     * badge hilang dari DOM dan wire:poll otomatis berhenti.
     */
    public function content(Schema $schema): Schema
    {
        return parent::content($schema)->components([
            Html::make(fn (): string => view('filament.expenses.parsing-status-badge', [
                'isWaitingForParsing' => $this->isWaitingForParsing,
            ])->render()),
            ...$schema->getComponents(),
        ]);
    }

    /**
     * True bila ada expense milik user yang masih menunggu hasil parsing
     * (field vendor & amount sama-sama kosong).
     */
    protected function hasPendingParsingResults(): bool
    {
        return Expense::query()
            ->where(fn ($query) => $query->whereNull('vendor')->orWhere('vendor', ''))
            ->where(fn ($query) => $query->whereNull('amount')->orWhere('amount', '<=', 0))
            ->exists();
    }

    protected function onParsingResultsReady(): void
    {
        // Muat ulang isi tabel agar baris yang barusan selesai diparsing
        // langsung menampilkan vendor/amount terbaru tanpa refresh manual.
        $this->resetTable();

        Notification::make()
            ->title('Hasil parsing struk tersedia')
            ->body('Data struk yang sedang diproses sudah selesai dan ditampilkan di tabel.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
