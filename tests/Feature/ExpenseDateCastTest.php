<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('date_shopping di-cast sebagai tanggal (Carbon)', function () {
    $user = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja',
        'date_shopping' => '2023-08-04',
        'amount' => 25000,
    ]);

    $expense->refresh();

    expect($expense->date_shopping)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    expect($expense->date_shopping->toDateString())->toBe('2023-08-04');
});

test('Budget::spentAmount bekerja pada kolom tanggal dan hanya menghitung periode yang sama', function () {
    $user = User::factory()->create();
    $category = Category::resolveFromLabel('Makanan & Minuman', $user->id);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Agustus',
        'date_shopping' => '2023-08-04',
        'amount' => 30000,
    ]);
    Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'September',
        'date_shopping' => '2023-09-01',
        'amount' => 5000,
    ]);

    $budget = Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 100000,
        'month' => 8,
        'year' => 2023,
    ]);

    // Hanya expense bulan Agustus 2023 yang dihitung untuk budget ini.
    expect($budget->spentAmount())->toBe(30000.0);
});