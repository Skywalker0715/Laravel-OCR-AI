<?php

use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Guard konfigurasi timeout queue.
 *
 * MASALAH YANG DILEWATI (bug laten, ditemukan 2026-10-03):
 *   config/queue.php punya `retry_after` 90 detik, sementara
 *   App\Jobs\AIParserJob::$timeout = 150 detik.
 *
 *   Laravel mensyaratkan retry_after > timeout job. Kalau tidak, job yang
 *   masih berjalan di worker pertama dianggap hilang oleh worker kedua, yang
 *   lalu mengambil job yang sama dan memprosesnya ULANG:
 *     - parsing struk dijalankan dua kali (Cohere API dipanggil 2x = biaya dobel),
 *     - pengguna menerima notifikasi kembar,
 *     - expense_items saling delete-create antar dua proses.
 *
 * Test di bawah mengunci hubungan itu supaya regression tidak bisa lolos
 * diam-diam. Nilai timeout job dibaca dari property job sungguhan — BUKAN
 * angka hardcoded — sehingga test ini ikut gagal (dengan pesan yang jelas) bila
 * suatu saat $timeout job dinaikkan tanpa menaikkan retry_after.
 */

uses(RefreshDatabase::class);

/**
 * Timeout job AIParserJob dalam detik, dibaca dari property job sungguhan
 * supaya test tidak melenceng bila nilainya diubah di kemudian hari.
 */
function aiparserJobTimeout(): int
{
    return (int) (new AIParserJob(new Expense))->timeout;
}

test('retry_after koneksi database lebih besar dari $timeout AIParserJob', function () {
    $retryAfter = (int) config('queue.connections.database.retry_after');
    $jobTimeout = aiparserJobTimeout();

    expect($retryAfter)->toBeGreaterThan($jobTimeout, config('queue.connections.database.retry_after').' = '.$retryAfter
        .'detik, tapi AIParserJob::$timeout = '.$jobTimeout.'detik. retry_after wajib LEBIH BESAR dari timeout job — kalau tidak, job yang masih diproses worker pertama akan diambil worker kedua dan dieksekusi dua kali.');
});

test('retry_after memberi jarak aman, bukan hanya sedikit di atas timeout job', function () {
    $retryAfter = (int) config('queue.connections.database.retry_after');
    $jobTimeout = aiparserJobTimeout();

    // Yang diuji hanya Inequality ">": jarak 1 detik secara teknis lolos, tapi
    // rapuh — OCR + Cohere bisa menyentuh batas timeout, dan retry_after yang
    // nyaris sama memancing eksekusi ganda di detik paling menegangkan. Selisih
    // minimal 10 detik memberi ruang untuk proses yang lambat.
    expect($retryAfter - $jobTimeout)->toBeGreaterThanOrEqual(10, 'retry_after ('.$retryAfter.') terlalu dekat dengan timeout job ('.$jobTimeout.'); recommended selisih minimal 10 detik.');
});

test('retry_after koneksi lain (redis & beanstalkd) juga melampaui timeout job', function () {
    $jobTimeout = aiparserJobTimeout();

    // Driver lain punya aturan yang sama: job diserialkan ke media (tabel
    // database atau Redis) lalu diambil worker berdasarkan reserved_at. Kalau
    // retry_after-nya lebih kecil dari timeout job, bug eksekusi ganda yang
    // sama terjadi saat pengguna pindah driver.
    foreach (['redis', 'beanstalkd'] as $connection) {
        expect((int) config("queue.connections.{$connection}.retry_after"))
            ->toBeGreaterThan($jobTimeout, "Koneksi {$connection}: retry_after wajib > timeout job ({$jobTimeout} detik).");
    }
});

test('bawaan retry_after di config/queue.php adalah 180 detik', function () {
    // Mengunci nilai BAWAAN di file config, terpisah dari nilai yang aktif
    // setelah env di-override. Tujuannya: mencegah seseorang menurunkan default
    // diam-diam di config/queue.php — test di atas memakai config() yang
    // bisa saja ikut berubah bila DB_QUEUE_RETRY_AFTER diset di .env.
    $defaults = require base_path('config/queue.php');

    expect((int) $defaults['connections']['database']['retry_after'])->toBe(180);
});

test('composer run dev menjalankan worker dengan --timeout yang >= timeout job', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer)->toBeArray('composer.json tidak bisa dibaca sebagai JSON.');

    $devScript = implode(' ', $composer['scripts']['dev'] ?? []);

    expect($devScript)->toContain('queue:work');

    // Worker default Laravel hanya 60 detik — kalau argumen --timeout tidak
    // ada, worker akan membunuh AIParserJob (timeout 150 detik) di detik ke-60.
    expect($devScript)->toMatch('/--timeout=(\d+)/', 'composer run dev harus menyertakan --timeout untuk queue:work.', 1);

    preg_match('/--timeout=(\d+)/', $devScript, $matches);
    $workerTimeout = (int) $matches[1];

    expect($workerTimeout)->toBeGreaterThanOrEqual(aiparserJobTimeout(), '--timeout worker ('.$workerTimeout.') harus >= timeout job ('.aiparserJobTimeout().'), kalau tidak worker mematikan job sebelum selesai.');

    // Dan tetap di bawah retry_after, agar tidak ada window di mana worker
    // masih kills job sementara Laravel sudah menganggapnya hilang.
    expect($workerTimeout)->toBeLessThan((int) config('queue.connections.database.retry_after'), '--timeout worker harus < retry_after.');
});

test('AIParserJob benar-benar declaring $timeout di atas 0 (sanity check)', function () {
    // Bila suatu saat property $timeout dihapus, test di atas akan salah
    // membandingkan dengan 0 dan tetap hijau. Test ini menutup celah itu.
    expect(aiparserJobTimeout())->toBeGreaterThan(0);

    // Sanity: job harus benar-benar bisa di-instantiate dengan model nyata,
    // sehingga test ini juga menangkap perubahan signature constructor.
    $user = User::factory()->create();
    $job = new AIParserJob(Expense::create(['user_id' => $user->id, 'title' => 'Sanity']));

    expect($job->timeout)->toBe(150);
});