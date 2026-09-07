<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Membatasi query Expense hanya pada data milik user yang sedang login.
 * Sengaja nonaktif tanpa sesi login (console/queue) agar query sistem tetap berjalan;
 * perilaku scoping dikunci oleh MultiUserExpenseScopingTest.
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
