<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterSubscriberApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_signup_creates_active_newsletter_subscriber(): void
    {
        $this->postJson('/api/newsletter/subscribers', [
            'email' => 'citatel@example.test',
            'name' => 'Верна Читателка',
            'source' => 'footer',
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'citatel@example.test')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas(NewsletterSubscriber::class, [
            'email' => 'citatel@example.test',
            'name' => 'Верна Читателка',
            'status' => 'active',
            'source' => 'footer',
        ]);
    }

    public function test_public_signup_is_repeatable_without_creating_duplicates(): void
    {
        NewsletterSubscriber::query()->create([
            'email' => 'reader@example.test',
            'name' => 'Old Name',
            'status' => 'unsubscribed',
            'source' => 'old_import',
            'subscribed_at' => now()->subMonth(),
        ]);

        $this->postJson('/api/newsletter/subscribers', [
            'email' => 'READER@example.test',
            'name' => 'New Name',
            'source' => 'article_footer',
        ])
            ->assertOk()
            ->assertJsonPath('data.email', 'reader@example.test')
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(1, NewsletterSubscriber::query()->where('email', 'reader@example.test')->count());
        $this->assertDatabaseHas(NewsletterSubscriber::class, [
            'email' => 'reader@example.test',
            'name' => 'New Name',
            'status' => 'active',
            'source' => 'article_footer',
        ]);
    }

    public function test_public_signup_validates_email(): void
    {
        $this->postJson('/api/newsletter/subscribers', [
            'email' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }
}
