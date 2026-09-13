<?php

namespace App\Models;

use App\Models\Scopes\OwnedByUserScope;
use App\Services\BudgetAlertService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        'items_mismatch',
    ];

    protected $casts = [
        'parsed_data' => 'array',
        'used_fallback' => 'boolean',
        'items_mismatch' => 'boolean',
        // date_shopping kini kolom date (bukan string) sejak migration
        // 2026_08_26_000003; cast 'date' membuat akses via Eloquent selalu
        // mengembalikan instance Carbon.
        'date_shopping' => 'date',
    ];

    /**
     * Global scope OwnedByUserScope + hook creating yang SELALU menimpa user_id
     * dengan user login saat terautentikasi (anti-spoofing mass assignment);
     * di console/queue tanpa auth, user_id eksplisit (seeder/test) dipertahankan.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByUserScope);

        static::creating(function (Expense $expense): void {
            if (Auth::check()) {
                $expense->user_id = Auth::id();
            }
        });

        // Cek ulang pemakaian budget setiap kali amount/kategori/tanggal belanja/pemilik
        // berubah. Menempel di model event agar jalur manual, tinker, maupun AIParserJob
        // semuanya terpantau; dedup notifikasi 90%/100% oleh flag di BudgetAlertService.
        static::saved(function (Expense $expense): void {
            // wasChanged() selalu false tepat setelah INSERT (Laravel hanya sync changes
            // pada update), jadi model baru langsung dianggap relevan — BudgetAlertService
            // mengabaikan expense tanpa nominal.
            if (! $expense->wasRecentlyCreated
                && ! $expense->wasChanged(['amount', 'category_id', 'date_shopping', 'user_id'])) {
                return;
            }

            app(BudgetAlertService::class)->checkAndNotify($expense);
        });

        // Hapus file foto struk lama saat diganti — Filament FileUpload tidak
        // menghapus file lama otomatis, jadi tanpa ini storage penuh file orphan.
        // Di model event agar cleanup berlaku juga untuk tinker/API/command.
        static::updated(function (Expense $expense): void {
            if (! $expense->wasChanged('receipt_image')) {
                return;
            }

            $old = $expense->getOriginal('receipt_image');

            // Hapus path lama bila berbeda dari yang baru (atau baru dikosongkan).
            if ($old !== null && $old !== $expense->receipt_image) {
                // Disk privat 'receipts' (temuan audit #2) — bukan lagi disk public.
                Storage::disk('receipts')->delete($old);
            }
        });

        // Bersihkan pula foto struk saat sebuah expense dihapus agar tidak
        // tersisa file orphan di storage/receipts/.
        //
        // Audit #3: disk 'receipts' punya konfigurasi throw=false, sehingga
        // Storage::delete() MENGGUNGKAN mengembalikan false (bukan exception)
        // saat gagal (mis. permission error). Jika tidak dicek eksplisit,
        // DB transaction tetap commit dan file fisik menjadi orphan.
        // Solusi: cek return value; jika false, log + throw agar DB::transaction
        // rollback dan tidak ada data yang setengah terhapus.
        static::deleted(function (Expense $expense): void {
            if ($expense->receipt_image) {
                // Disk privat 'receipts' (temuan audit #2) — bukan lagi disk public.
                // throw=false pada disk berarti delete() mengembalikan false
                // (bukan exception) saat gagal — perlu dicek eksplisit di sini.
                $deleted = Storage::disk('receipts')->delete($expense->receipt_image);

                if (! $deleted) {
                    Log::warning('Gagal menghapus file struk saat expense dihapus', [
                        'expense_id' => $expense->id,
                        'receipt_image' => $expense->receipt_image,
                        'user_id' => $expense->user_id,
                    ]);

                    // Throw untuk memaksa DB::transaction rollback ketika
                    // dipanggil dari dalam konteks transaction (DeleteUserAccountService).
                    // Di luar transaction, exception ini akan menghentikan
                    // penghapusan expense ini sambil tetap aman.
                    throw new \RuntimeException(
                        "Gagal menghapus file struk fisik: {$expense->receipt_image}. "
                        . 'Penghapusan expense dibatalkan untuk menjaga konsistensi data.'
                    );
                }
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
