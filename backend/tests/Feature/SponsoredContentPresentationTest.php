<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\SponsoredContentDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SponsoredContentPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_cards_include_sponsored_disclosure_label(): void
    {
        $article = Article::factory()->published()->create([
            'title' => 'Платена промоција за локален бренд',
            'slug' => 'platena-promocija-lokalen-brend',
            'type' => 'sponsored',
            'is_sponsored' => true,
        ]);

        SponsoredContentDetail::query()->create([
            'article_id' => $article->id,
            'sponsor_name' => 'Локален бренд',
            'disclosure_label' => 'Платена промоција',
        ]);

        $this->getJson('/api/articles')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'platena-promocija-lokalen-brend')
            ->assertJsonPath('data.0.sponsored_label', 'Платена промоција');
    }
}
