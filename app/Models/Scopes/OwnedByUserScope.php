<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Membatasi query Expense hanya pada data milik user yang sedang login.
 *
 * Scope ini sengaja hanya aktif ketika ada user yang login (Auth::check()),
 * sehingga query yang berjalan dari console/queue (tanpa konteks user)
 * tidak ikut terfilter dan tetap bisa dipakai untuk keperluan sistem.
 */
class OwnedByUserScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::check()) {
            $builder->where($model->qualifyColumn('user_id'), Auth::id());
        }
    }
}
