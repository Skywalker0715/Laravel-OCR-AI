<?php

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Route terotorisasi foto struk (temuan audit keamanan #2): foto kini
 * disimpan di disk privat 'receipts' dan HANYA disajikan lewat
 * /receipt-image/{expense} untuk pemilik expense. User lain & tamu tidak
 * mendapat akses — 404 (bukan 403) agar keberadaan file tidak bocor lewat
 * kode status; tamu diarahkan ke halaman login.
 */

beforeEach(function () {
    Storage::fake('receipts');
});

test('pemilik expense bisa melihat foto struk lewat route terotorisasi', function () {
    $user = User::factory()->create();
    Storage::disk('receipts')->put('receipts/struk-uji.jpg', 'fake-image-content');

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Uji',
        'receipt_image' => 'receipts/struk-uji.jpg',
    ]);

    $this->actingAs($user)
        ->get("/receipt-image/{$expense->getKey()}")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

test('user lain mendapat 404 untuk foto struk milik orang lain', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    Storage::disk('receipts')->put('receipts/struk-orang-lain.jpg', 'rahasia');

    $expense = Expense::create([
        'user_id' => $owner->id,
        'title' => 'Struk Pemilik',
        'receipt_image' => 'receipts/struk-orang-lain.jpg',
    ]);

    $this->actingAs($intruder)
        ->get("/receipt-image/{$expense->getKey()}")
        ->assertNotFound();
});

test('tamu diarahkan ke halaman login', function () {
    $user = User::factory()->create();
    Storage::disk('receipts')->put('receipts/struk-uji.jpg', 'isi');

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Uji',
        'receipt_image' => 'receipts/struk-uji.jpg',
    ]);

    $this->get("/receipt-image/{$expense->getKey()}")->assertRedirect();
});

test('404 bila expense tidak punya foto atau filenya hilang', function () {
    $user = User::factory()->create();

    $tanpaFoto = Expense::create([
        'user_id' => $user->id,
        'title' => 'Tanpa Foto',
        'receipt_image' => null,
    ]);

    $fileHilang = Expense::create([
        'user_id' => $user->id,
        'title' => 'File Hilang',
        'receipt_image' => 'receipts/tidak-ada.jpg',
    ]);

    $this->actingAs($user)
        ->get("/receipt-image/{$tanpaFoto->getKey()}")
        ->assertNotFound();

    $this->get("/receipt-image/{$fileHilang->getKey()}")
        ->assertNotFound();
});