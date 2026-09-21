<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Category $category */
        $category = $this->record;
        $user = auth()->user();

        if ($category->user_id === null && $user instanceof \App\Models\User) {
            $data['icon'] = $category->displayIconFor($user);
            $data['color'] = $category->displayColorFor($user);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Category $record */
        if ($record->user_id === null) {
            $record->appearanceOverrides()->updateOrCreate(
                ['user_id' => auth()->id()],
                ['icon' => $data['icon'] ?? null, 'color' => $data['color'] ?? null],
            );

            return $record;
        }

        return parent::handleRecordUpdate($record, $data);
    }
}
