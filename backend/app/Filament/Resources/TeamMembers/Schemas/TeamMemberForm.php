<?php

namespace App\Filament\Resources\TeamMembers\Schemas;

use App\Filament\Resources\Concerns\ConfiguresMediaSelects;
use App\Models\TeamMember;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TeamMemberForm
{
    use ConfiguresMediaSelects;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('display_title')
                            ->maxLength(255),
                        Select::make('sex')
                            ->label(__('admin.fields.sex'))
                            ->options([
                                TeamMember::SEX_MALE => __('admin.sex.male'),
                                TeamMember::SEX_FEMALE => __('admin.sex.female'),
                            ])
                            ->default(TeamMember::SEX_MALE)
                            ->native(false)
                            ->helperText(__('admin.fields.team_member_sex_help')),
                        FileUpload::make('profile_image_upload')
                            ->label(__('admin.fields.profile_photo_upload'))
                            ->helperText(__('admin.fields.profile_photo_upload_help'))
                            ->disk('public')
                            ->directory('media/team')
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
                        self::mediaAssetSelect('profile_image_id', 'profileImage', 'Profile image'),
                        Select::make('primary_department_id')
                            ->relationship('primaryDepartment', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('manager_team_member_id')
                            ->label('Manager')
                            ->options(fn (?TeamMember $record): array => TeamMember::query()
                                ->when($record?->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->helperText('Optional reporting manager. The current member is excluded when editing.'),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        Select::make('member_type')
                            ->required()
                            ->options([
                                'human' => 'Human',
                                'ai' => 'AI',
                            ])
                            ->default('human'),
                        Select::make('status')
                            ->required()
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                                'hidden' => 'Hidden',
                            ])
                            ->default('active'),
                        TextInput::make('sort_order')
                            ->integer()
                            ->default(0),
                        DatePicker::make('joined_at'),
                    ])
                    ->columns(2),

                Section::make('Account, positions and author link')
                    ->schema([
                        Select::make('user_id')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Backoffice account used for personal article statistics.'),
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Assign public Team/Crew positions. Security permissions stay under Users & Access.'),
                        Select::make('author_id')
                            ->relationship('author', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Optional link to an existing public Author profile.'),
                    ])
                    ->columns(3),

                Section::make('Bio')
                    ->schema([
                        Textarea::make('short_bio')
                            ->rows(3),
                        Textarea::make('full_bio')
                            ->rows(8),
                    ])
                    ->columns(1),

                Section::make('Structured data')
                    ->schema([
                        KeyValue::make('social_links')
                            ->helperText('Use keys such as website, facebook, instagram, linkedin, x.'),
                        KeyValue::make('metadata'),
                    ])
                    ->columns(1),
            ]);
    }
}
