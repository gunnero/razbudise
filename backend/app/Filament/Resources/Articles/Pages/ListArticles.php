<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListArticles extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    public function mount(): void
    {
        if (request()->boolean('resetFilters')) {
            session()->forget($this->getTableFiltersSessionKey());
            $this->tableFilters = [];
        }

        parent::mount();
    }

    /**
     * Version the saved table layout so existing newsroom users receive the
     * compact default columns introduced for the editorial workspace.
     */
    public function getTableColumnsSessionKey(): string
    {
        return 'tables.'.md5(static::class).'_columns_v2';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('admin.actions.create_article')),
        ];
    }
}
