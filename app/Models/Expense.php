<?php

namespace App\Models;

use App\Models\Scopes\OwnedByUserScope;
use App\Services\BudgetAlertService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class Expense extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'amount',
        'receipt_image',
        'note',
        'parsed_data',
        'date_shopping',
        'change',
        'vendor',
        'used_fallback',
    ];

    protected $casts = [
        'parsed_data' => 'array',
        'used_fallback' => 'boolean',
        // date_shopping kini kolom date (bukan string) sejak migration
        // 2026_08_26_000003; cast 'date' membuat akses via Eloquent selalu
        // mengembalikan instance Carbon.
        'date_shopping' => 'date',
    ];

    /**
     * Registrasi global scope + anti-spoofing kepemilikan.
     *
     * OwnedByUserScope memastikan query apa pun (termasuk di Filament) hanya
     * mengembalikan expense milik user yang sedang login. Hook creating di
     * bawah berlaku sebagai FORCE-override (bukan sekadar fill-if-null):
     * dalam konteks terautentikasi (request web maupun queue sync), nilai
     * user_id apa pun yang dibawa mass assignment — mis. dari pemanggilan
     * create($request->all()) di kode masa depan — SELALU ditimpa dengan
     * user yang sedang login, sehingga ownership mustahil di-spoof dari
     * input. Di console/queue tanpa auth, user_id yang di-set eksplisit
     * (seeder, test fixture, command) tetap dipertahankan.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByUserScope);

        static::creating(function (Expense $expense): void {
            if (Auth::check()) {
                $expense->user_id = Auth::id();
            }
        });

        // Periksa ulang pemakaian budget setiap kali expense disimpan dengan
        // komponen pemakaian budget yang berubah (nominal, kategori, tanggal
        // belanja, atau pemilik). Menempel di model event — bukan di form
        // Filament — agar jalur manual (Create/Edit), tinker, maupun
        // penyimpanan dari AIParserJob semuanya terpantau tanpa logika
        // terduplikasi. Deduplikasi notifikasi 90%/100% ditangani oleh
        // BudgetAlertService lewat flag notified_90_at/notified_100_at.
        static::saved(function (Expense $expense): void {
            // Catatan: tepat setelah INSERT, wasChanged() selalu false karena
            // Laravel hanya mengisi "changes" pada jalur update (syncChanges).
            // Maka model yang BARU dibuat langsung dianggap relevan —
            // BudgetAlertService sendiri mengabaikan expense tanpa nominal.
            if (! $expense->wasRecentlyCreated
                && ! $expense->wasChanged(['amount', 'category_id', 'date_shopping', 'user_id'])) {
                return;
            }

            app(BudgetAlertService::class)->checkAndNotify($expense);
        });

        // Bersihkan file foto struk lama agar storage/receipts/ tidak menumpuk
        // ketika foto diganti lewat form Edit.
        //
        // Investigasi menunjukkan sebelumnya memang TIDAK ada logika penghapusan
        // file lama di ExpenseForm.php (atau di mana pun). Filament
        // FileUpload tidak otomatis menghapus file lama saat diganti, sehingga
        // tiap ganti foto meninggalkan file orphan. Pakai model event ini
        // (bukan schema form) agar cleanup berlaku pula untuk tinker, API, dan
        // command lainnya; serta konsisten antara Create dan Edit.
        static::updated(function (Expense $expense): void {
            if (! $expense->wasChanged('receipt_image')) {
                return;
            }

            $old = $expense->getOriginal('receipt_image');

            // Hapus path lama bila memang berbeda dari yang baru (atau bila kolom
            // baru dikosongkan). File yang baru tetap disimpan.
            if ($old !== null && $old !== $expense->receipt_image) {
            // Disk privat 'receipts' (temuan audit #2) — bukan lagi disk public.
            Storage::disk('receipts')->delete($old);
            }
        });

        // Bersihkan pula foto struk saat sebuah expense dihapus agar tidak
        // tersisa file orphan di storage/receipts/.
        static::deleted(function (Expense $expense): void {
            if ($expense->receipt_image) {
            // Disk privat 'receipts' (temuan audit #2) — bukan lagi disk public.
            Storage::disk('receipts')->delete($expense->receipt_image);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(related: User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(related: Category::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(related: ExpenseItem::class, foreignKey: 'expenses_id');
    }
}
