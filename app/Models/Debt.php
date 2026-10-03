<?php

namespace App\Models;

use App\Models\Scopes\OwnedByUserScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Catatan Utang Piutang (pinjam-meminjam dengan pihak lain) — fitur yang
 * relevan untuk segmen UMKM (modal, supplier, karyawan).
 *
 *  - type 'utang'   : uang yang kita PINJAM dari pihak lain (kewajiban)
 *  - type 'piutang' : uang yang kita PINJAMKAN ke pihak lain (hak tagih)
 *
 * Isolasi data per-user memakai global scope OwnedByUserScope + hook creating
 * yang menimpa user_id dengan user login (anti-spoofing) — pola sama dengan
 * Expense/Budget, aman untuk mode personal maupun UMKM multi-user.
 *
 * Nilai `status` SELALU diturunkan dari perbandingan `paid_amount` vs `amount`
 * (lihat normalizePaymentState()) sehingga status tidak mungkin bertentangan
 * dengan nominal tersimpan, dari jalur mana pun (form, action, tinker).
 */
class Debt extends Model
{
    public const TYPE_UTANG = 'utang';

    public const TYPE_PIUTANG = 'piutang';

    public const STATUS_BELUM_LUNAS = 'belum_lunas';

    public const STATUS_SEBAGIAN = 'sebagian';

    public const STATUS_LUNAS = 'lunas';

    protected $fillable = [
        'user_id',
        'type',
        'counterparty_name',
        'amount',
        'due_date',
        // 'status' tetap fillable demi kenyamanan seed/test, tapi hook saving
        // menimpanya dengan hasil perhitungan — nilai yang dipalsukan manual
        // tidak akan pernah tersimpan.
        'status',
        'paid_amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    /**
     * Global scope per-user + normalisasi status sebelum setiap penyimpanan.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByUserScope);

        static::creating(function (Debt $debt): void {
            if (Auth::check()) {
                $debt->user_id = Auth::id();
            }
        });

        // Dipasang di model event (bukan hanya di action Filament) supaya
        // aturan pembayaran tetap konsisten walau diubah dari tinker, seeder,
        // atau kode lain: paid_amount di-clamp ke nominal total dan status
        // dihitung ulang.
        static::saving(function (Debt $debt): void {
            $debt->normalizePaymentState();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(related: User::class);
    }

    /**
     * Pilihan tipe untuk Select form / label tabel.
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_UTANG => 'Utang',
            self::TYPE_PIUTANG => 'Piutang',
        ];
    }

    /**
     * Pilihan status untuk Select filter / label tabel.
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_BELUM_LUNAS => 'Belum Lunas',
            self::STATUS_SEBAGIAN => 'Sebagian',
            self::STATUS_LUNAS => 'Lunas',
        ];
    }

    /**
     * Clamp nilai pembayaran ke rentang wajar lalu simpan status hasilnya.
     * Dipanggil otomatis oleh hook saving (lihat booted()).
     */
    public function normalizePaymentState(): void
    {
        $total = max(0.0, (float) $this->amount);
        $paid = max(0.0, (float) ($this->paid_amount ?? 0));

        // Pembayaran tidak boleh melebihi total; kelebihan diabaikan
        // (form/action sudah membatasi nilai maksimumnya).
        if ($paid > $total) {
            $paid = $total;
        }

        $this->paid_amount = $paid;
        $this->status = $this->deriveStatus($total, $paid);
    }

    /**
     * Hitung status dari nominal total & nominal terbayar:
     *  - belum dibayar sama sekali                    → belum_lunas
     *  - terbayar sebagian (0 < paid < total)         → sebagian
     *  - terbayar penuh / lebih (paid >= total > 0)   → lunas
     */
    public function deriveStatus(?float $total = null, ?float $paid = null): string
    {
        $total ??= max(0.0, (float) $this->amount);
        $paid ??= max(0.0, (float) ($this->paid_amount ?? 0));

        if ($total > 0 && $paid >= $total) {
            return self::STATUS_LUNAS;
        }

        if ($paid > 0) {
            return self::STATUS_SEBAGIAN;
        }

        return self::STATUS_BELUM_LUNAS;
    }

    /** Sisa yang belum dibayar/diterima (0 untuk catatan lunas). */
    public function remainingAmount(): float
    {
        return max(0.0, (float) $this->amount - (float) ($this->paid_amount ?? 0));
    }

    /** True bila catatan sudah lunas. */
    public function isLunas(): bool
    {
        return $this->status === self::STATUS_LUNAS;
    }

    /** True bila catatan masih berjalan (belum lunas / baru sebagian). */
    public function isActive(): bool
    {
        return ! $this->isLunas();
    }

    /** True bila jatuh tempo sudah lewat dan catatan belum lunas. */
    public function isOverdue(): bool
    {
        if ($this->due_date === null || $this->isLunas()) {
            return false;
        }

        // due_date bertipe date (00:00), jadi "hari ini" tidak dianggap lewat.
        return $this->due_date->isBefore(today());
    }

    /** Persentase pelunasan (0-100) untuk indikator progres. */
    public function paidPercent(): int
    {
        $total = (float) $this->amount;

        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round(((float) $this->paid_amount / $total) * 100));
    }

    /**
     * Catat pembayaran (utang) / penerimaan (piutang). Status & clamp nominal
     * diurus hook saving, termasuk kasus pembayaran yang melebihi sisa.
     *
     * Anti lost-update: seluruh pembayaran dijalankan di dalam DB::transaction
     * dengan lockForUpdate (SELECT ... FOR UPDATE) pada baris debt, sehingga
     * pembayaran paralel dari proses/tab lain DISERIALISASI — transaksi kedua
     * menunggu transaksi pertama commit, lalu membaca paid_amount TERBARU hasil
     * kunci sebelum menambahkannya (bukan snapshot basi di memori). Tanpa ini,
     * dua pembayaran Rp100.000 yang berjalan bersamaan bisa tersimpan hanya
     * Rp100.000 (satu update menimpa update lain).
     *
     * @throws InvalidArgumentException bila nominal tidak lebih besar dari 0.
     * @throws ModelNotFoundException   bila catatan terhapus di tengah jalan.
     */
    public function recordPayment(float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal pembayaran harus lebih besar dari 0.');
        }

        DB::transaction(function () use ($amount): void {
            // Kunci baris debt. withoutGlobalScopes() disengaja: objek $this
            // sudah pasti baris yang sah dipanggil caller (scoping pemanggilan
            // tetap urusan global scope saat baris itu MUAT, bukan saat lock).
            $locked = static::query()
                ->withoutGlobalScopes()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new ModelNotFoundException(
                    "Catatan utang/piutang id {$this->getKey()} sudah tidak ada — pembayaran dibatalkan."
                );
            }

            // Hitung ulang DARI DATA TERKUNCI: atribut basi di memori ditimpa
            // dengan nilai terkini (syncOriginal=true supaya hanya paid_amount
            // (+ status oleh hook) yang dianggap berubah oleh Eloquent).
            $this->setRawAttributes($locked->getAttributes(), true);

            $this->paid_amount = (float) ($this->paid_amount ?? 0) + $amount;

            // Lewat save() — BUKAN query builder mentah — agar hook saving
            // (normalizePaymentState: clamp ke amount + derive status) tetap
            // berjalan untuk jalur ini.
            $this->save();
        });
    }

    /** Tandai catatan sebagai lunas (paid_amount = amount). */
    public function markAsPaid(): void
    {
        $this->paid_amount = (float) $this->amount;
        $this->save();
    }

    /** Label tipe siap tampil ("Utang"/"Piutang"). */
    public function typeLabel(): string
    {
        return self::typeOptions()[$this->type] ?? (string) $this->type;
    }

    /** Label status siap tampil ("Belum Lunas"/"Sebagian"/"Lunas"). */
    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    /** Warna badge tipe: Utang merah (kewajiban), Piutang hijau (hak tagih). */
    public function typeColor(): string
    {
        return $this->type === self::TYPE_UTANG ? 'danger' : 'success';
    }

    /** Warna badge status: belum lunas oranye, sebagian biru, lunas hijau. */
    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_LUNAS => 'success',
            self::STATUS_SEBAGIAN => 'info',
            default => 'warning',
        };
    }

    /** Batasi query ke satu tipe (utang / piutang). */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where($query->qualifyColumn('type'), $type);
    }

    /** Batasi query ke catatan yang belum lunas. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), '!=', self::STATUS_LUNAS);
    }

    /**
     * Total sisa (amount - paid_amount) semua catatan AKTIF bertipe $type milik
     * user yang sedang login. Dipakai kartu statistik Dashboard — memakai model
     * yang sudah ter-scope OwnedByUserScope sehingga angkanya selalu per-user.
     */
    public static function activeTotalFor(string $type): float
    {
        $total = static::query()
            ->ofType($type)
            ->active()
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) AS total')
            ->value('total');

        return (float) $total;
    }
}
