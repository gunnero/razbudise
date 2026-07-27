<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\User;

class ArticlePublicationBlockerExplainer
{
    public function __construct(private readonly ArticlePublicationReadiness $readiness) {}

    public function explain(Article $article, ?User $viewer = null): ?string
    {
        if ($article->status === 'published' && $article->published_at?->lte(now())) {
            return null;
        }

        if ($article->publication_failed_at || filled($article->publication_failure_reason)) {
            return 'Последниот обид за објавување не успеал. Отворете ја објавата за деталите.';
        }

        if ($article->author && ! $article->author->is_active) {
            return 'Авторскиот профил е оневозможен.';
        }

        $blockingComments = (int) ($article->getAttribute('unresolved_blocking_editorial_comments_count')
            ?? $article->unresolvedBlockingEditorialComments()->count());

        if ($blockingComments > 0) {
            return "Има {$blockingComments} нерешени блокирачки коментари.";
        }

        if ($article->status === 'changes_requested') {
            return 'Потребна е доработка пред повторно испраќање.';
        }

        $missingRequired = collect($this->readiness->checklist($article, $viewer))
            ->filter(fn (array $item, string $key): bool => $item['state'] === 'blocking' && $key !== 'blocking_comments')
            ->pluck('label')
            ->filter()
            ->values();

        if ($missingRequired->isNotEmpty()) {
            return 'Недостигаат задолжителни податоци: '.$missingRequired->implode(', ').'.';
        }

        if ($article->status === 'draft') {
            return 'Работната верзија сè уште не е испратена на преглед.';
        }

        if (in_array($article->status, ['submitted', 'review', 'in_review'], true)) {
            return 'Чека преглед и одлука од уредник.';
        }

        if ($article->status === 'approved') {
            return 'Одобрена е, но сè уште не е објавена или закажана.';
        }

        if ($article->status === 'scheduled') {
            return $article->scheduled_at?->isFuture()
                ? 'Закажана е за идно објавување.'
                : 'Терминот поминал. Проверете ја задачата за закажано објавување.';
        }

        if (! $article->approved_at && $article->status !== 'archived') {
            return 'Објавата сè уште не е одобрена.';
        }

        return $article->status === 'archived' ? 'Објавата е архивирана.' : null;
    }
}
