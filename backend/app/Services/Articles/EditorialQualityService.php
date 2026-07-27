<?php

namespace App\Services\Articles;

use App\Models\Article;
use Illuminate\Support\Str;

class EditorialQualityService
{
    /** @return array<int, array{code: string, level: string, message: string}> */
    public function inspect(Article $article): array
    {
        $article->loadMissing(['author', 'category', 'featuredImage']);
        $title = trim((string) ($article->homepage_title ?: $article->title));
        $excerpt = trim(strip_tags((string) ($article->homepage_excerpt ?: $article->excerpt)));
        $body = trim(strip_tags((string) $article->body_html));
        $issues = [];

        if (mb_strlen($title) > 110) {
            $issues[] = $this->issue('title_too_long', 'strong', 'Насловот за почетна е подолг од 110 знаци.');
        } elseif (mb_strlen($title) > 85) {
            $issues[] = $this->issue('title_long', 'warning', 'Насловот за почетна е подолг од препорачаните 85 знаци.');
        }

        if ($this->uppercaseRatio($title) >= 0.8) {
            $issues[] = $this->issue('title_mostly_uppercase', 'warning', 'Насловот е претежно напишан со големи букви.');
        }

        if ($excerpt === '') {
            $issues[] = $this->issue('missing_excerpt', 'warning', 'Недостасува краток опис.');
        } elseif (Str::lower($excerpt) === Str::lower(trim((string) $article->title))) {
            $issues[] = $this->issue('excerpt_duplicates_title', 'warning', 'Краткиот опис го повторува насловот.');
        } elseif (mb_strlen($excerpt) > 240) {
            $issues[] = $this->issue('excerpt_long', 'warning', 'Краткиот опис е подолг од 240 знаци.');
        }

        if (! $article->featured_image_id) {
            $issues[] = $this->issue('missing_featured_image', 'warning', 'Недостасува главна слика.');
        } elseif (trim((string) $article->featuredImage?->alt_text) === '') {
            $issues[] = $this->issue('missing_alt_text', 'warning', 'Главната слика нема алтернативен текст.');
        }

        if (! $article->category_id) {
            $issues[] = $this->issue('missing_category', 'strong', 'Недостасува категорија.');
        } elseif (in_array($article->category?->slug, ['uncategorized', 'pocetna'], true)) {
            $issues[] = $this->issue('default_category', 'warning', 'Објавата е во стандардна или наследена Почетна категорија.');
        }

        if (! $article->author_id) {
            $issues[] = $this->issue('missing_author', 'strong', 'Недостасува автор.');
        }

        if (preg_match('/\b(lorem ipsum|todo|placeholder|test article)\b/ui', "{$title} {$excerpt} {$body}")) {
            $issues[] = $this->issue('placeholder_content', 'strong', 'Пронајден е можен привремен текст.');
        }

        if ($article->type === 'news' && $article->published_at?->lt(now()->subDays(30))) {
            $issues[] = $this->issue('stale_news_risk', 'warning', 'Оваа вест е постара од 30 дена; проверете дали е соодветна за повторно истакнување.');
        }

        if (($article->is_sponsored || in_array($article->type, ['sponsored', 'pr'], true)) && ! $article->sponsoredDisclosureLabel()) {
            $issues[] = $this->issue('missing_sponsored_label', 'strong', 'Недостасува ознака за платена или ПР содржина.');
        }

        return $issues;
    }

    private function uppercaseRatio(string $value): float
    {
        preg_match_all('/\p{L}/u', $value, $letters);
        preg_match_all('/\p{Lu}/u', $value, $uppercase);
        $count = count($letters[0]);

        return $count >= 6 ? count($uppercase[0]) / $count : 0.0;
    }

    /** @return array{code: string, level: string, message: string} */
    private function issue(string $code, string $level, string $message): array
    {
        return compact('code', 'level', 'message');
    }
}
