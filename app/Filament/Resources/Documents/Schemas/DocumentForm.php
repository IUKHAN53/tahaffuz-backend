<?php

namespace App\Filament\Resources\Documents\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('knowledge_base_id')
                    ->relationship('knowledgeBase', 'name')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                FileUpload::make('source_path')
                    ->disk('local')
                    ->directory('documents')
                    ->preserveFilenames()
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'text/plain',
                        'text/markdown',
                    ])
                    ->maxSize(20 * 1024)
                    ->required(),
            ]);
    }
}
