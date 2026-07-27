<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Filament\Resources\Concerns\ConfiguresMediaSelects;
use App\Models\Article;
use App\Models\Author;
use App\Support\AdminTranslations;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ArticleForm
{
    use ConfiguresMediaSelects;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Hidden::make('slug'),
                Hidden::make('status')->default('draft'),
                Hidden::make('scheduled_at'),

                Section::make(__('admin.writer.story'))
                    ->description(__('admin.writer.story_help'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('admin.fields.title'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->placeholder(__('admin.writer.title_placeholder')),
                        TextInput::make('homepage_title')
                            ->label('Краток наслов за почетна')
                            ->helperText('Опционално. Канонскиот наслов на објавата останува непроменет.')
                            ->maxLength(255)
                            ->live(onBlur: true),
                        Placeholder::make('editorial_title_guidance')
                            ->label('Уредувачка проверка')
                            ->content(fn (Get $get): string => self::titleGuidance((string) ($get('homepage_title') ?: $get('title')))),
                        FileUpload::make('featured_image_upload')
                            ->label(__('admin.fields.featured_image'))
                            ->helperText(__('admin.writer.image_help'))
                            ->disk('public')
                            ->directory('media')
                            ->visibility('public')
                            ->image()
                            ->imageEditor()
                            ->imagePreviewHeight('260')
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                            ->downloadable()
                            ->openable()
                            ->previewable()
                            ->columnSpanFull(),
                        self::mediaAssetSelect(
                            'featured_image_id',
                            'featuredImage',
                            __('admin.writer.choose_from_library'),
                        )
                            ->helperText(__('admin.writer.library_help'))
                            ->columnSpanFull(),
                        Textarea::make('excerpt')
                            ->label(__('admin.fields.excerpt'))
                            ->helperText(__('admin.writer.excerpt_help'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('homepage_excerpt')
                            ->label('Краток опис за почетна')
                            ->helperText('Опционално. Се користи само на картички; оригиналниот краток опис останува непроменет.')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        RichEditor::make('body_html')
                            ->label(__('admin.fields.body'))
                            ->helperText(__('admin.writer.body_help'))
                            ->extraInputAttributes(['style' => 'min-height: 28rem'])
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make(__('admin.writer.organize'))
                    ->description(__('admin.writer.organize_help'))
                    ->schema([
                        Select::make('author_id')
                            ->label(__('admin.fields.author'))
                            ->relationship('author', 'name', modifyQueryUsing: fn (Builder $query, ?Article $record): Builder => self::scopedAuthorOptions($query, $record))
                            ->default(fn (): ?int => auth()->user()?->teamMember?->author_id
                                ?? Author::query()
                                    ->where('is_active', true)
                                    ->where('email', auth()->user()?->email)
                                    ->value('id'))
                            ->disabled(fn (): bool => auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor'))
                            ->mutateStateForValidationUsing(fn ($state): mixed => (auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor'))
                                ? auth()->user()?->teamMember?->author_id
                                : $state)
                            ->dehydrated()
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('category_id')
                            ->label(__('admin.fields.category'))
                            ->relationship('category', 'name', modifyQueryUsing: fn ($query) => $query
                                ->where('is_active', true)
                                ->whereNotIn('slug', ['uncategorized', 'pocetna']))
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('tags')
                            ->label(__('admin.fields.tags'))
                            ->relationship('tags', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        Select::make('type')
                            ->label(__('admin.fields.type'))
                            ->options(AdminTranslations::articleTypes())
                            ->default('editorial')
                            ->required(),
                        Select::make('presentation_type')
                            ->label('Приказ на објавата')
                            ->options([
                                'standard' => 'Стандардна објава',
                                'event_guide' => 'Практичен водич за настани',
                                'interview' => 'Интервју',
                                'column' => 'Колумна',
                            ])
                            ->default('standard')
                            ->required()
                            ->live(),
                    ])
                    ->columns(2),

                Section::make('SEO')
                    ->description('Податоци за пребарувачи и споделување. Оваа секција е достапна само за уреднички улоги.')
                    ->relationship('seoMetadata')
                    ->schema([
                        TextInput::make('seo_title')
                            ->label(__('admin.fields.seo_title'))
                            ->helperText('Опционален наслов за резултатите од пребарување.'),
                        Textarea::make('meta_description')
                            ->label(__('admin.fields.meta_description'))
                            ->helperText('Опционален опис за пребарувачи и споделување.')
                            ->rows(3),
                        TextInput::make('canonical_url')
                            ->label('Канонска адреса')
                            ->url(),
                        TextInput::make('og_title')
                            ->label('Open Graph наслов'),
                        Textarea::make('og_description')
                            ->label('Open Graph опис')
                            ->rows(3),
                        self::mediaAssetSelect(
                            'og_image_id',
                            'ogImage',
                            'Open Graph слика',
                        )->columnSpanFull(),
                    ])
                    ->visible(fn (?Article $record): bool => self::canManageSeo($record))
                    ->saveRelationshipsWhenHidden(false)
                    ->columns(2)
                    ->collapsed()
                    ->collapsible(),

                Section::make('Водич за настани')
                    ->description('Структурирани податоци за практични културни водичи. Не внесувајте непотврдени детали.')
                    ->schema([
                        TextInput::make('guide_metadata.meta.period_label')
                            ->label('Период')
                            ->placeholder('20 до 26 јули')
                            ->maxLength(120),
                        TextInput::make('guide_metadata.meta.city_label')
                            ->label('Град / регион')
                            ->placeholder('Битола')
                            ->maxLength(120),
                        DatePicker::make('guide_metadata.meta.last_verified_at')
                            ->label('Последна проверка')
                            ->native(false),
                        Placeholder::make('guide_warnings')
                            ->label('Проверка')
                            ->content(fn (Get $get): string => self::guideWarnings($get('guide_metadata') ?? []))
                            ->columnSpanFull(),
                        Repeater::make('guide_metadata.events')
                            ->label('Настани')
                            ->schema([
                                TextInput::make('id')
                                    ->label('Внатрешен клуч')
                                    ->helperText('Опционално. Се користи само за стабилен редослед и аналитика.')
                                    ->maxLength(160),
                                TextInput::make('title')
                                    ->label('Наслов')
                                    ->required()
                                    ->maxLength(255),
                                DatePicker::make('start_date')
                                    ->label('Почетен датум')
                                    ->native(false),
                                DatePicker::make('end_date')
                                    ->label('Краен датум')
                                    ->native(false)
                                    ->afterOrEqual('start_date'),
                                TextInput::make('time')
                                    ->label('Време')
                                    ->placeholder('20:30')
                                    ->maxLength(80),
                                TextInput::make('location')
                                    ->label('Локација')
                                    ->maxLength(160),
                                TextInput::make('price_label')
                                    ->label('Цена')
                                    ->placeholder('600 денари')
                                    ->maxLength(120),
                                Textarea::make('short_description')
                                    ->label('Краток опис')
                                    ->rows(3)
                                    ->maxLength(500)
                                    ->columnSpanFull(),
                                TextInput::make('source_label')
                                    ->label('Извор')
                                    ->maxLength(160),
                                TextInput::make('source_url')
                                    ->label('Линк до извор')
                                    ->url()
                                    ->maxLength(500),
                                TextInput::make('booking_label')
                                    ->label('Текст за резервација')
                                    ->maxLength(120),
                                TextInput::make('booking_url')
                                    ->label('Линк за резервација')
                                    ->url()
                                    ->maxLength(500),
                                TextInput::make('display_order')
                                    ->label('Редослед')
                                    ->integer()
                                    ->default(0),
                            ])
                            ->columns(2)
                            ->reorderable()
                            ->defaultItems(0)
                            ->columnSpanFull(),
                        Repeater::make('guide_metadata.practical_notes')
                            ->label('Практични белешки')
                            ->simple(TextInput::make('note')->maxLength(240))
                            ->defaultItems(0)
                            ->columnSpanFull(),
                        Repeater::make('guide_metadata.sources')
                            ->label('Проверени извори')
                            ->schema([
                                TextInput::make('label')
                                    ->label('Назив')
                                    ->required()
                                    ->maxLength(160),
                                TextInput::make('url')
                                    ->label('Линк')
                                    ->required()
                                    ->url()
                                    ->maxLength(500),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Get $get): bool => $get('presentation_type') === 'event_guide')
                    ->columns(3)
                    ->collapsible(),

                Section::make(__('admin.writer.advanced'))
                    ->description(__('admin.writer.advanced_help'))
                    ->schema([
                        Toggle::make('is_sponsored')
                            ->label(__('admin.fields.is_sponsored'))
                            ->default(false)
                            ->live(),
                        Section::make(__('admin.sections.sponsor_details'))
                            ->relationship('sponsoredContentDetail')
                            ->schema([
                                TextInput::make('sponsor_name')
                                    ->label(__('admin.writer.sponsor_name'))
                                    ->required(),
                                TextInput::make('sponsor_url')
                                    ->label(__('admin.writer.sponsor_url'))
                                    ->url(),
                                Select::make('disclosure_label')
                                    ->label(__('admin.writer.disclosure_label'))
                                    ->options([
                                        'Спонзорирана објава' => 'Спонзорирана објава',
                                        'ПР текст' => 'ПР текст',
                                        'Платена промоција' => 'Платена промоција',
                                    ])
                                    ->in([
                                        'Спонзорирана објава',
                                        'ПР текст',
                                        'Платена промоција',
                                    ])
                                    ->default('Спонзорирана објава')
                                    ->required(),
                            ])
                            ->visible(fn (Get $get): bool => self::requiresSponsorDetails($get))
                            ->saveRelationshipsWhenHidden(false)
                            ->columns(2),
                    ])
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    private static function requiresSponsorDetails(Get $get): bool
    {
        return (bool) $get('../../is_sponsored')
            || in_array($get('../../type'), ['sponsored', 'pr'], true)
            || (bool) $get('is_sponsored')
            || in_array($get('type'), ['sponsored', 'pr'], true);
    }

    private static function scopedAuthorOptions(Builder $query, ?Article $record = null): Builder
    {
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin() || $user->hasRole('editor_in_chief')) {
            return $query->where('is_active', true);
        }

        if ($user->hasRole('editor')) {
            return $query->where(function (Builder $query) use ($user, $record): void {
                $query->where(fn (Builder $managed): Builder => $managed
                    ->where('is_active', true)
                    ->whereIn(
                        'authors.id',
                        $user->editorialSupervisions()->active()->select('author_id'),
                    ));

                if ($user->teamMember?->author_id) {
                    $query->orWhere('authors.id', $user->teamMember->author_id);
                }

                if ($record?->author_id) {
                    $query->orWhere('authors.id', $record->author_id);
                }
            });
        }

        if ($user->hasRole('author') || $user->hasRole('contributor')) {
            return $query->whereKey($user->teamMember?->author_id ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }

    private static function canManageSeo(?Article $record = null): bool
    {
        $user = auth()->user();

        if (! $user || ! $user->hasPermission('seo.manage')) {
            return false;
        }

        if ($user->isAdmin() || $user->hasRole('editor_in_chief')) {
            return true;
        }

        if (! $user->hasRole('editor')) {
            return false;
        }

        return $record === null || $user->can('manageSeo', $record);
    }

    private static function titleGuidance(string $title): string
    {
        $length = mb_strlen(trim($title));

        if ($length > 110) {
            return "Силен совет: скратете го насловот за почетна ({$length} знаци; цел до 85).";
        }

        if ($length > 85) {
            return "Совет: насловот за почетна има {$length} знаци; препорачано е до 85.";
        }

        return $length > 0 ? "Насловот за почетна има {$length} знаци." : 'Внесете наслов за да ја видите проверката.';
    }

    /** @param array<string, mixed> $metadata */
    private static function guideWarnings(array $metadata): string
    {
        // Filament repeaters may use UUID-like string keys while the form is live.
        // Normalising to a list keeps the display index predictable and avoids a
        // TypeError when guide metadata came from an associative payload.
        $events = collect($metadata['events'] ?? [])->values();
        $warnings = [];

        $events->each(function ($event, int $index) use (&$warnings): void {
            if (! is_array($event)) {
                return;
            }

            $label = 'Настан '.($index + 1);

            if (blank($event['title'] ?? null)) {
                $warnings[] = "{$label}: недостига наслов.";
            }

            if (blank($event['start_date'] ?? null) && blank($event['end_date'] ?? null)) {
                $warnings[] = "{$label}: нема внесен датум.";
            }

            if (filled($event['source_label'] ?? null) && blank($event['source_url'] ?? null)) {
                $warnings[] = "{$label}: има назив на извор без линк.";
            }
        });

        return $warnings === []
            ? 'Нема критични предупредувања. Проверете ги датумите, изворите и линковите пред објава.'
            : implode(' ', $warnings);
    }
}
