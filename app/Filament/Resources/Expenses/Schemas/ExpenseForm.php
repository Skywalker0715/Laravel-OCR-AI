<?php

namespace App\Filament\Resources\Expenses\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                TextInput::make('date_shopping'),
                TextInput::make('change')
                    ->numeric(),
                TextInput::make('amount')
                    ->numeric(),
                Textarea::make('note')
                    ->columnSpanFull(),
                FileUpload::make('receipt_image')
                    ->image()
                    ->disk('public'),
                TextInput::make('parsed_data'),
            ]);
    }
}
