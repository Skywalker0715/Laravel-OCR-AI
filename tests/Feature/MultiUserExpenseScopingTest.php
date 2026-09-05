<?php

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user hanya bisa melihat expense miliknya sendiri', function () {
    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    $expenseA = Expense::create(['title' => 'Belanja A', 'user_id' => $userA->id]);
    $expenseB = Expense::create(['title' => 'Belanja B', 'user_id' => $userB->id]);

    // User A hanya melihat expense miliknya.
    $this->actingAs($userA);
    expect(Expense::query()->pluck('id')->all())->toBe([$expenseA->id]);

    // User B hanya melihat expense miliknya.
    $this->actingAs($userB);
    expect(Expense::query()->pluck('id')->all())->toBe([$expenseB->id]);
});

test('create expense otomatis mengisi user_id dari user yang sedang login', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $expense = Expense::create(['title' => 'Belanja Baru']);

    expect($expense->user_id)->toBe($user->id);
});

test('route binding Filament tidak bisa resolve expense milik user lain', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $expenseA = Expense::create(['title' => 'Belanja A', 'user_id' => $userA->id]);

    // Query ini persis seperti yang dipakai Filament untuk halaman view/edit/delete.
    $this->actingAs($userA);
    expect(ExpenseResource::resolveRecordRouteBinding($expenseA->id))->not->toBeNull();

    $this->actingAs($userB);
    expect(ExpenseResource::resolveRecordRouteBinding($expenseA->id))->toBeNull();
});
