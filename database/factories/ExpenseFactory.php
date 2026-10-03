<?php

namespace Database\Factories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory expense untuk pengujian pada volume besar (mis. 3.000 transaksi).
 *
 * Hanya dipakai di test — tidak ada seeding produksi yang memakainya, supaya
 * data contoh tetap realistis.
 *
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * Tanggal tersebar selama ±3 tahun supaya agregasi per tahun/bulan di
     * FinancialInsightService benar-benar punya banyak bucket untuk diuji.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Belanja '.fake()->randomElement(['Harian', 'Bulgur', 'Sembako', 'Transport', 'Perlengkapan']),
            'amount' => fake()->numberBetween(5_000, 750_000),
            'date_shopping' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'vendor' => 'Toko '.fake()->randomElement(['A', 'B', 'C', 'D', 'E', 'F']),
        ];
    }
}
