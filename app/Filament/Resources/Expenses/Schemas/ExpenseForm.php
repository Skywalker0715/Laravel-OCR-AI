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
                    ->required()
                    ->label('Judul Belanja')
                    ->placeholder('Masukkan judul belanja...'),

                FileUpload::make('receipt_image')
                    ->label('Foto Struk')
                    ->image()
                    ->disk('public')
                    ->required()
                    ->directory('receipts')
                    ->imagePreviewHeight('250')
                    ->loadingIndicatorPosition('left')
                    ->panelAspectRatio('2:1')
                    ->panelLayout('integrated')
                    ->removeUploadedFileButtonPosition('right')
                    ->uploadButtonPosition('left')
                    ->uploadProgressIndicatorPosition('left'),

                // Field teknis disembunyikan dari form, akan diisi otomatis oleh sistem
                TextInput::make('date_shopping')
                    ->hidden(),

                TextInput::make('change')
                    ->hidden()
                    ->numeric(),

                TextInput::make('amount')
                    ->hidden()
                    ->numeric(),

                Textarea::make('note')
                    ->hidden()
                    ->columnSpanFull(),

                TextInput::make('parsed_data')
                    ->hidden(),
            ]);
    }
}
