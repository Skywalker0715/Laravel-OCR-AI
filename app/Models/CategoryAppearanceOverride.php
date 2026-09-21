<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tampilan kategori bawaan yang dipersonalisasi oleh satu user. */
class CategoryAppearanceOverride extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'icon',
        'color',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
