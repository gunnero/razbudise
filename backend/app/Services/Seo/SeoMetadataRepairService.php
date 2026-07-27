<?php

namespace App\Services\Seo;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SeoMetadata;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SeoMetadataRepairService
{
    /**
     * @return array{articles_checked: int, article_excerpts_repaired: int, metadata_created: int, metadata_updated: int, canonical_repaired: int, og_images_repaired: int, dry_run: bool}
     */
    public function repairArticles(bool $apply = false, bool $onlyImported = false): array
    {
        $summary = [
            'articles_checked' => 0,
            'article_excerpts_repaired' => 0,
            'metadata_created' => 0,
            'metadata_updated' => 0,
            'canonical_repaired' => 0,
            'og_images_repaired' => 0,
            'dry_run' => ! $apply,
        ];

        Article::query()
            ->when($onlyImported, fn ($query) => $query->whereNotNull('old_wordpress_id'))
            ->with('seoMetadata')
            ->orderBy('id')
            ->each(function (Article $article) use ($apply, &$summary): void {
                $summary['articles_checked']++;

                $metadata = $article->seoMetadata;
                $changes = $this->repairPayload($article, $metadata);
                $excerpt = $this->stringOrNull($article->excerpt);
                $derivedExcerpt = $excerpt === null ? $this->extractDescriptionFromBody($article) : null;

                if ($derivedExcerpt !== null) {
                    $summary['article_excerpts_repaired']++;
                }

                if ($changes === []) {
                    if ($apply && $derivedExcerpt !== null) {
                        $article->forceFill(['excerpt' => $derivedExcerpt])->save();
                    }

                    return;
                }

                if (array_key_exists('canonical_url', $changes)) {
                    $summary['canonical_repaired']++;
                }

                if (array_key_exists('og_image_id', $changes)) {
                    $summary['og_images_repaired']++;
                }

                if ($metadata) {
                    $summary['metadata_updated']++;
                } else {
                    $summary['metadata_created']++;
                }

                if (! $apply) {
                    return;
                }

                if ($derivedExcerpt !== null) {
                    $article->forceFill(['excerpt' => $derivedExcerpt])->save();
                }

                SeoMetadata::query()->updateOrCreate(
                    [
                        'seoable_type' => Article::class,
                        'seoable_id' => $article->id,
                    ],
                    $changes,
                );
            });

        return $summary;
    }

    /**
     * @return array{pages_checked: int, metadata_created: int, metadata_updated: int, canonical_repaired: int, dry_run: bool}
     */
    public function repairPublicPages(bool $apply = false): array
    {
        $summary = [
            'pages_checked' => 0,
            'metadata_created' => 0,
            'metadata_updated' => 0,
            'canonical_repaired' => 0,
            'dry_run' => ! $apply,
        ];

        Category::query()
            ->where('is_active', true)
            ->with('seoMetadata')
            ->orderBy('id')
            ->each(function (Category $category) use ($apply, &$summary): void {
                $this->repairPublicPage(
                    $category,
                    rtrim((string) config('app.frontend_url'), '/')."/category/{$category->slug}",
                    $category->seo_title ?: "{$category->name} - Разбуди се",
                    $this->categoryMetaDescription($category),
                    $apply,
                    $summary,
                );
            });

        Tag::query()
            ->with('seoMetadata')
            ->orderBy('id')
            ->each(function (Tag $tag) use ($apply, &$summary): void {
                $this->repairPublicPage(
                    $tag,
                    rtrim((string) config('app.frontend_url'), '/')."/tag/{$tag->slug}",
                    "#{$tag->name} - Разбуди се",
                    "Сите објави означени со {$tag->name} на Разбуди се.",
                    $apply,
                    $summary,
                );
            });

        Author::query()
            ->active()
            ->with('seoMetadata')
            ->orderBy('id')
            ->each(function (Author $author) use ($apply, &$summary): void {
                $this->repairPublicPage(
                    $author,
                    rtrim((string) config('app.frontend_url'), '/')."/profil/{$author->slug}",
                    $author->seo_title ?: "{$author->name} - Разбуди се",
                    $author->meta_description ?: $author->short_bio ?: "Текстови и авторски објави од {$author->name} на Разбуди се.",
                    $apply,
                    $summary,
                );
            });

        return $summary;
    }

    /**
     * @param  array{pages_checked: int, metadata_created: int, metadata_updated: int, canonical_repaired: int, dry_run: bool}  $summary
     */
    private function repairPublicPage(
        Model $model,
        string $canonicalUrl,
        string $seoTitle,
        string $metaDescription,
        bool $apply,
        array &$summary,
    ): void {
        $summary['pages_checked']++;

        $metadata = $model->getRelationValue('seoMetadata');
        $changes = $this->publicPageRepairPayload($metadata, $canonicalUrl, $seoTitle, $metaDescription);

        if ($changes === []) {
            return;
        }

        if (array_key_exists('canonical_url', $changes)) {
            $summary['canonical_repaired']++;
        }

        if ($metadata) {
            $summary['metadata_updated']++;
        } else {
            $summary['metadata_created']++;
        }

        if (! $apply) {
            return;
        }

        SeoMetadata::query()->updateOrCreate(
            [
                'seoable_type' => $model::class,
                'seoable_id' => $model->getKey(),
            ],
            $changes,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    private function repairPayload(Article $article, ?SeoMetadata $metadata): array
    {
        $changes = [];
        $desiredCanonical = $article->publicUrl();
        $description = $this->stringOrNull($article->excerpt) ?? $this->extractDescriptionFromBody($article);

        if ($this->stringOrNull($metadata?->seo_title) === null && $this->stringOrNull($article->title) !== null) {
            $changes['seo_title'] = $article->title;
        }

        if ($this->stringOrNull($metadata?->meta_description) === null && $description !== null) {
            $changes['meta_description'] = $description;
        }

        if ($this->canonicalNeedsRepair($metadata?->canonical_url, $desiredCanonical)) {
            $changes['canonical_url'] = $desiredCanonical;
        }

        if ($metadata?->og_image_id === null && $article->featured_image_id !== null) {
            $changes['og_image_id'] = $article->featured_image_id;
        }

        return $changes;
    }

    /**
     * @return array<string, string|null>
     */
    private function publicPageRepairPayload(?SeoMetadata $metadata, string $canonicalUrl, string $seoTitle, string $metaDescription): array
    {
        $changes = [];

        if ($this->stringOrNull($metadata?->seo_title) === null) {
            $changes['seo_title'] = $seoTitle;
        }

        if ($this->stringOrNull($metadata?->meta_description) === null) {
            $changes['meta_description'] = $metaDescription;
        }

        if ($this->canonicalNeedsRepair($metadata?->canonical_url, $canonicalUrl)) {
            $changes['canonical_url'] = $canonicalUrl;
        }

        return $changes;
    }

    private function canonicalNeedsRepair(?string $canonicalUrl, string $desiredCanonical): bool
    {
        return $this->normalizeUrl($canonicalUrl) !== $this->normalizeUrl($desiredCanonical);
    }

    private function normalizeUrl(?string $url): string
    {
        return rtrim(trim((string) $url), '/');
    }

    private function extractDescriptionFromBody(Article $article): ?string
    {
        $body = preg_replace('/\[[^\]]+\]/u', ' ', (string) $article->body_html) ?? '';
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return Str::limit($text, 160, '');
    }

    private function categoryMetaDescription(Category $category): string
    {
        $descriptions = [
            'zivot' => 'Животни приказни, навики и инспирација за секојдневие со повеќе радост, љубопитност и добри идеи.',
            'hedonizam-i-gastronomija' => 'Хедонизам, гастрономија, вкусови и места за уживање низ Македонија и регионот.',
            'opusteno' => 'Опуштени приказни, лесни теми и мали паузи за читање кога ти треба здив од денот.',
            'psihologija' => 'Психологија, односи, ментално здравје и личен развој низ разбирливи и топли текстови.',
            'modna-soba' => 'Мода, стил, локални креативци и модни приказни од македонската и регионалната сцена.',
            'urbano' => 'Урбана култура, градски живот, трендови и приказни за луѓето и местата што го движат градот.',
            'intervju' => 'Интервјуа и разговори со луѓе кои создаваат, размислуваат и оставаат трага.',
            'dom-i-dizajn' => 'Дом, ентериер, архитектура, дизајн и практични идеи за простор со карактер.',
            'kniga' => 'Книги, автори, читање, книжевни препораки и приказни за љубителите на литературата.',
            'art-time' => 'Art Time носи уметност, изложби, сцена и креативни луѓе на едно место.',
            'nastani' => 'Настани, најави, случувања и културен календар за градски и локални приказни.',
            'bitolski-muabeti' => 'Битолски муабети, локални приказни, луѓе, места и моменти од Битола.',
            'kolumni' => 'Колумни, авторски ставови, размислувања и коментари за темите што вреди да се отворат.',
            'biznis' => 'Бизнис приказни, претприемништво, компании и идеи што ја движат деловната сцена.',
            'finansii' => 'Финансии, економија, лични финансии и корисни теми за паметни одлуки.',
            'vesti' => 'Вести, актуелности и кратки информации од Македонија, регионот и локалната сцена.',
        ];

        return $category->meta_description
            ?: ($descriptions[$category->slug] ?? null)
            ?: $category->description
            ?: "Најнови објави од категоријата {$category->name} на Разбуди се.";
    }

    private function stringOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
