<?php

namespace App\Filament\Resources\Authors\Schemas;

use App\Filament\Resources\Concerns\ConfiguresMediaSelects;
use App\Models\Author;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AuthorForm
{
    use ConfiguresMediaSelects;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.sections.profile'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin.fields.name'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),
                        TextInput::make('slug')
                            ->label(__('admin.fields.slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('sex')
                            ->label(__('admin.fields.sex'))
                            ->options([
                                Author::SEX_MALE => __('admin.sex.male'),
                                Author::SEX_FEMALE => __('admin.sex.female'),
                            ])
                            ->default(Author::SEX_MALE)
                            ->required()
                            ->native(false)
                            ->helperText(__('admin.fields.sex_help')),
                        FileUpload::make('avatar_upload')
                            ->label(__('admin.fields.profile_photo_upload'))
                            ->helperText(__('admin.fields.profile_photo_upload_help'))
                            ->disk('public')
                            ->directory('media/authors')
                            ->visibility('public')
                            ->image()
                            ->imageEditor()
                            ->imagePreviewHeight('220')
                            ->maxSize(5120)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->downloadable()
                            ->openable()
                            ->previewable()
                            ->columnSpanFull(),
                        self::mediaAssetSelect('avatar_id', 'avatar', __('admin.fields.avatar'))
                            ->helperText(__('admin.fields.avatar_library_help'))
                            ->columnSpanFull(),
                        Toggle::make('use_default_avatar')
                            ->label(__('admin.fields.use_default_avatar'))
                            ->helperText(__('admin.fields.use_default_avatar_help'))
                            ->default(false)
                            ->columnSpanFull(),
                        TextInput::make('job_title')
                            ->label(__('admin.fields.job_title'))
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label(__('admin.fields.email'))
                            ->email()
                            ->maxLength(255),
                        Toggle::make('show_email')
                            ->label(__('admin.fields.show_email'))
                            ->default(false),
                        Toggle::make('is_active')
                            ->label(__('admin.fields.is_active'))
                            ->default(true),
                        TextInput::make('sort_order')
                            ->label(__('admin.fields.sort_order'))
                            ->integer()
                            ->default(0),
                    ])
                    ->columns(2),

                Section::make(__('admin.sections.bio'))
                    ->schema([
                        Textarea::make('short_bio')
                            ->label(__('admin.fields.short_bio'))
                            ->rows(3),
                        Textarea::make('full_bio')
                            ->label(__('admin.fields.full_bio'))
                            ->rows(8),
                    ])
                    ->columns(1),

                Section::make(__('admin.sections.social_links'))
                    ->schema([
                        TextInput::make('website_url')->label(__('admin.fields.website_url'))->url(),
                        TextInput::make('facebook_url')->label(__('admin.fields.facebook_url'))->url(),
                        TextInput::make('instagram_url')->label(__('admin.fields.instagram_url'))->url(),
                        TextInput::make('linkedin_url')->label(__('admin.fields.linkedin_url'))->url(),
                        TextInput::make('x_url')->label(__('admin.fields.x_url'))->url(),
                    ])
                    ->columns(2),

                Section::make('SEO')
                    ->schema([
                        TextInput::make('seo_title')
                            ->label(__('admin.fields.seo_title'))
                            ->maxLength(255),
                        Textarea::make('meta_description')
                            ->label(__('admin.fields.meta_description'))
                            ->rows(3),
                    ])
                    ->columns(1),
            ]);
    }
}
