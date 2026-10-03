<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Kategori belanja; bisa milik user tertentu (user_id terisi) atau default sistem
 * (user_id NULL). Semua user melihat kategori default + kategori miliknya sendiri.
 */
class Category extends Model
{
    /**
     * Kategori default sistem yang dibuat oleh CategorySeeder. Nama & warna
     * dipakai pula oleh findOrCreateByName() agar kategori hasil tebakan AI
     * konsisten dengan daftar kategori bawaan.
     */
    public const DEFAULT_CATEGORIES = [
        ['name' => 'Makanan & Minuman', 'icon' => 'o-shopping-cart', 'color' => '#10B981'],
        ['name' => 'Transportasi', 'icon' => 'o-truck', 'color' => '#3B82F6'],
        ['name' => 'Belanja Rumah Tangga', 'icon' => 'o-shopping-bag', 'color' => '#F59E0B'],
        ['name' => 'Kesehatan', 'icon' => 'o-heart', 'color' => '#EF4444'],
        ['name' => 'Hiburan', 'icon' => 'o-film', 'color' => '#8B5CF6'],
        ['name' => 'Lainnya', 'icon' => 'o-ellipsis-horizontal', 'color' => '#64748B'],
    ];

    /**
     * Sinonim → nama kategori kanonik untuk menebak kategori dari label AI atau teks
     * struk (fallback parser); dicocokkan case-insensitive sebagai substring.
     * @var array<string, array<int, string>>
     */
    private const CATEGORY_SYNONYMS = [
        'Makanan & Minuman' => [
            'makanan & minuman', 'makanan dan minuman', 'makanan', 'makan', 'minuman',
            'makanan kemasan', 'snack', 'camilan', 'cafe', 'kafe', 'restoran', 'restaurant',
            'kuliner', 'minum', 'indomaret', 'indomar', 'indomart', 'alfamart', 'alamart',
            'minimarket', 'supermarket', 'sembako', 'sayur', 'daging', 'nasi', 'mie', 'kopi',
            'susu', 'roti', 'biskuit', 'cokelat', 'permen',
        ],
        'Transportasi' => [
            'transportasi', 'transport', 'bensin', 'bbm', 'spbu', 'pertamina', 'bahan bakar',
            'parkir', 'tol', 'taxi', 'taksi', 'gojek', 'tiket', 'ojek', 'ojol', 'bus',
            'kereta', 'toll', 'grab', 'pangkalan',
        ],
        'Belanja Rumah Tangga' => [
            'belanja rumah tangga', 'rumah tangga', 'household', 'keperluan rumah', 'sembako',
            'bahan pokok', 'deterjen', 'sabun', 'detergent', 'pembersih', 'sapu', 'pel',
            'kain', 'masker', 'handuk', 'perlengkapan',
        ],
        'Kesehatan' => [
            'kesehatan', 'health', 'apotek', 'pharmacy', 'obat', 'farmasi', 'vitamin',
            'dokter', 'klinik', 'rumah sakit', 'rs', 'kesehatan', 'darurat',
        ],
        'Hiburan' => [
            'hiburan', 'entertainment', 'game', 'gaming', 'steam', 'playstation', 'netflix',
            'spotify', 'bioskop', 'film', 'konser', 'karaoke', 'permainan',
        ],
        'Lainnya' => [
            'lainnya', 'lain-lain', 'other', 'lain',
        ],
    ];

    protected $fillable = [
        'name',
        'icon',
        'color',
        'user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            // Guard anti-spoofing tanpa merusak kategori default: user_id NULL disengaja
            // (dipakai lintas user) dan tidak diisi paksa dari Auth — hanya user_id milik
            // user lain yang ditimpa. Aman untuk queue sync (user_id di-set eksplisit).
            if (Auth::check()
                && $category->user_id !== null
                && (int) $category->user_id !== (int) Auth::id()) {
                $category->user_id = Auth::id();
            }
        });
    }

    /** Semua personalisasi tampilan kategori ini. */
    public function appearanceOverrides(): HasMany
    {
        return $this->hasMany(CategoryAppearanceOverride::class);
    }

    /**
     * Eager-load personalisasi tampilan milik SATU user saja, sehingga
     * displayIconFor()/displayColorFor() tidak memicu query per kategori
     * (N+1) saat dipakai pada tabel, grafik, atau PDF.
     *
     * Dipakai di halaman yang memanggil display*For() untuk tiap baris:
     * CategoryResource, tabel Budgets, dan query expense halaman Laporan.
     *
     * @param  Builder  $query  query kategori (induk atau nested eager load)
     */
    public function scopeWithAppearanceOverridesFor(Builder $query, ?int $userId): Builder
    {
        return $query->with([
            'appearanceOverrides' => fn (Relation $relation): Relation => $relation->where('user_id', $userId),
        ]);
    }

    /** Ikon yang seharusnya dilihat oleh user tertentu. */
    public function displayIconFor(User $user): ?string
    {
        return $this->appearanceOverrideFor($user)?->icon ?? $this->icon;
    }

    /** Warna yang seharusnya dilihat oleh user tertentu. */
    public function displayColorFor(User $user): ?string
    {
        return $this->appearanceOverrideFor($user)?->color ?? $this->color;
    }

    private function appearanceOverrideFor(User $user): ?CategoryAppearanceOverride
    {
        if ($this->relationLoaded('appearanceOverrides')) {
            return $this->appearanceOverrides->firstWhere('user_id', $user->getKey());
        }

        return $this->appearanceOverrides()
            ->where('user_id', $user->getKey())
            ->first();
    }

    /**
     * Tebak nama kategori kanonik dari sebuah label/teks. Fungsi murni (tanpa DB),
     * aman dipakai AIParserService untuk jalur AI maupun fallback regex;
     * tanpa kecocokan mengembalikan "Lainnya".
     */
    public static function inferCategoryName(?string $text): string
    {
        $haystack = mb_strtolower(trim((string) $text));

        if ($haystack === '') {
            return 'Lainnya';
        }

        foreach (self::CATEGORY_SYNONYMS as $name => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($haystack, $alias)) {
                    return $name;
                }
            }
        }

        return 'Lainnya';
    }

    /**
     * Resolusi label kategori → instance kategori nyata di database: cocokkan ke nama
     * kanonik via inferCategoryName(), lalu findOrCreateByName(). NULL bila gagal dibuat.
     */
    public static function resolveFromLabel(?string $label, ?int $userId): ?self
    {
        $name = self::inferCategoryName($label);

        return self::findOrCreateByName($name, $userId);
    }

    /**
     * Cari kategori berdasarkan nama; buat baru bila belum ada. Urutan: default sistem
     * (user_id NULL) didahulukan agar semua user memakai instance sama, lalu milik user,
     * lalu buat baru (default sistem bila nama terdaftar di DEFAULT_CATEGORIES).
     *
     * TAHAN RACE: bila proses/request lain menyisipkan kategori dengan nama+owner
     * yang sama tepat SETELAH pengecekan di atas (tapi sebelum INSERT di sini),
     * unique index `categories_user_name_unique` /
     * `categories_default_name_unique` menolak INSERT tersebut. Pelanggaran unik
     * itu ditangkap, lalu baris yang sudah ada diambil sebagai hasil — pemanggil
     * tidak pernah melihat exception dan tetap mendapat SATU baris kategori.
     */
    public static function findOrCreateByName(string $name, ?int $userId): ?self
    {
        $existing = self::findExistingByName($name, $userId);

        if ($existing) {
            return $existing;
        }

        $default = collect(self::DEFAULT_CATEGORIES)->first(
            fn (array $item): bool => $item['name'] === $name
        );

        try {
            return self::create([
                'name' => $name,
                'icon' => $default['icon'] ?? null,
                'color' => $default['color'] ?? '#64748B',
                // Kategori terdaftar sebagai default sistem jadi milik bersama
                // (user_id NULL); kategori baru di luar daftar jadi milik user
                // yang memicunya agar tidak mencemari daftar user lain.
                'user_id' => $default ? null : $userId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Balapan: baris sudah dibuat proses lain sesaat sebelumnya — pakai
            // baris itu. Bila ternyata baris tersebut sudah terhapus lagi
            // (kasus langka), kembalikan null seperti kegagalan create biasa.
            return self::findExistingByName($name, $userId);
        }
    }

    /**
     * Pencarian kategori yang sudah ada: default sistem (user_id NULL) lebih
     * dulu, lalu milik $userId. Dipakai sebelum INSERT dan lagi setelah
     * menangkap pelanggaran unique index (jalur balapan) supaya logikanya satu
     * sumber kebenaran.
     */
    private static function findExistingByName(string $name, ?int $userId): ?self
    {
        $shared = self::query()
            ->where('name', $name)
            ->whereNull('user_id')
            ->first();

        if ($shared) {
            return $shared;
        }

        if ($userId === null) {
            return null;
        }

        return self::query()
            ->where('name', $name)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Kategori fallback "Lainnya" (default sistem) — dipakai command artisan
     * untuk memberi kategori pada expense lama yang masih kosong.
     */
    public static function fallbackCategory(): ?self
    {
        return self::resolveFromLabel('Lainnya', null);
    }

    /**
     * Pemilik kategori (null untuk kategori default sistem).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(related: User::class);
    }

    /**
     * Seluruh expense yang memakai kategori ini.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(related: Expense::class);
    }

    /**
     * Total nominal (SUM amount) expense milik $userId pada kategori ini. where('user_id')
     * eksplisit karena kategori default dipakai lintas user; amount NULL (parsing gagal)
     * tidak dijumlahkan — konsisten dengan Budget::spentAmount().
     */
    public function totalExpenseAmountForUser(?int $userId): float
    {
        if ($userId === null) {
            return 0.0;
        }

        return (float) $this->expenses()
            ->where('user_id', $userId)
            ->whereNotNull('amount')
            ->sum('amount');
    }

    /**
     * Jumlah expense milik $userId pada kategori ini.
     *
     * CATATAN: angka ini bisa BERBEDA dari kolom "Jumlah Transaksi" di tabel
     * list, dan itu memang disengaja:
     *  - Kolom tabel memakai `->counts('expenses')`. Relasi `expenses()` mewarisi
     *    global scope `OwnedByUserScope` milik model Expense, jadi saat ada
     *    sesi login angka tabel pun ter-scope ke user yang sedang login.
     *  - Method ini menambah filter `where('user_id', $userId)` eksplisit di
     *    atas scope tersebut, sehingga hasilnya sama di konteks yang TIDAK
     *    punya sesi login (console/queue) dan tidak pernah ikut berubah bila
     *    pemanggil kebetulan memakai id user lain.
     *
     * Kedua angka tetap sama untuk user yang sedang login saat dipakai di
     * View; perbedaan baru muncul bila dipanggil dari konteks tanpa auth.
     * $userId null = 0 (tidak ada user yang bisa memiliki expense).
     */
    public function expenseCountForUser(?int $userId): int
    {
        if ($userId === null) {
            return 0;
        }

        return $this->expenses()
            ->where('user_id', $userId)
            ->count();
    }

    /**
     * $limit transaksi terakhir milik $userId pada kategori ini (urut created_at).
     * Sengaja bukan date_shopping: bisa NULL dan perilaku NULLS FIRST/LAST beda
     * antar driver DB — latest() aman di semua driver.
     */
    public function recentExpensesForUser(?int $userId, int $limit = 5): Collection
    {
        if ($userId === null) {
            return collect();
        }

        return $this->expenses()
            ->where('user_id', $userId)
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Budget terbaru kategori ini untuk $userId, apa pun periodenya — tidak dibatasi
     * bulan berjalan agar budget periode lampau tetap tampil; urut year DESC, month
     * DESC; where('user_id') eksplisit sebagai safety net di atas global scope.
     */
    public function latestBudgetForUser(?int $userId): ?Budget
    {
        if ($userId === null) {
            return null;
        }

        return Budget::query()
            ->where('user_id', $userId)
            ->where('category_id', $this->getKey())
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();
    }
}
