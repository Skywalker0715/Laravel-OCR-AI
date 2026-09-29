<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\FinancialInsightService;
use App\Support\AiAnswerSanitizer;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dashboard admin: memakai widget kustom aplikasi (Widget "Welcome" bawaan dihapus).
 *
 * Halaman ini juga mendaftarkan dua header action terkait fitur tanya-jawab AI
 * (lihat askAiAction() & showAiAnswerAction()) — satu-satunya titik masuk fitur
 * "Tanya AI" pada versi sederhana ini:
 *
 *   1. askAi       → modal berisi satu textarea pertanyaan;
 *   2. showAiAnswer → modal HANYA-TAMPILAN berisi jawaban AI (tanpa form),
 *                     dibuka via replaceMountedAction() dari processAskAi().
 *
 * Jawaban sengaja TIDAK dikirim sebagai notifikasi/toast lagi: toast bergantung
 * pada session & event yang bisa terhapus oleh race condition wire:poll widget
 * dashboard. Modal adalah kontainer yang jauh lebih stabil.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\PendingParsingJobsAlert::class,
            \App\Filament\Widgets\StatsOverview::class,
            // Baris kedua: TIGA kartu sejajar dalam SATU baris (masing-masing
            // 1/3 lebar) — Total Pemasukan | Total Utang Aktif | Total Piutang
            // Aktif. Urutan Income SEBELUM Debt disengaja: widget Income menempati
            // 1/3 pertama, lalu widget Debt membagi 2/3 sisanya jadi 2 kartu
            // (lihat $columnSpan masing-masing widget & getColumns() di bawah).
            \App\Filament\Widgets\IncomeStatsOverview::class,
            \App\Filament\Widgets\DebtStatsOverview::class,
            \App\Filament\Widgets\ExpenseLineChart::class,
            \App\Filament\Widgets\CategoryChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        // Grid 6 kolom di layar lg+ (di bawah lg tetap 1 kolom / menumpuk —
        // perilaku sama dengan sebelumnya yang memakai 2 kolom).
        // 6 dipilih sebagai KPK dari kebutuhan lebar widget:
        //  - baris 1: StatsOverview (columnSpan 'full') → 3 kartu statistik
        //    SEPERTI SEBELUMNYA, tidak diubah sama sekali;
        //  - baris 2: Income (2/6) + Debt (4/6, berisi 2 kartu @2/6)
        //    = 3 kartu @1/3 sejajar sempurna;
        //  - baris 3: ExpenseLineChart (3/6) + CategoryChart (3/6)
        //    = dua grafik berdampingan 50:50 seperti sebelumnya.
        return 6;
    }

    /**
     * Aksi header "Tanya AI": buka modal berisi satu field pertanyaan, kirim
     * pertanyaan itu ke AI, lalu tampilkan jawabannya di modal KEDUA
     * (showAiAnswerAction) — bukan sebagai notifikasi/toast.
     *
     * POLA SENGAJA MENIRU deleteAccountAction() di App\Filament\Auth\EditProfile
     * (Action::make() → label/heading → schema() → modalSubmitActionLabel() →
     * action()) supaya memakai komponen & gaya bawaan Filament — TIDAK ada
     * Alpine.js/JS/renderHook manual, sehingga tombol & modal otomatis
     * mengikuti warna brand, ikon, dan perilaku loading milik panel.
     *
     * Perbedaan dari EditProfile: action ini memakai layout halaman penuh
     * (sidebar), jadi tombolnya cukup didaftarkan di header lewat
     * getHeaderActions() — halaman Profile harus render manual di body karena
     * memakai layout "simple".
     */
    public function askAiAction(): Action
    {
        return Action::make('askAi')
            ->label('Tanya AI')
            ->icon('heroicon-o-sparkles')
            ->modalHeading('Tanya AI')
            // Deskripsi mencerminkan cakupan baru: tiga jenis data + filter
            // cerdas (default seluruh riwayat, persempit saat ada periode/
            // kategori/jenis yang disebut eksplisit di pertanyaan).
            ->modalDescription('AI menjawab berdasarkan data Anda: pengeluaran, pemasukan, serta utang & piutang. Default-nya seluruh riwayat dipakai; kalau Anda menyebut periode/kategori tertentu (mis. "Maret 2025"), data otomatis disaring sesuai pertanyaan.')
            ->modalSubmitActionLabel('Tanya')
            ->schema([
                Textarea::make('question')
                    ->label('Pertanyaan Anda')
                    ->required()
                    ->rows(3)
                    ->placeholder('Contoh: berapa total pengeluaran saya dari awal? / pengeluaran bulan Maret 2025 / berapa utang saya?'),
            ])
            // Indikator loading di bawah textarea (view raw HTML, bukan
            // notification): tampil HANYA selama request callMountedAction
            // berjalan. Tombol "Tanya" bawaan Filament sendiri sudah otomatis
            // disabled + spinner selama request, jadi spam klik beruntun
            // tidak bisa menggandakan panggilan API AI.
            ->modalContentFooter(view('filament.pages.ask-ai-loading'))
            ->action(function (Action $action): void {
                $this->processAskAi($action);
            });
    }

    /**
     * Modal KEDUA "Jawaban AI": wadah tampilan hasil tanya-jawab.
     *
     * Modal ini TIDAK terdaftar di getHeaderActions() (tidak boleh jadi tombol
     * kedua di header). Ia di-mount dari processAskAi() lewat
     * replaceMountedAction('showAiAnswer', [...]) — Filament meresolvi action
     * ini dari method `showAiAnswerAction()` berdasarkan namanya
     * (InteractsWithActions::resolveAction()), sehingga modal pertama (askAi)
     * berganti menjadi modal jawaban pada nesting index yang sama (0) dan
     * otomatis terbuka kembali oleh JS Filament (event sync-action-modals).
     *
     * Sifatnya murni tampilan: tanpa schema, tanpa tombol submit
     * (modalSubmitAction(false)), dan formWrapper(false) agar body modal tidak
     * dibungkus <form> — tidak ada wire:submit yang bisa dipicu Enter.
     */
    public function showAiAnswerAction(): Action
    {
        return Action::make('showAiAnswer')
            ->label('Jawaban AI')
            // Ikon & warnanya mengikuti status jawaban: hijau (normal),
            // oranye (kuota harian habis), merah (kegagalan teknis).
            ->modalIcon(fn (array $arguments): string => match (true) {
                (bool) ($arguments['isError'] ?? false) => 'heroicon-o-exclamation-triangle',
                (bool) ($arguments['isLimit'] ?? false) => 'heroicon-o-clock',
                default => 'heroicon-o-sparkles',
            })
            ->modalIconColor(fn (array $arguments): string => match (true) {
                (bool) ($arguments['isError'] ?? false) => 'danger',
                (bool) ($arguments['isLimit'] ?? false) => 'warning',
                default => 'success',
            })
            ->modalHeading('Jawaban AI')
            ->modalWidth('2xl')
            // Hanya tombol "Tutup" di footer — tidak ada tombol submit.
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup')
            ->formWrapper(false)
            // Isi modal dibaca dari arguments yang dikirim processAskAi(),
            // sehingga jawaban TIDAK disimpan ke state publik maupun database.
            ->modalContent(fn (array $arguments) => view('filament.pages.ask-ai-answer', [
                'question' => (string) ($arguments['question'] ?? ''),
                'answer' => (string) ($arguments['answer'] ?? ''),
                'isError' => (bool) ($arguments['isError'] ?? false),
                'isLimit' => (bool) ($arguments['isLimit'] ?? false),
            ]));
    }

    /**
     * Daftarkan aksi "Tanya AI" sebagai HEADER action halaman Dashboard.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->askAiAction(),
        ];
    }

    /**
     * Kirim pertanyaan user ke FinancialInsightService, lalu tampilkan
     * jawabannya di modal KEDUA showAiAnswerAction() lewat
     * replaceMountedAction() - bukan sebagai notifikasi/toast.
     *
     * KENAPA MODAL, BUKAN NOTIFIKASI:
     * Toast lama bergantung pada session 'filament.notifications' + event
     * 'notificationSent'. Widget dashboard memakai wire:poll sehingga bisa
     * mengirim permintaan Livewire paralel berbasis snapshot LAMA; saat hasil
     * itu diterapkan, toast yang baru saja muncul ikut terhapus (race condition
     * yang membuat toast hilang tepat setelah muncul, khususnya di dark mode).
     * Modal adalah bagian dari snapshot komponen yang SAMA, jadi tidak bisa
     * terhapus oleh permintaan lain.
     *
     * Risiko spam klik beruntun ditangani bawaan Filament: tombol submit modal
     * memakai form wrapper Livewire (wire:submit), jadi tombol otomatis
     * disabled + menampilkan spinner selama request berjalan (ditambah teks
     * "AI sedang menjawab..." dari ask-ai-loading.blade.php). Tidak ada
     * riwayat pertanyaan yang disimpan ke database.
     *
     * @param  Action  $action  Aksi yang sedang dijalankan - data form validasi
     *                          didapat via getData().
     */
    protected function processAskAi(Action $action): void
    {
        $data = $action->getData();

        $question = trim((string) ($data['question'] ?? ''));
        $user = auth()->user();

        // Safety net: field 'question' sudah ->required() di schema, tapi
        // closure ini bisa dipanggil dari jalur lain - jangan pernah
        // mengirim pertanyaan kosong ke API AI.
        if (! $user instanceof User || $question === '') {
            Notification::make()
                ->title('Pertanyaan masih kosong')
                ->body('Tulis dulu pertanyaan Anda, lalu tekan tombol Tanya.')
                ->danger()
                ->send();

            return;
        }

        $isError = false;

        try {
            $answer = app(FinancialInsightService::class)->ask($user, $question);
        } catch (Throwable $e) {
            // FinancialInsightService sudah punya fallback internal (pesan
            // ramah bila API Cohere gagal/tidak tersedia); catch ini hanya
            // jaring terakhir agar modal tidak pernah pecah dengan error 500.
            Log::error('Gagal memproses pertanyaan Tanya AI', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            // Kegagalan teknis juga ditampilkan DI MODAL (bukan toast) supaya
            // user selalu melihat hasil di satu tempat yang sama.
            $answer = 'Terjadi kesalahan teknis saat menghubungi AI. Silakan coba lagi nanti.';
            $isError = true;
        }

        // Pengaman kedua (jaga-jaga bila AI tetap menulis markdown walau system
        // prompt sudah melarangnya): buang sisa penanda **bold**/__bold__ SEBELUM
        // jawaban dikirim ke modal. FinancialInsightService juga sudah
        // membersihkannya, jadi ini murni jaring terakhir.
        $answer = AiAnswerSanitizer::stripMarkdown($answer);

        // Guard rate limit harian FinancialInsightService memakai pesan dengan
        // prefix 'Batas pertanyaan harian tercapai' (lihat sprintf di
        // FinancialInsightService). Dilabeli terpisah supaya modal menampilkan
        // penanda peringatan oranye, bukan dianggap jawaban biasa.
        $isLimit = str_contains($answer, 'Batas pertanyaan harian tercapai');

        // Ganti modal "Tanya AI" dengan modal "Jawaban AI". Keduanya memakai
        // nesting index yang sama (0), sehingga JS Filament membuka ulang modal
        // secara otomatis (event sync-action-modals -> open-modal). Kembalinya ke
        // InteractsWithActions::callMountedAction() TIDAK meng-unmount modal ini,
        // karena daftar mounted action sudah berubah (lihat guard "action was
        // replaced while it was being called" di sana).
        $this->replaceMountedAction('showAiAnswer', [
            'question' => $question,
            'answer' => $answer,
            'isError' => $isError,
            'isLimit' => $isLimit,
        ]);
    }
}