<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman legacy "Detail Item" — daftar item belanja per struk.
 *
 * STATUS: SISA (belum dihapus, sengaja ditahan). Satu-satunya reference:
 *  - Pendaftaran route di ExpenseResource::getPages() dengan key 'items'
 *    (URL /expenses/{record}/items) — reference ini WAJIB ikut dibersihkan
 *    seandainya nanti class ini dihapus.
 *  - View blade 'filament.components.item-list' yang di-render-nya.
 *
 * Tidak ada reference lain: tidak ada action/link Filament (ExpensesTable,
 * ViewExpense, EditExpense) yang mengarah ke route 'items', dan tidak ada test
 * yang memakainya. Daftar item kini sudah tampil di halaman View Expense
 * (Section "Daftar Item Belanja" pada ExpenseResource::infolist()) dan bisa
 * dikoreksi di halaman Edit (Repeater "Daftar Item Belanja").
 *
 * Menghapus class ini aman secara fungsional, tapi sengaja TIDAK dilakukan pada
 * batch ini: halaman ini masih punya URL yang mungkin sudah dibagikan/di-bookmark
 * (mis. oleh pengguna), dan menghapus route akan membuat URL lama menghasilkan
 * 404. Penghapusan sebaiknya diputuskan terpisah, sekalian menghapus route
 * 'items' di getPages() beserta view item-list.blade.php yang tidak lagi dipakai.
 *
 * Sifat halaman ini: memakai blade view sendiri, bukan infolist Filament —
 * jadi tipe return render() harus tetap \Illuminate\Contracts\View\View.
 */
class ViewExpenseItems extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    // Static $view sengaja tidak dideklarasikan supaya tidak konflik dengan
    // ViewRecord; halaman ini memilih view-nya sendiri lewat render() di bawah.
    public function render(): \Illuminate\Contracts\View\View
    {
        return view('filament.components.item-list', [
            'items' => $this->record->items,
            'expense' => $this->record,
        ]);
    }
}
