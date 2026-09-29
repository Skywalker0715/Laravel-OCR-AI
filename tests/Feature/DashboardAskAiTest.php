<?php

use App\Filament\Pages\Dashboard;
use App\Models\Category;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Services\FinancialInsightService;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Markup modal aksi dari respons Livewire TERAKHIR.
 *
 * Livewire tidak mengirim ulang HTML utama komponen pada request update —
 * ia hanya mengirim partial yang berubah lewat effects['partials']. Karena itu
 * semua asersi "terlihat di browser" untuk isi modal dibaca dari sini, bukan
 * dari html() (yang isinya masih render awal tanpa modal).
 *
 * Catatan: key partial bisa `action-modals` (container) maupun
 * `action-modals.0` (modal yang sudah ter-mount), tergantung bagian mana yang
 * berubah — karenanya SEMUA nilai partial digabung.
 */
function actionModalsHtml($component): string
{
    $partials = $component->effects['partials'] ?? [];

    return collect($partials)->flatten()->implode("\n");
}

/**
 * Fitur "Tanya AI" versi sederhana: satu header Action di halaman Dashboard
 * (pola sama dengan deleteAccountAction() di App\Filament\Auth\EditProfile) —
 * modal berisi satu textarea, jawaban AI ditampilkan di modal KEDUA
 * showAiAnswer (bukan lagi notifikasi/toast),
 * tanpa riwayat tersimpan di database.
 */
test('tombol Tanya AI ter-render di header halaman Dashboard lewat HTTP GET (browser-fidelity)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/admin');

    $response->assertOk();
    // Label aksi terlihat sebagai text di markup halaman (tombol header).
    expect($response->getContent())->toContain('Tanya AI');
});

test('halaman Dashboard mendaftarkan action askAi dengan label & tombol submit yang benar', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class)
        // Action terdaftar di sisi PHP (diekspos lewat getHeaderActions()).
        ->assertActionExists('askAi')
        ->mountAction('askAi');

    // Isi modal (textarea) dirender Filament secara dinamis lewat Alpine,
    // sehingga tidak muncul sebagai text statis pada snapshot HTML — sama
    // seperti modal konfirmasi Hapus Akun (lihat ProfileDeleteAccountPageRenderTest).
    // Karena itu definisi action diperiksa di level PHP; keberadaan field
    // 'question' + rule required-nya dibuktikan oleh test validasi di bawah.
    $action = $component->instance()->getMountedAction();

    expect($action->getLabel())->toBe('Tanya AI');
    expect($action->getModalHeading())->toBe('Tanya AI');
    expect($action->getModalSubmitActionLabel())->toBe('Tanya');
    expect($component->instance()->mountedActionHasSchema())->toBeTrue();
});

test('submit pertanyaan mengirim data pengeluaran user ke Cohere dan membuka modal Jawaban AI berisi jawaban', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Total pengeluaran Anda Rp 50.000.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Uji Tanya AI',
        'amount' => 50000,
        'date_shopping' => now()->toDateString(),
    ]);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa total pengeluaran saya?'])
        ->callMountedAction();

    // Modal "Tanya AI" sudah DIGANTIKAN oleh modal "Jawaban AI"
    // (replaceMountedAction yang dipanggil dari processAskAi).
    $mountedAction = $component->instance()->getMountedAction();

    expect($mountedAction)->not->toBeNull()
        ->and($mountedAction->getName())->toBe('showAiAnswer');

    // Jawaban dibawa sebagai ARGUMENTS modal, bukan notifikasi/session.
    $arguments = $mountedAction->getArguments();

    expect($arguments['question'])->toBe('berapa total pengeluaran saya?')
        ->and($arguments['answer'])->toContain('Total pengeluaran Anda Rp 50.000.')
        ->and($arguments['isError'])->toBeFalse()
        ->and($arguments['isLimit'])->toBeFalse();

    // Tidak ada satupun notifikasi jawaban yang masuk ke session.
    expect(collect(session()->get('filament.notifications', []))->pluck('title')->all())
        ->not->toContain('Jawaban AI');

    // Markup modal (partial action-modals dari respons Livewire) benar-benar
    // memuat jawaban, bukan cuma state Livewire.
    expect(actionModalsHtml($component))->toContain('Total pengeluaran Anda Rp 50.000.');

    // Pertanyaan user + ringkasan pengeluaran benar-benar dikirim ke Cohere.
    Http::assertSent(function ($request) {
        $message = (string) ($request['message'] ?? '');

        return str_contains($message, 'berapa total pengeluaran saya?')
            && str_contains($message, 'Rp 50.000');
    });
});
test('pertanyaan kosong ditolak validasi dan tidak memanggil API AI sama sekali', function () {
    Http::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => ''])
        ->callMountedAction()
        ->assertHasActionErrors(['question']);

    Http::assertNothingSent();
});

test('API AI gagal: user tetap dapat jawaban ramah (bukan error) lewat modal Jawaban AI', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response('boom', 500),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa pengeluaran saya?'])
        ->callMountedAction();

    $mountedAction = $component->instance()->getMountedAction();
    expect($mountedAction->getName())->toBe('showAiAnswer');

    $arguments = $mountedAction->getArguments();

    // Fallback service, bukan exception/500.
    expect($arguments['answer'])->toContain('Maaf, sistem sedang bermasalah.')
        ->and($arguments['isError'])->toBeFalse();

    expect(actionModalsHtml($component))->toContain('Maaf, sistem sedang bermasalah.');
});

/*
|--------------------------------------------------------------------------
| Regresi 3 bug hasil testing fitur "Tanya AI"
|--------------------------------------------------------------------------
| 1. Jawaban AI rusak: satu karakter ("3") diulang ratusan kali.
| 2. Ringkasan data hanya 90 hari terakhir -> data lama (2017-2024) tak terhitung.
| 3. Markdown **bold** muncul mentah di jawaban.
*/
test('jawaban AI yang mengulang satu karakter (loop token) TIDAK ditampilkan - user dapat pesan fallback + warning di log', function () {
    // Replay bug: Cohere "nyangkut" mengembalikan karakter "3" yang sama
    // diulang 400 kali memenuhi layar, bukan jawaban yang bisa dibaca.
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => str_repeat('3', 400)], 200),
    ]);

    Log::spy();

    $user = User::factory()->create();
    $this->actingAs($user);

    $question = 'berapa total pengeluaran saya 3 bulan terakhir?';

    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => $question])
        ->callMountedAction();

    $arguments = $component->instance()->getMountedAction()->getArguments();

    // Yang tampil HANYA pesan fallback ramah; karakter rusak tidak pernah lolos.
    expect($arguments['answer'])->toContain('Maaf, AI tidak bisa menjawab pertanyaan ini dengan baik, coba pertanyaan lain atau tanya ulang.')
        ->and($arguments['answer'])->not->toContain('3333');

    expect(actionModalsHtml($component))
        ->toContain('Maaf, AI tidak bisa menjawab pertanyaan ini dengan baik, coba pertanyaan lain atau tanya ulang.');

    // Kejadian ini dicatat untuk investigasi, lengkap dengan pertanyaan pemicunya.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'pengulangan')
            && ($context['question'] ?? null) === $question)
        ->once();
});
test('permintaan ke Cohere memakai max_tokens wajar (300–500) supaya jawaban ngawur terpotong cepat', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Total pengeluaran Anda Rp 50.000.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa total pengeluaran saya?'])
        ->callMountedAction();

    Http::assertSent(function ($request): bool {
        $maxTokens = (int) ($request['max_tokens'] ?? 0);

        return $maxTokens >= 300 && $maxTokens <= 500;
    });
});

test('ringkasan yang dikirim ke AI mencakup SELURUH riwayat — expense lama (2017 & 2024) ikut dihitung', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Total pengeluaran Anda Rp 294.100.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $food = Category::resolveFromLabel('Makanan & Minuman', $user->id);

    // 2017 & 2024 jauh di luar 90 hari terakhir: dulu expense ini TIDAK pernah
    // dikirim ke AI sehingga total jawaban AI salah.
    foreach ([['2017-05-02', 84700], ['2024-11-19', 9400], ['2026-09-20', 200000]] as [$date, $amount]) {
        Expense::create([
            'user_id' => $user->id,
            'category_id' => $food->id,
            'title' => 'Belanja '.$date,
            'amount' => $amount,
            'date_shopping' => $date,
            'vendor' => 'Toko Uji',
        ]);
    }

    Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa total pengeluaran saya?'])
        ->callMountedAction();

    Http::assertSent(function ($request): bool {
        $message = (string) ($request['message'] ?? '');

        return str_contains($message, 'Total pengeluaran SELURUH riwayat: Rp 294.100 (3 transaksi).')
            // Agregat per tahun (bukan per baris expense) & kategori lengkap.
            && str_contains($message, 'Rincian total per tahun:')
            && str_contains($message, '2017')
            && str_contains($message, '2024')
                        && str_contains($message, 'Makanan & Minuman')
            // Rincian per BULAN (12 bulan terakhir) ikut dikirim supaya
            // pertanyaan "bulan ini / 3 bulan terakhir" tetap bisa dijawab.
            && str_contains($message, 'Rincian per bulan (agregat')
            && str_contains($message, 'Bulan 2026-09')
            // Format ringkasan lama (90 hari / per bulan) sudah tidak dipakai lagi.
            && ! str_contains($message, 'Rincian per kategori per bulan');
    });
});

test('isolasi lintas-user: ringkasan Tanya AI hanya memuat data user yang bertanya, data user lain tidak ikut', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Total pengeluaran Anda Rp 111.000.'], 200),
    ]);

    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    // Data kedua user dibuat SEBELUM login: hook `creating` pada model Expense
    // menimpa user_id dengan user yang sedang login, jadi user_id eksplisit
    // hanya dihormati saat tidak ada sesi (pola sama dengan test isolasi
    // resource Income/Debt/KasArus).
    Expense::create([
        'user_id' => $userA->id,
        'title' => 'Belanja User A',
        'amount' => 111000,
        'date_shopping' => now()->toDateString(),
        'vendor' => 'Toko Alpha',
    ]);

    Expense::create([
        'user_id' => $userB->id,
        'title' => 'Belanja User B Rahasia',
        'amount' => 222000,
        'date_shopping' => now()->toDateString(),
        'vendor' => 'Toko Beta',
    ]);

    // Sanity check fixture: masing-masing user benar-benar punya 1 expense.
    expect(Expense::query()->withoutGlobalScopes()->where('user_id', $userA->id)->count())->toBe(1)
        ->and(Expense::query()->withoutGlobalScopes()->where('user_id', $userB->id)->count())->toBe(1);

    $this->actingAs($userA);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa total pengeluaran saya?'])
        ->callMountedAction();

    // 1. Data yang dikirim ke Cohere hanya milik user A: total 1 transaksi
    //    Rp 111.000 (bukan gabungan 333.000) dan hanya vendor user A yang
    //    dirangkum — angka/vendor/nama data user B tidak boleh muncul sama
    //    sekali di dalam prompt.
    Http::assertSent(function ($request): bool {
        $message = (string) ($request['message'] ?? '');

        return str_contains($message, 'Total pengeluaran SELURUH riwayat: Rp 111.000 (1 transaksi).')
            && str_contains($message, 'Toko Alpha')
            && ! str_contains($message, 'Rp 222.000')
            && ! str_contains($message, 'Rp 333.000')
            && ! str_contains($message, 'Toko Beta')
            && ! str_contains($message, 'Belanja User B');
    });

    // 2. Jawaban yang benar-benar tampil di modal Jawaban AI juga jawaban atas
    //    data user A, tanpa menyinggung angka/vendor milik user B.
    $answer = $component->instance()->getMountedAction()->getArguments()['answer'];

    expect($answer)->toContain('Rp 111.000')
        ->and($answer)->not->toContain('Rp 222.000')
        ->and($answer)->not->toContain('Toko Beta');

    expect(actionModalsHtml($component))->toContain('Total pengeluaran Anda Rp 111.000.')
        ->not->toContain('Rp 222.000');
});


test('system prompt menegaskan cakupan SELURUH riwayat & melarang markdown (jawaban teks polos)', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Total pengeluaran Anda Rp 50.000.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa total pengeluaran saya?'])
        ->callMountedAction();

    Http::assertSent(function ($request): bool {
        $message = (string) ($request['message'] ?? '');

        return str_contains($message, 'SELURUH riwayat pengeluaran user')
            && str_contains($message, 'Jawab dalam teks polos (plain text) saja')
            && str_contains($message, 'JANGAN gunakan format markdown')
            // Header data lama (batas 90 hari) tidak boleh muncul lagi.
            && ! str_contains($message, 'DATA PENGELUARAN USER (90 hari terakhir)');
    });
});

test('jawaban AI bermarkdown (**bold**) dibersihkan sebelum masuk ke modal Jawaban AI', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Total pengeluaran Anda **Rp 15.000** untuk __Makanan__.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa total pengeluaran saya?'])
        ->callMountedAction();

    $answer = $component->instance()->getMountedAction()->getArguments()['answer'];

    expect($answer)->toContain('Total pengeluaran Anda Rp 15.000 untuk Makanan.')
        ->and($answer)->not->toContain('**')
        ->and($answer)->not->toContain('__');

    expect(actionModalsHtml($component))->toContain('Total pengeluaran Anda Rp 15.000 untuk Makanan.');
});
test('pengaman kedua di Dashboard: markdown tetap dibersihkan walau service mengembalikan ** mentah', function () {
    Http::fake();

    // Service diganti stub yang sengaja "lolos" mengembalikan markdown, untuk
    // membuktikan jaring terakhir di Dashboard.php tetap bekerja sendiri.
    app()->instance(FinancialInsightService::class, new class extends FinancialInsightService
    {
        public function ask(User $user, string $question): string
        {
            return 'Total **Rp 9.400** di kategori __Lainnya__.';
        }
    });

    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa total pengeluaran saya?'])
        ->callMountedAction();

    $answer = $component->instance()->getMountedAction()->getArguments()['answer'];

    expect($answer)->toContain('Total Rp 9.400 di kategori Lainnya.')
        ->and($answer)->not->toContain('**');
});

/*
|--------------------------------------------------------------------------
| Retrieval RAG-like: filter cerdas sesuai pertanyaan
|--------------------------------------------------------------------------
| 1. TANPA filter periode/jenis -> SELURUH riwayat (data 2017 ikut) +
|    ringkasan Income & Debt ikut terkirim (bukan cuma Expense).
| 2. DENGAN filter periode spesifik -> data yang dikirim SUDAH tersaring
|    (periode lain tidak pernah bocor ke prompt).
| 3. Pertanyaan soal utang/piutang -> memakai data Debt, jenis lain tidak.
*/
test('pertanyaan umum TANPA filter memakai seluruh riwayat (data 2017 ikut) + ringkasan pemasukan & utang-piutang', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Ringkasan keuangan Anda baik.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $food = Category::resolveFromLabel('Makanan & Minuman', $user->id);

    // 2017 (arsip lama) & 2026 (terbaru) — keduanya wajib masuk ringkasan
    // karena pertanyaan umum tanpa filter = SELURUH riwayat, bukan 90 hari.
    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'title' => 'Belanja 2017',
        'amount' => 84700,
        'date_shopping' => '2017-05-02',
        'vendor' => 'Toko Arsip 2017',
    ]);
    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'title' => 'Belanja 2026',
        'amount' => 200000,
        'date_shopping' => '2026-09-20',
        'vendor' => 'Toko Baru 2026',
    ]);

    Income::create([
        'user_id' => $user->id,
        'source' => 'Gaji Bulanan',
        'amount' => 5000000,
        'date_received' => '2026-09-01',
    ]);

    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Bu Sari',
        'amount' => 1000000,
        'paid_amount' => 250000,
    ]);
    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_PIUTANG,
        'counterparty_name' => 'Pak Anton',
        'amount' => 500000,
    ]);

    // Sengaja TANPA kata kunci jenis data/periode apa pun → deteksi filter
    // harus menyimpulkan: tanpa filter, semua jenis data.
    Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'gimana ringkasan keuangan saya?'])
        ->callMountedAction();

    Http::assertSent(function ($request): bool {
        $message = (string) ($request['message'] ?? '');

        return str_contains($message, 'Toko Arsip 2017')           // data 2017 ikut
            && str_contains($message, 'Total pengeluaran SELURUH riwayat: Rp 284.700 (2 transaksi).')
            && str_contains($message, '=== PEMASUKAN (INCOME) ===')
            && str_contains($message, 'Total pemasukan SELURUH riwayat: Rp 5.000.000 (1 catatan).')
            && str_contains($message, '=== UTANG & PIUTANG (DEBT) ===')
            && str_contains($message, 'Bu Sari')
            && str_contains($message, 'Pak Anton')
            // Tidak ada anjuran batas 90 hari / jendela pendek di system prompt.
            && ! str_contains($message, '90 hari');
    });
});

test('pertanyaan dengan periode spesifik mengirim HANYA data periode itu — 2017 & 2024 tidak ikut', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Pengeluaran Maret 2025 Anda Rp 200.000.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $food = Category::resolveFromLabel('Makanan & Minuman', $user->id);

    $rows = [
        // [tanggal, nominal, vendor]
        ['2017-05-02', 84700, 'Toko Arsip 2017'],
        ['2024-11-19', 9400, 'Toko Arsip 2024'],
        ['2025-03-05', 150000, 'Toko Maret A'],
        ['2025-03-20', 50000, 'Toko Maret B'],
        ['2025-06-10', 77000, 'Toko Juni'],
    ];
    foreach ($rows as [$date, $amount, $vendor]) {
        Expense::create([
            'user_id' => $user->id,
            'category_id' => $food->id,
            'title' => 'Belanja '.$date,
            'amount' => $amount,
            'date_shopping' => $date,
            'vendor' => $vendor,
        ]);
    }

    Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa pengeluaran bulan Maret 2025?'])
        ->callMountedAction();

    Http::assertSent(function ($request): bool {
        $message = (string) ($request['message'] ?? '');

        return str_contains($message, 'Total pengeluaran periode Maret 2025: Rp 200.000 (2 transaksi).')
            && str_contains($message, 'Rentang periode: 2025-03-01 s.d. 2025-03-31.')
            && str_contains($message, 'Bulan 2025-03: Rp 200.000 (2 transaksi)')
            && str_contains($message, '2025-03-05: Rp 150.000 (1 transaksi)')
            // Data di LUAR periode tidak boleh bocor sama sekali.
            && ! str_contains($message, 'Toko Arsip')
            && ! str_contains($message, 'Toko Juni')
            && ! str_contains($message, 'Rincian total per tahun:')
            // Jenis data yang disebut hanya pengeluaran — modul lain tidak ikut.
            && ! str_contains($message, '=== PEMASUKAN (INCOME) ===')
            && ! str_contains($message, '=== UTANG & PIUTANG (DEBT) ===');
    });
});

test('pertanyaan soal utang menjawab dari data Debt — piutang & expense tidak ikut', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Sisa utang Anda Rp 750.000.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja yang tidak relevan',
        'amount' => 999000,
        'date_shopping' => now()->toDateString(),
    ]);
    Income::create([
        'user_id' => $user->id,
        'source' => 'Gaji',
        'amount' => 7000000,
        'date_received' => now()->toDateString(),
    ]);

    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Bu Sari',
        'amount' => 1000000,
        'paid_amount' => 250000,
        'due_date' => now()->addMonth()->toDateString(),
    ]);
    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_PIUTANG,
        'counterparty_name' => 'Pak Anton',
        'amount' => 500000,
    ]);

    Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa utang saya sekarang?'])
        ->callMountedAction();

    Http::assertSent(function ($request): bool {
        $message = (string) ($request['message'] ?? '');

        // Hanya sub-tipe "utang" yang diminta — data Debt terbaca lengkap:
        // nama pihak, nominal, sisa, dan statusnya.
        return str_contains($message, '=== UTANG & PIUTANG (DEBT) ===')
            && str_contains($message, 'UTANG AKTIF (belum lunas): 1 catatan, total sisa Rp 750.000 dari total Rp 1.000.000')
            && str_contains($message, '- Bu Sari: sisa Rp 750.000 dari Rp 1.000.000 (status: Sebagian')
            && str_contains($message, 'jatuh tempo '.now()->addMonth()->toDateString())
            // Piutang tidak disebut → tidak dikirim; jenis lain juga tidak.
            && ! str_contains($message, 'Pak Anton')
            && ! str_contains($message, '=== PENGELUARAN (EXPENSE) ===')
            && ! str_contains($message, '=== PEMASUKAN (INCOME) ===');
    });
});

test('pertanyaan lintas modul (pemasukan vs utang) memuat ringkasan Income & Debt tanpa Expense', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Pemasukan Anda cukup untuk membayar utang.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja',
        'amount' => 123000,
        'date_shopping' => now()->toDateString(),
    ]);
    Income::create([
        'user_id' => $user->id,
        'source' => 'Penjualan Toko',
        'amount' => 3000000,
        'date_received' => now()->toDateString(),
    ]);
    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier Jaya',
        'amount' => 2000000,
    ]);

    Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'apakah pemasukan saya cukup buat bayar utang saya?'])
        ->callMountedAction();

    Http::assertSent(function ($request): bool {
        $message = (string) ($request['message'] ?? '');

        return str_contains($message, '=== PEMASUKAN (INCOME) ===')
            && str_contains($message, 'Total pemasukan SELURUH riwayat: Rp 3.000.000 (1 catatan).')
            && str_contains($message, '=== UTANG & PIUTANG (DEBT) ===')
            && str_contains($message, 'Supplier Jaya')
            // Pengeluaran tidak disebut di pertanyaan → tidak dikirim.
            && ! str_contains($message, '=== PENGELUARAN (EXPENSE) ===');
    });
});

test('pertanyaan dengan nama kategori menyaring pengeluaran HANYA kategori itu', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Pengeluaran makanan Anda Rp 60.000.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $food = Category::resolveFromLabel('Makanan & Minuman', $user->id);
    $transport = Category::resolveFromLabel('Transportasi', $user->id);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'title' => 'Nasi goreng',
        'amount' => 60000,
        'date_shopping' => '2026-09-20',
        'vendor' => 'Warung Enak',
    ]);
    Expense::create([
        'user_id' => $user->id,
        'category_id' => $transport->id,
        'title' => 'Bensin',
        'amount' => 40000,
        'date_shopping' => '2026-09-21',
        'vendor' => 'SPBU Sudirman',
    ]);

    // "makanan" = kata bermakna dari nama kategori "Makanan & Minuman"
    // → trigger filter kategori; "Transportasi" tidak boleh ikut.
    Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa pengeluaran untuk makanan saya?'])
        ->callMountedAction();

    Http::assertSent(function ($request): bool {
        $message = (string) ($request['message'] ?? '');

        return str_contains($message, 'Total pengeluaran kategori Makanan & Minuman: Rp 60.000 (1 transaksi).')
            && str_contains($message, 'Warung Enak')
            && ! str_contains($message, 'Rp 40.000')
            && ! str_contains($message, 'Transportasi')
            && ! str_contains($message, 'SPBU Sudirman');
    });
});

test('jawaban AI TIDAK dikirim sebagai notifikasi/event notificationSent - hanya lewat modal showAiAnswer', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Total pengeluaran Anda Rp 50.000.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa total pengeluaran saya?'])
        ->callMountedAction();

    // Jalur session resmi Filament TIDAK dipakai lagi untuk jawaban AI.
    $sessionNotifications = collect(session()->get('filament.notifications', []));
    expect($sessionNotifications->pluck('title')->all())->not->toContain('Jawaban AI');

    // Jalur event langsung juga sudah dihapus. Dulu event inilah yang membuat
    // race condition: wire:poll widget mengirim permintaan paralel berbasis
    // snapshot LAMA sehingga toast yang baru muncul ikut terhapus.
    $dispatched = collect($component->effects['dispatches'] ?? []);
    expect($dispatched->pluck('name')->all())->not->toContain('notificationSent');

    // Penggantinya: modal showAiAnswer sudah ter-mount membawa jawaban.
    $mountedAction = $component->instance()->getMountedAction();
    expect($mountedAction->getName())->toBe('showAiAnswer')
        ->and($mountedAction->getArguments()['answer'])->toContain('Total pengeluaran Anda Rp 50.000.');
});

/*
|--------------------------------------------------------------------------
| Regresi lama "toast jawaban AI" (kini diganti modal showAiAnswer)
|--------------------------------------------------------------------------
| Sebelumnya jawaban AI dikirim lewat DUA jalur (session + event
| 'notificationSent') dan dijaga guard UUID + dedup by id untuk mencegah
| toast dobel sekaligus error 500 "invalid input syntax for type uuid" di
| PostgreSQL. Karena tampilannya kini modal, mekanisme dua jalur itu dihapus
| total. Test-test berikut mengunci bahwa:
| (a) Tanya AI tidak pernah membuat notifikasi sama sekali;
| (b) notifikasi database ASLI milik user tidak tersentuh;
| (c) modal Jawaban AI tetap menampilkan jawaban yang benar.
*/
test('menjawab Tanya AI tidak membuat record di tabel notifications & tidak ada notifikasi di session', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Total pengeluaran Anda Rp 50.000.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Uji Dedup',
        'amount' => 50000,
        'date_shopping' => now()->toDateString(),
    ]);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa total pengeluaran saya?'])
        ->callMountedAction();

    // Tidak ada push ke session...
    expect(collect(session()->get('filament.notifications', []))->where('title', 'Jawaban AI'))
        ->toHaveCount(0);

    // ...dan tidak ada record sama sekali di tabel notifications.
    expect(DB::table('notifications')->count())->toBe(0);

    // Cukup SATU modal jawaban yang ter-mount (pengganti satu toast dulu).
    $instance = $component->instance();
    expect($instance->getMountedActions())->toHaveCount(1)
        ->and($instance->getMountedAction()->getName())->toBe('showAiAnswer');
});
test('menjawab Tanya AI tidak menghapus / menambah notifikasi database asli milik user', function () {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Total pengeluaran Anda Rp 50.000.'], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Notifikasi database SUNGGUHAN (lonceng notifikasi, mis. peringatan budget).
    Notification::make()
        ->title('Budget Terlampaui')
        ->body('Pengeluaran kategori Makanan melebihi budget.')
        ->sendToDatabase($user);

    expect(DB::table('notifications')->count())->toBe(1);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'berapa total pengeluaran saya?'])
        ->callMountedAction();

    // Dulu penutupan toast menjalankan `delete from notifications where id =
    // <id toast>` pada kolom bertipe uuid. Sekarang Tanya AI tidak menyentuh
    // tabel notifications sama sekali.
    expect(collect(session()->get('filament.notifications', [])))->toHaveCount(0)
        ->and(DB::table('notifications')->count())->toBe(1);

    // Modal Jawaban AI tetap menampilkan jawaban yang benar.
    expect($component->instance()->getMountedAction()->getName())->toBe('showAiAnswer')
        ->and($component->instance()->getMountedAction()->getArguments()['answer'])
        ->toContain('Total pengeluaran Anda Rp 50.000.');
});
/*
|--------------------------------------------------------------------------
| Isi modal Jawaban AI untuk SELURUH VARIASI jawaban
|--------------------------------------------------------------------------
| Mengunci bahwa:
| 1. SEMUA jawaban AI (pendek, panjang multi-paragraf, numerik, dsb.) selalu
|    sampai utuh sebagai ARGUMENTS modal showAiAnswer - tanpa dipotong dan
|    tanpa kehilangan newline (lihat white-space: pre-line di view-nya).
| 2. Hanya SATU modal yang ter-mount per pertanyaan (pengganti "satu toast").
| 3. Tidak ada notifikasi yang ikut terbuat untuk variasi apapun.
*/

test('modal Jawaban AI menampilkan SEMUA 5 variasi jawaban (pendek maupun panjang) apa adanya', function (string $question, string $simulatedAiAnswer) {
    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => $simulatedAiAnswer], 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => $question])
        ->callMountedAction();

    $instance = $component->instance();
    $mountedAction = $instance->getMountedAction();
    $arguments = $mountedAction->getArguments();
    $firstLine = Str::of($simulatedAiAnswer)->explode("\n")->first();

    expect($instance->getMountedActions())->toHaveCount(1)
        ->and($mountedAction->getName())->toBe('showAiAnswer')
        ->and($arguments['question'])->toBe($question)
        ->and($arguments['isError'])->toBeFalse()
        ->and($arguments['isLimit'])->toBeFalse();

    // Jawaban pertama sampai utuh, dan jumlah barisnya tidak berkurang
    // (multiline tetap utuh, tidak di-flatten jadi satu baris).
    expect($arguments['answer'])->toContain($firstLine)
        ->and(substr_count($arguments['answer'], "\n"))->toBeGreaterThanOrEqual(substr_count($simulatedAiAnswer, "\n"));

    // Baris pertama ikut ter-render di markup modal.
    expect(actionModalsHtml($component))->toContain($firstLine);

    // Sama sekali tidak ada notifikasi untuk variasi apapun.
    expect(collect(session()->get('filament.notifications', [])))->toHaveCount(0);
})->with([
    'maret 2025 (pendek)' => [
        'berapa pengeluaran bulan Maret 2025?',
        'Pengeluaran Anda pada bulan Maret 2025 tercatat sebesar Rp 1.250.000.',
    ],
    'total dari awal (panjang & angka besar)' => [
        'berapa total pengeluaran saya dari awal?',
        "Berdasarkan seluruh catatan riwayat transaksi Anda dari awal:\n" .
        "- Total Pengeluaran: Rp 845.720.000 dari 1.420 transaksi.\n" .
        "- Kategori pengeluaran terbesar adalah Operasional (Rp 320.000.000) dan Bahan Baku (Rp 250.000.000).\n" .
        "- Transaksi paling awal tercatat pada 12 Januari 2022.\n" .
        "Secara keseluruhan keuangan Anda terkendali dengan rata-rata bulanan Rp 23.500.000.",
    ],
    'piutang belum tertagih (daftar rincian)' => [
        'berapa piutang saya yang belum tertagih?',
        "Total piutang Anda yang belum tertagih saat ini adalah Rp 18.500.000 dari 3 debitur:\n" .
        "1. Toko Berkah Jaya: Rp 10.000.000 (jatuh tempo 15 Maret 2025 - LEWAT JATUH TEMPO)\n" .
        "2. Bpk. Hendra: Rp 5.000.000 (jatuh tempo 30 April 2025)\n" .
        "3. Ibu Siti: Rp 3.500.000 (jatuh tempo 10 Mei 2025)\n" .
        "Segera tindak lanjuti tagihan Toko Berkah Jaya yang sudah melewati batas waktu.",
    ],
    'ringkasan kas arus (multi baris & kalkulasi)' => [
        'bagaimana ringkasan arus kas saya?',
        "Ringkasan arus kas Anda:\n" .
        "Total Pemasukan: Rp 95.000.000\n" .
        "Total Pengeluaran: Rp 62.000.000\n" .
        "Net Cash Flow: Surplus Rp 33.000.000\n" .
        "Kondisi kas sangat sehat dengan rasio tabungan 34,7%.",
    ],
    'pertanyaan umum ringkas' => [
        'apa saran keuangan untuk saya?',
        'Pertahankan pencatatan rutin setiap struk belanja agar anggaran bulanan tetap terpantau dengan baik.',
    ],
]);
test('saat limit harian tercapai, modal Jawaban AI menampilkan pesan batas harian jujur dan jelas, bukan pesan tidak tersedia', function () {
    config()->set('services.cohere.daily_limit', 5);

    $user = User::factory()->create();
    $this->actingAs($user);

    $service = app(FinancialInsightService::class);
    $key = 'ai-insight:' . $user->id;
    \Illuminate\Support\Facades\RateLimiter::clear($key);

    // Hit 5 kali untuk menghabiskan kuota harian
    for ($i = 0; $i < 5; $i++) {
        \Illuminate\Support\Facades\RateLimiter::hit($key, 86400);
    }

    $answer = $service->ask($user, 'Berapa total pengeluaran saya?');

    // Pesan harus menjelaskan batas tercapai, menyebutkan kuota (5 dari 5), dan estimasi waktu reset
    expect($answer)->toContain('Batas pertanyaan harian tercapai (5 dari 5)')
        ->and($answer)->toContain('Bisa tanya lagi dalam')
        ->and($answer)->not->toContain('tidak tersedia');

    // Verifikasi juga bila dipanggil dari Dashboard askAi action
    $component = Livewire::test(Dashboard::class)
        ->mountAction('askAi')
        ->setActionData(['question' => 'Berapa total pengeluaran saya?'])
        ->callMountedAction();

    $mountedAction = $component->instance()->getMountedAction();
    $arguments = $mountedAction->getArguments();

    // Pesan limit tampil DI MODAL, dilabeli isLimit sehingga view menampilkan
    // penanda peringatan oranye (bukan dianggap jawaban biasa).
    expect($mountedAction->getName())->toBe('showAiAnswer')
        ->and($arguments['isLimit'])->toBeTrue()
        ->and($arguments['answer'])->toContain('Batas pertanyaan harian tercapai (5 dari 5)')
        ->and($arguments['answer'])->not->toContain('tidak tersedia');

    // Markup modal benar-benar memuat pesan limit itu.
    expect(actionModalsHtml($component))->toContain('Batas pertanyaan harian tercapai (5 dari 5)');

    \Illuminate\Support\Facades\RateLimiter::clear($key);
});
test('rate limit Tanya AI mengikuti nilai config services.cohere.daily_limit', function () {
    config()->set('services.cohere.daily_limit', 3);

    $user = User::factory()->create();
    $service = app(FinancialInsightService::class);
    $key = 'ai-insight:' . $user->id;
    \Illuminate\Support\Facades\RateLimiter::clear($key);

    Http::fake([
        'api.cohere.ai/*' => Http::response(['text' => 'Jawaban simulasi.'], 200),
    ]);

    // Pertanyaan 1, 2, 3 harus berhasil
    for ($i = 1; $i <= 3; $i++) {
        $answer = $service->ask($user, "Pertanyaan {$i}");
        expect($answer)->toBe('Jawaban simulasi.');
    }

    // Pertanyaan ke-4 harus terblokir karena limit = 3
    $blockedAnswer = $service->ask($user, 'Pertanyaan ke 4');
    expect($blockedAnswer)->toContain('Batas pertanyaan harian tercapai (3 dari 3)')
        ->and($blockedAnswer)->toContain('Bisa tanya lagi dalam')
        ->and($blockedAnswer)->not->toContain('tidak tersedia');

    \Illuminate\Support\Facades\RateLimiter::clear($key);
});

test('action showAiAnswer terkonfigurasi sebagai modal tampilan-saja (tanpa submit, tombol Tutup)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class)
        ->assertActionExists('showAiAnswer')
        ->mountAction('showAiAnswer', [
            'question' => 'berapa total pengeluaran saya?',
            'answer' => 'Total pengeluaran Anda Rp 50.000.',
            'isError' => false,
            'isLimit' => false,
        ]);

    $action = $component->instance()->getMountedAction();

    expect($action->getModalHeading())->toBe('Jawaban AI')
        ->and($action->getModalSubmitAction())->toBeNull()
        ->and($action->getModalCancelActionLabel())->toBe('Tutup')
        ->and($action->hasFormWrapper())->toBeFalse()
        ->and($action->getModalContent())->not->toBeNull();

    expect(actionModalsHtml($component))->toContain('Total pengeluaran Anda Rp 50.000.')
        ->toContain('Dibuat otomatis oleh AI');
});

test('modal Tanya AI memuat indikator loading "AI sedang menjawab..." yang dibatasi pada request submit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Dashboard::class)->mountAction('askAi');
    $html = actionModalsHtml($component);

    // wire:loading.flex + wire:target membatasi indikator hanya pada request
    // callMountedAction, sehingga tidak ikut kedip saat widget wire:poll.
    expect($html)->toContain('AI sedang menjawab...')
        ->and($html)->toContain('wire:loading.flex')
        ->and($html)->toContain('wire:target="callMountedAction"');
});