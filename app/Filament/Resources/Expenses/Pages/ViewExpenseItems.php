<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Resources\Pages\ViewRecord;

class ViewExpenseItems extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    // Remove the static $view property to avoid conflict
    // Instead, override the render method to return the blade view

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('filament.components.item-list', [
            'items' => $this->record->items,
            'expense' => $this->record,
        ]);
    }
}
