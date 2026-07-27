<?php

namespace App\Filament\Pages;

use App\Models\Author;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Authors\AuthorAvatarResolver;
use App\Services\Authors\AuthorProfileFormDataService;
use App\Services\Operations\AdminAuditLogger;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

class EditProfile extends BaseEditProfile
{
    /** @var array<string, mixed> */
    private array $authorAuditBefore = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.profile.account_section'))
                ->description(__('admin.profile.account_section_help'))
                ->schema([
                    $this->getNameFormComponent(),
                    $this->getEmailFormComponent(),
                    Select::make('locale')
                        ->label(__('admin.profile.language'))
                        ->options([
                            'en' => __('admin.languages.en'),
                            'mk' => __('admin.languages.mk'),
                        ])
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.profile.language_help')),
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
                ])
                ->columns(2),

            Section::make(__('admin.profile.author_section'))
                ->description(__('admin.profile.author_section_help'))
                ->schema([
                    Placeholder::make('author_profile_status')
                        ->label(__('admin.profile.author_profile_status'))
                        ->content(fn (): HtmlString => $this->authorStatusHtml())
                        ->columnSpanFull(),
                    Select::make('author_sex')
                        ->label(__('admin.fields.sex'))
                        ->options([
                            Author::SEX_MALE => __('admin.sex.male'),
                            Author::SEX_FEMALE => __('admin.sex.female'),
                        ])
                        ->native(false)
                        ->required(fn (): bool => (bool) $this->linkedAuthor())
                        ->visible(fn (): bool => (bool) $this->linkedAuthor())
                        ->helperText(__('admin.fields.sex_help')),
                    FileUpload::make('author_avatar_upload')
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
                        ->visible(fn (): bool => (bool) $this->linkedAuthor())
                        ->columnSpanFull(),
                    Toggle::make('author_use_default_avatar')
                        ->label(__('admin.fields.use_default_avatar'))
                        ->helperText(__('admin.fields.use_default_avatar_help'))
                        ->default(false)
                        ->visible(fn (): bool => (bool) $this->linkedAuthor())
                        ->columnSpanFull(),
                    TextInput::make('author_job_title')
                        ->label(__('admin.fields.job_title'))
                        ->maxLength(255)
                        ->visible(fn (): bool => (bool) $this->linkedAuthor()),
                    TextInput::make('author_website_url')
                        ->label(__('admin.fields.website_url'))
                        ->url()
                        ->visible(fn (): bool => (bool) $this->linkedAuthor()),
                    Textarea::make('author_short_bio')
                        ->label(__('admin.fields.short_bio'))
                        ->rows(3)
                        ->visible(fn (): bool => (bool) $this->linkedAuthor())
                        ->columnSpanFull(),
                    Textarea::make('author_full_bio')
                        ->label(__('admin.fields.full_bio'))
                        ->rows(6)
                        ->visible(fn (): bool => (bool) $this->linkedAuthor())
                        ->columnSpanFull(),
                    TextInput::make('author_facebook_url')
                        ->label(__('admin.fields.facebook_url'))
                        ->url()
                        ->visible(fn (): bool => (bool) $this->linkedAuthor()),
                    TextInput::make('author_instagram_url')
                        ->label(__('admin.fields.instagram_url'))
                        ->url()
                        ->visible(fn (): bool => (bool) $this->linkedAuthor()),
                    TextInput::make('author_linkedin_url')
                        ->label(__('admin.fields.linkedin_url'))
                        ->url()
                        ->visible(fn (): bool => (bool) $this->linkedAuthor()),
                    TextInput::make('author_x_url')
                        ->label(__('admin.fields.x_url'))
                        ->url()
                        ->visible(fn (): bool => (bool) $this->linkedAuthor()),
                ])
                ->columns(2),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $author = $this->linkedAuthor();

        if (! $author) {
            return $data;
        }

        return [
            ...$data,
            'author_sex' => $author->sex ?: Author::SEX_MALE,
            'author_job_title' => $author->job_title,
            'author_short_bio' => $author->short_bio,
            'author_full_bio' => $author->full_bio,
            'author_website_url' => $author->website_url,
            'author_facebook_url' => $author->facebook_url,
            'author_instagram_url' => $author->instagram_url,
            'author_linkedin_url' => $author->linkedin_url,
            'author_x_url' => $author->x_url,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $author = $this->linkedAuthor();

        if ($author) {
            $author->loadMissing('avatar');
            $this->authorAuditBefore = $this->authorAuditSnapshot($author);
            $displayName = trim((string) ($data['name'] ?? $author->name));

            $authorData = app(AuthorProfileFormDataService::class)->prepare([
                'name' => $displayName !== '' ? $displayName : $author->name,
                'sex' => Arr::pull($data, 'author_sex'),
                'job_title' => Arr::pull($data, 'author_job_title'),
                'short_bio' => Arr::pull($data, 'author_short_bio'),
                'full_bio' => Arr::pull($data, 'author_full_bio'),
                'website_url' => Arr::pull($data, 'author_website_url'),
                'facebook_url' => Arr::pull($data, 'author_facebook_url'),
                'instagram_url' => Arr::pull($data, 'author_instagram_url'),
                'linkedin_url' => Arr::pull($data, 'author_linkedin_url'),
                'x_url' => Arr::pull($data, 'author_x_url'),
                'avatar_upload' => Arr::pull($data, 'author_avatar_upload'),
                'use_default_avatar' => Arr::pull($data, 'author_use_default_avatar', false),
            ], $author);

            $author->update($authorData);
            $author->refresh();
            $this->syncLinkedTeamMember($author);
        }

        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, 'author_')) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $author = $this->linkedAuthor();

        if (! $author || $this->authorAuditBefore === []) {
            return;
        }

        $after = $this->authorAuditSnapshot($author->fresh(['avatar']));

        if ($after === $this->authorAuditBefore) {
            return;
        }

        app(AdminAuditLogger::class)->record(
            'author.self_profile_updated',
            $author,
            before: $this->authorAuditBefore,
            after: $after,
        );
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getUrl();
    }

    private function linkedAuthor(): ?Author
    {
        /** @var User $user */
        $user = $this->getUser();
        $user->loadMissing('teamMember.author.avatar');

        if ($user->teamMember?->author) {
            return $user->teamMember->author;
        }

        return Author::query()
            ->with('avatar')
            ->where('email', $user->email)
            ->first();
    }

    private function linkedTeamMember(): ?TeamMember
    {
        /** @var User $user */
        $user = $this->getUser();
        $user->loadMissing('teamMember.author.avatar');

        return $user->teamMember;
    }

    private function syncLinkedTeamMember(Author $author): void
    {
        $member = $this->linkedTeamMember();

        if (! $member || (int) $member->author_id !== (int) $author->id) {
            return;
        }

        $socialLinks = $member->social_links ?: [];

        foreach ([
            'website' => $author->website_url,
            'facebook' => $author->facebook_url,
            'instagram' => $author->instagram_url,
            'linkedin' => $author->linkedin_url,
            'x' => $author->x_url,
        ] as $key => $url) {
            if (filled($url)) {
                $socialLinks[$key] = $url;
            } else {
                unset($socialLinks[$key]);
            }
        }

        $member->forceFill([
            'name' => $author->name,
            'display_title' => $author->job_title,
            'short_bio' => $author->short_bio,
            'full_bio' => $author->full_bio,
            'profile_image_id' => $author->avatar_id,
            'sex' => $author->sex,
            'social_links' => $socialLinks,
        ])->save();
    }

    private function authorStatusHtml(): HtmlString
    {
        $author = $this->linkedAuthor();

        if (! $author) {
            return new HtmlString('<span class="text-gray-600">'.e(__('admin.profile.no_author_profile')).'</span>');
        }

        $avatar = app(AuthorAvatarResolver::class)->forAuthor($author, request());
        $url = e((string) ($avatar['url'] ?? ''));
        $alt = e((string) ($avatar['alt_text'] ?? $author->name));
        $profileUrl = e('/profil/'.$author->slug);

        return new HtmlString(
            '<div style="display:flex;align-items:center;gap:1rem">'
            .'<img src="'.$url.'" alt="'.$alt.'" style="width:72px;height:72px;border-radius:999px;object-fit:cover;border:1px solid rgba(120,113,108,.35)">'
            .'<div><div style="font-weight:700">'.$author->name.'</div>'
            .'<div style="color:#78716c">'.e($author->job_title ?: __('admin.profile.public_author_profile')).'</div>'
            .'<a href="'.$profileUrl.'" target="_blank" rel="noopener" style="color:#c2410c;text-decoration:underline">'.e(__('admin.profile.open_public_profile')).'</a>'
            .'</div></div>',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function authorAuditSnapshot(?Author $author): array
    {
        if (! $author) {
            return [];
        }

        return [
            'name' => $author->name,
            'sex' => $author->sex,
            'avatar_id' => $author->avatar_id,
            'job_title' => $author->job_title,
            'short_bio' => $author->short_bio,
            'full_bio' => $author->full_bio,
            'website_url' => $author->website_url,
            'facebook_url' => $author->facebook_url,
            'instagram_url' => $author->instagram_url,
            'linkedin_url' => $author->linkedin_url,
            'x_url' => $author->x_url,
        ];
    }
}
