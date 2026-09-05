<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\Concerns\PollsParsingStatus;
use App\Jobs\AIParserJob;
use App\Models\Expense;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ViewExpense extends ViewRecord
{
    use PollsParsingStatus;

    protected static string $resource = ExpenseResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Badge "Sedang diproses..." + polling aktif hanya bila hasil parsing
        // (vendor/amount) memang belum terisi oleh AIParserJob.
        $this->isWaitingForParsing = $this->hasPendingParsingResults();
    }

    /**
     * Sisipkan badge "Sedang diproses..." di atas konten (infolist) selama
     * parsing OCR/AI masih berjalan di queue. Badge membawa wire:poll.3s yang
     * memanggil checkParsingStatus() — begitu hasil parsing tersedia, polling
     * berhenti dan infolist otomatis menampilkan data terbaru tanpa perlu
     * refresh manual dari user.
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
     * True bila expense ini masih menunggu hasil parsing. Query ulang ke DB
     * (bukan memakai state $this->record yang di-hydrate Livewire) agar nilai
     * terbaru yang ditulis queue worker selalu terbaca.
     */
    protected function hasPendingParsingResults(): bool
    {
        $fresh = Expense::find($this->record->getKey());

        return $fresh !== null && static::isExpenseParsingPending($fresh);
    }

    protected function onParsingResultsReady(): void
    {
        // Muat data terbaru ke instance record yang sama (yang dipegang
        // infolist); re-render Livewire pada respons polling ini otomatis
        // menampilkan field terbaru — tanpa reload halaman.
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reprocess')
                ->label('Proses Ulang OCR & AI')
                ->icon(Heroicon::OutlinedArrowPath)
                ->tooltip('Baca ulang foto struk terbaru di kolom receipt_image, lalu jalankan ulang parsing OCR & AI.')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Proses ulang parsing struk ini?')
                ->modalDescription('Sistem akan mem-baca ulang foto struk terbaru, menjalankan OCR, lalu mem-parse ulang vendor, total, tanggal, kategori, dan item.')
                ->modalSubmitActionLabel('Ya, proses ulang')
                ->action(function (): void {
                    // forceReocr=true agar OCR dibaca dari file di receipt_image
                    // TERBARU, bukan dari `note` yang mungkin kadaluarsa setelah
                    // foto struk diganti lewat form Edit.
                    $result = (new AIParserJob($this->record))->reprocess($this->record, true);

                    if ($result['ok']) {
                        Notification::make()
                            ->title('Struk berhasil diproses ulang')
                            ->body($result['note'])
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Parsing gagal')
                            ->body('Parsing gagal total — tidak ada data yang bisa diekstrak. Mohon isi data secara manual.')
                            ->danger()
                            ->send();
                    }

                    // Muat ulang halaman agar field terbaru tampil.
                    $this->js('window.setTimeout(() => window.location.reload(), 900)');
                }),

            EditAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return 'View Expense: '.$this->record->title;
    }
}
