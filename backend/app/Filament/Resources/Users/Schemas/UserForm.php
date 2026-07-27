<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Login account')
                ->description('CMS login accounts are separate from public author profiles.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Select::make('locale')
                        ->label(__('admin.profile.language'))
                        ->options([
                            'en' => __('admin.languages.en'),
                            'mk' => __('admin.languages.mk'),
                        ])
                        ->default('en')
                        ->required()
                        ->native(false),
                    Select::make('status')
                        ->options([
                            'invited' => 'Invited',
                            'active' => 'Active',
                            'disabled' => 'Disabled',
                            'suspended' => 'Suspended',
                            'archived' => 'Archived',
                        ])
                        ->default('active')
                        ->required()
                        ->native(false)
                        ->helperText('Inactive and suspended users cannot access the backoffice.'),
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->minLength(12)
                        ->helperText('Leave blank when editing to keep the current password.'),
                    Select::make('roles')
                        ->relationship(
                            'roles',
                            'label',
                            modifyQueryUsing: fn ($query) => auth()->user()?->hasPermission('users.manage')
                                ? $query
                                : $query->whereIn('name', ['editor', 'author', 'contributor']),
                        )
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->required()
                        ->helperText('Administrator has full access. Editor manages publishing content.'),
                ])
                ->columns(2),
        ]);
    }
}
