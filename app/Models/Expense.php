<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\ExpenseItem;

class Expense extends Model
{
    protected $fillable = [
        'title',
        'amount',
        'receipt_image',
        'note',
        'parsed_data',
        'date_shopping',
        'change',
        'vendor',
    ];

     public function items(): HasMany
    {
        return $this->hasMany(related: ExpenseItem::class, foreignKey: 'expenses_id');
    }
}
