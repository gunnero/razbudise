<?php

namespace App\Filament\Resources\Authors\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AuthorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('avatar.display_name')
                    ->label('Avatar')
                    ->placeholder(__('admin.fields.default_avatar')),
                TextEntry::make('sex')
                    ->label(__('admin.fields.sex'))
                    ->formatStateUsing(fn (?string $state): string => $state === 'female' ? __('admin.sex.female') : __('admin.sex.male'))
                    ->badge(),
                TextEntry::make('short_bio')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('full_bio')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('job_title')
                    ->placeholder('-'),
                TextEntry::make('articles_count')
                    ->label('Article count')
                    ->counts('articles')
                    ->numeric(),
                TextEntry::make('email')
                    ->label('Email address')
                    ->placeholder('-'),
                IconEntry::make('show_email')
                    ->boolean(),
                TextEntry::make('website_url')
                    ->placeholder('-'),
                TextEntry::make('facebook_url')
                    ->placeholder('-'),
                TextEntry::make('instagram_url')
                    ->placeholder('-'),
                TextEntry::make('linkedin_url')
                    ->placeholder('-'),
                TextEntry::make('x_url')
                    ->placeholder('-'),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('sort_order')
                    ->numeric(),
                TextEntry::make('seo_title')
                    ->placeholder('-'),
                TextEntry::make('meta_description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
