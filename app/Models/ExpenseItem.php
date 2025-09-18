<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Expense;

class ExpenseItem extends Model
{
    protected $fillable = [
        'expenses_id',
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
