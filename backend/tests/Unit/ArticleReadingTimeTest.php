<?php

namespace Tests\Unit;

use App\Models\Article;
use Tests\TestCase;

class ArticleReadingTimeTest extends TestCase
{
    public function test_reading_time_counts_macedonian_cyrillic_words(): void
    {
        $body = '<p>'.str_repeat('македонски ', 221).'</p>';

        $article = new Article(['body_html' => $body]);

        $this->assertSame(2, $article->reading_time_minutes);
    }

    public function test_reading_time_keeps_one_minute_minimum_for_short_or_empty_articles(): void
    {
        $this->assertSame(1, (new Article(['body_html' => '<p>Краток текст.</p>']))->reading_time_minutes);
        $this->assertSame(1, (new Article(['body_html' => '']))->reading_time_minutes);
    }
}
