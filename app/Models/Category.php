<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Kategori belanja untuk mengklasifikasikan pengeluaran.
 *
 * Sebuah kategori bisa dimiliki oleh seorang user tertentu (user_id terisi)
 * atau menjadi kategori default yang berlaku sistem (user_id NULL). Semua
 * user dapat melihat kategori default + kategori milik dirinya sendiri.
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
     * Kata kunci sinonim → nama kategori kanonik.
     *
     * Dipakai untuk menebak kategori dari label yang dikembalikan AI ataupun
     * dari teks mentah struktur (fallback parser). Semua alias dicocokkan
     * secara tidak peka huruf (substring), jadi kalimat seperti "Minuman
     * Kemasan" akan jatuh ke kategori "Makanan & Minuman".
     *
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
            // Guard anti-spoofing TANPA merusak semantik kategori default:
            // user_id NULL adalah nilai yang disengaja (kategori default
            // sistem dipakai lintas user), jadi tidak boleh diisi paksa dari
            // Auth. Yang dipaksa hanya KETIDAKCOCOKAN — di konteks
            // terautentikasi, user_id milik user lain selalu ditimpa dengan
            // user yang sedang login. Aman untuk queue sync (AIParserJob →
            // findOrCreateByName()): user_id di-set eksplisit di sana dan
            // selalu milik pemilik expense (atau tetap NULL untuk default).
            if (Auth::check()
                && $category->user_id !== null
                && (int) $category->user_id !== (int) Auth::id()) {
                $category->user_id = Auth::id();
            }
        });
    }

    /**
     * Tebak nama kategori kanonik dari sebuah label/teks.
     *
     * Fungsi murni (tidak menyentuh database) sehingga aman dipakai oleh
     * AIParserService untuk dua jalur sekaligus: hasil AI maupun fallback
     * regex. Jika tidak ada kata kunci yang cocok, dikembalikan "Lainnya".
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
     * Resolusi label kategori → instance kategori nyata di database.
     *
     * Dua langkah: (1) cocokkan label ke nama kanonik via inferCategoryName(),
     * (2) cari kategori tersebut lalu buat baru bila belum ada. return null
     * terjadi ketika record kategori gagal dibuat (jarang).
     */
    public static function resolveFromLabel(?string $label, ?int $userId): ?self
    {
        $name = self::inferCategoryName($label);

        return self::findOrCreateByName($name, $userId);
    }

    /**
     * Cari kategori berdasarkan nama; buat baru bila belum ada.
     *
     * Urutan pencarian:
     * 1. Kategori default sistem (user_id NULL) — berlaku lintas user dan
     *    lebih diutamakan agar semua user memakai instance kategori sama.
     * 2. Kategori milik user tersebut (user_id = $userId).
     * 3. Buat baru: sebagai default sistem untuk nama yang terdaftar di
     *    DEFAULT_CATEGORIES, atau milik user untuk nama di luar daftar.
     */
    public static function findOrCreateByName(string $name, ?int $userId): ?self
    {
        $existing = self::query()
            ->where('name', $name)
            ->whereNull('user_id')
            ->first();

        if ($existing) {
            return $existing;
        }

        if ($userId !== null) {
            $userOwned = self::query()
                ->where('name', $name)
                ->where('user_id', $userId)
                ->first();

            if ($userOwned) {
                return $userOwned;
            }
        }

        $default = collect(self::DEFAULT_CATEGORIES)->first(
            fn (array $item): bool => $item['name'] === $name
        );

        return self::create([
            'name' => $name,
            'icon' => $default['icon'] ?? null,
            'color' => $default['color'] ?? '#64748B',
            // Kategori terdaftar sebagai default sistem jadi milik bersama
            // (user_id NULL); kategori baru di luar daftar jadi milik user
            // yang memicunya agar tidak mencemari daftar user lain.
            'user_id' => $default ? null : $userId,
        ]);
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
     * Total nominal (SUM amount) seluruh expense milik $userId yang memakai
     * kategori ini.
     *
     * Query dibatasi eksplisit ke user tersebut (di atas global scope
     * OwnedByUserScope pada Expense) karena kategori default sistem dipakai
     * lintas user — tanpa ini total akan mencampur pengeluaran user lain.
     * Expense yang parsing-nya gagal total (amount NULL) tidak ikut
     * dijumlahkan, konsisten dengan perhitungan Budget::spentAmount().
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
     * Jumlah transaksi (expense) milik $userId yang memakai kategori ini.
     *
     * Sengaja dipisahkan dari kolom "Jumlah Transaksi" di tabel list (yang
     * menghitung lintas user karena kategori default dipakai bersama): pada
     * halaman View angka ini harus konsisten dengan Total Pengeluaran yang
     * berada di Section yang sama, yaitu khusus milik user yang login.
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
     * $limit transaksi terakhir milik $userId yang memakai kategori ini,
     * diurutkan dari yang paling baru dicatat (created_at).
     *
     * Pengurutan sengaja TIDAK memakai date_shopping karena tanggal belanja
     * bisa NULL (struk yang parsing-nya belum selesai) dan perilaku
     * NULLS FIRST/LAST berbeda antara PostgreSQL dan MySQL — `latest()`
     * dijamin aman di semua driver database.
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
     * Budget TERBARU kategori ini untuk $userId, apa pun periodenya.
     *
     * Sengaja TIDAK dibatasi ke bulan berjalan — kategori mungkin punya
     * budget di periode lampau (mis. Mei 2025) dan sudah tidak ada di
     * periode sekarang; section "Budget" di halaman View Category tetap
     * harus menampilkannya. Query diurutkan year DESC lalu month DESC dan
     * mengambil 1 baris teratas.
     *
     * Global scope OwnedByUserScope pada model Budget sudah membatasi query
     * ke budget milik user yang login; kondisi user_id eksplisit ditambahkan
     * sebagai safety net yang self-documenting (pola sama dengan
     * Budget::spentAmount()).
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
