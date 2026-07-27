<?php

namespace Tests\Feature;

use App\Filament\Pages\EditProfile;
use App\Models\Author;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackofficePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_defaults_to_light_mode(): void
    {
        $this->assertSame(ThemeMode::Light, Filament::getPanel('admin')->getDefaultThemeMode());
    }

    public function test_authenticated_user_sees_backoffice_in_their_preferred_language(): void
    {
        $user = $this->backofficeUser(['locale' => 'mk']);

        $this->actingAs($user)
            ->get('/backoffice/profile')
            ->assertOk()
            ->assertSee('Јазик на административниот панел');

        $this->assertSame('mk', app()->getLocale());
    }

    public function test_user_can_change_their_backoffice_language_in_profile_settings(): void
    {
        $user = $this->backofficeUser(['locale' => 'en']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditProfile::class)
            ->fillForm([
                'name' => $user->name,
                'email' => $user->email,
                'locale' => 'mk',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect('/backoffice/profile');

        $this->assertSame('mk', $user->fresh()->locale);
        $this->assertSame('mk', $user->fresh()->preferredLocale());
    }

    public function test_linked_author_can_manage_public_profile_from_profile_settings(): void
    {
        $user = $this->backofficeUser([
            'name' => 'Elena Naumovski',
            'email' => 'elena@example.test',
            'locale' => 'mk',
        ]);
        $author = Author::factory()->create([
            'name' => 'Elena Naumovski',
            'email' => 'elena@example.test',
            'sex' => Author::SEX_MALE,
            'job_title' => null,
            'short_bio' => null,
        ]);
        $teamMember = TeamMember::factory()->create([
            'user_id' => $user->id,
            'author_id' => $author->id,
            'name' => $user->name,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditProfile::class)
            ->assertFormFieldExists('author_avatar_upload', fn (FileUpload $field): bool => true)
            ->assertFormSet([
                'author_sex' => Author::SEX_MALE,
                'author_job_title' => null,
            ])
            ->fillForm([
                'name' => 'Елена Наумовски',
                'email' => $user->email,
                'locale' => 'mk',
                'author_sex' => Author::SEX_FEMALE,
                'author_job_title' => 'Новинарка',
                'author_short_bio' => 'Кратка јавна биографија.',
                'author_full_bio' => 'Подолга јавна биографија.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Елена Наумовски',
        ]);

        $this->assertDatabaseHas('authors', [
            'id' => $author->id,
            'name' => 'Елена Наумовски',
            'sex' => Author::SEX_FEMALE,
            'job_title' => 'Новинарка',
            'short_bio' => 'Кратка јавна биографија.',
            'full_bio' => 'Подолга јавна биографија.',
        ]);

        $this->assertDatabaseHas('team_members', [
            'id' => $teamMember->id,
            'name' => 'Елена Наумовски',
            'display_title' => 'Новинарка',
        ]);
    }

    public function test_unknown_locale_falls_back_to_english(): void
    {
        $user = $this->backofficeUser(['locale' => 'de']);

        $this->assertSame('en', $user->preferredLocale());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function backofficeUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $role = Role::query()->create(['name' => 'editor', 'label' => 'Editor']);
        $user->roles()->attach($role);

        return $user;
    }
}
