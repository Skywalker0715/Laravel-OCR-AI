<?php

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('halaman Create Expense hanya menampilkan Informasi Belanja & Foto Struk (section hasil OCR disembunyikan)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateExpense::class)
        ->assertSee('Informasi Belanja')
        ->assertSee('Foto Struk')
        // Section "Detail Pembayaran" & "Daftar Item Belanja" (beserta tombol
        // "Tambah Item"-nya) HANYA relevan di halaman Edit: saat Create, data
        // OCR/AI belum ada (OCR baru berjalan setelah save), jadi section
        // tersebut disembunyikan dan tidak boleh ikut ter-render.
        ->assertDontSee('Detail Pembayaran')
        ->assertDontSee('Daftar Item Belanja')
        ->assertDontSee('Tambah Item')
        // Tombol aksi Create kini berada di footer Section "Informasi Belanja"
        // (pola yang sama dengan Save/Cancel di halaman Edit).
        ->assertSee('Create & create another');
});
