<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Support\AdminTranslations;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ArticleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('title')->label(__('admin.fields.title')),
                TextEntry::make('slug')->label(__('admin.fields.slug')),
                TextEntry::make('excerpt')
                    ->label(__('admin.fields.excerpt'))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('body_html')
                    ->label(__('admin.fields.body'))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('status')
                    ->label(__('admin.fields.status'))
                    ->formatStateUsing(fn (?string $state): string => AdminTranslations::articleStatus($state)),
                TextEntry::make('type')
                    ->label(__('admin.fields.type'))
                    ->formatStateUsing(fn (?string $state): string => AdminTranslations::articleType($state)),
                TextEntry::make('author.name')
                    ->label(__('admin.fields.author'))
                    ->placeholder('-'),
                TextEntry::make('category.name')
                    ->label(__('admin.fields.category'))
                    ->placeholder('-'),
                TextEntry::make('featuredImage.display_name')
                    ->label(__('admin.fields.featured_image'))
                    ->placeholder('-'),
                TextEntry::make('published_at')
                    ->label(__('admin.fields.published_at'))
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('scheduled_at')
                    ->label(__('admin.fields.scheduled_at'))
                    ->dateTime()
                    ->placeholder('-'),
                IconEntry::make('is_sponsored')
                    ->label(__('admin.fields.is_sponsored'))
                    ->boolean(),
                IconEntry::make('is_ai_generated')
                    ->boolean(),
                IconEntry::make('is_ai_assisted')
                    ->boolean(),
                TextEntry::make('sponsoredContentDetail.sponsor_name')
                    ->label('Sponsor')
                    ->placeholder('-'),
                TextEntry::make('sponsoredContentDetail.disclosure_label')
                    ->label('Disclosure')
                    ->placeholder('-'),
                TextEntry::make('old_wordpress_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('old_url')
                    ->placeholder('-'),
                TextEntry::make('created_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('updated_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->label(__('admin.fields.created_at'))
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label(__('admin.fields.updated_at'))
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
