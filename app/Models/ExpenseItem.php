<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Expense;

class ExpenseItem extends Model
{
    // `expenses_id` SENGAJA tidak ada di $fillable: FK tidak boleh di-assign
    // sembarangan lewat mass assignment. FK diset otomatis oleh relasi
    // $expense->items()->create([...]) (HasMany::setAttribute, dipakai
    // AIParserJob) maupun Repeater Filament — keduanya tidak butuh
    // expenses_id di $fillable.
    protected $fillable = [
        'name',
        'qty',
        'price',
        'subtotal',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(related: Expense::class, foreignKey: 'expenses_id');
    }
}
