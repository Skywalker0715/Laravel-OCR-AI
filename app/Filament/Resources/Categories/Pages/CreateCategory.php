<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Kategori baru yang dibuat user masuk sebagai kategori milik user itu
        // (bukan default sistem).
        $data['user_id'] = auth()->id();

        return $data;
    }
}
