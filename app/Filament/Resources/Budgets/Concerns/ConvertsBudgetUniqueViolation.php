<?php

namespace App\Filament\Resources\Budgets\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Backstop pesan ramah untuk pelanggaran unique constraint anggaran.
 *
 * Rule `scopedUnique` pada BudgetForm sudah mencegah duplikat di alur normal
 * (dan pesannya sudah ramah). Trait ini menangani kasus BALAPAN: baris duplikat
 * tersisip setelah validasi form lolos tetapi sebelum INSERT/UPDATE dieksekusi
 * — pelanggaran unique dari DB diterjemahkan menjadi error validasi pada field
 * Bulan, bukan error SQL mentah yang membingungkan user.
 */
trait ConvertsBudgetUniqueViolation
{
    /**
     * Pesan sengaja IDENTIK dengan validationMessages('unique') di BudgetForm
     * agar pengalaman user sama, dari jalur mana pun error itu tertangkap.
     */
    protected function duplicateBudgetValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            'data.month' => 'Sudah ada anggaran untuk kategori dan periode (bulan + tahun) ini.',
        ]);
    }
}
