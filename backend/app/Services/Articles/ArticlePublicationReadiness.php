<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ArticlePublicationReadiness
{
    /**
     * Build the single publication-checklist contract used by the newsroom UI
     * and every server-side publication transition.
     *
     * @return array<string, array{
     *     label: string,
     *     state: string,
     *     severity: string,
     *     complete: bool,
     *     message: string|null,
     *     why: string|null,
     *     how: string|null,
     *     action_label: string|null,
     *     target: string|null
     * }>
     */
    public function checklist(Article $article, ?User $viewer = null, bool $publicationCheck = false): array
    {
        $article->loadMissing(['author', 'category', 'featuredImage', 'seoMetadata', 'sponsoredContentDetail']);
        $viewer ??= auth()->user();
        $authorView = ! $publicationCheck
            && ($viewer?->hasRole('author') || $viewer?->hasRole('contributor'));
        $preloadedBlockingCount = $article->getAttribute('unresolved_blocking_editorial_comments_count');
        $blockingComments = $publicationCheck
            ? $article->unresolvedBlockingEditorialComments()->count()
            : (! $authorView && $preloadedBlockingCount !== null
                ? (int) $preloadedBlockingCount
                : $article->unresolvedBlockingEditorialComments()
                    ->when(
                        $authorView,
                        fn (Builder $query): Builder => $query->where('visibility', ArticleEditorialComment::VISIBILITY_AUTHOR),
                    )
                    ->count());
        $sourcesRequired = $this->sourcesRequired($article);
        $sponsored = $article->is_sponsored || in_array($article->type, ['sponsored', 'pr'], true);
        $sponsoredDisclosureComplete = ! $sponsored
            || filled($article->sponsoredContentDetail?->sponsor_name)
            && filled($article->sponsoredContentDetail?->disclosure_label);
        $scheduledDateComplete = $article->status !== 'scheduled' || $article->scheduled_at !== null;
        $slugComplete = filled($article->slug)
            && Article::query()
                ->where('slug', $article->slug)
                ->whereKeyNot($article->getKey())
                ->doesntExist();
        $directPublisher = $viewer?->isAdmin()
            || $viewer?->hasRole('editor_in_chief')
            || $viewer?->hasRole('editor');
        $approvalComplete = (bool) ($article->approved_at && $article->approved_by) || (bool) $directPublisher;

        $checklist = [
            'title' => $this->check(
                label: 'Наслов',
                complete: filled($article->title),
                blocking: true,
                message: 'Недостига наслов.',
                why: 'Насловот е потребен во објавата, листите и споделувањето.',
                how: 'Внесете јасен и конкретен наслов во полето „Наслов“ и зачувајте.',
                actionLabel: 'Додај наслов',
                target: 'data.title',
            ),
            'excerpt' => $this->check(
                label: 'Краток опис',
                complete: filled($article->excerpt),
                blocking: true,
                message: 'Недостига краток опис.',
                why: 'Краткиот опис се користи на почетната страница, во рубриките и во пребарувањето.',
                how: 'Напишете една или две реченици што точно ја најавуваат содржината.',
                actionLabel: 'Додај краток опис',
                target: 'data.excerpt',
            ),
            'content' => $this->check(
                label: 'Содржина',
                complete: filled(trim(strip_tags((string) $article->body_html))),
                blocking: true,
                message: 'Недостига содржина.',
                why: 'Објавата не може да се прегледа и објави без главен текст.',
                how: 'Внесете го и уредете го целиот текст во полето „Содржина“.',
                actionLabel: 'Додај содржина',
                target: 'data.body_html',
            ),
            'author' => $this->check(
                label: 'Автор',
                complete: (bool) $article->author_id,
                blocking: true,
                message: 'Не е избран автор.',
                why: 'Авторот е потребен за потпис, авторски профил и одговорност за содржината.',
                how: 'Изберете го точниот автор во делот за организација на објавата.',
                actionLabel: 'Избери автор',
                target: 'data.author_id',
            ),
            'category' => $this->check(
                label: 'Категорија',
                complete: (bool) $article->category_id,
                blocking: true,
                message: 'Не е избрана категорија.',
                why: 'Категоријата ја поставува објавата во точната рубрика и навигација.',
                how: 'Изберете ја главната категорија што најдобро ја опишува темата.',
                actionLabel: 'Избери категорија',
                target: 'data.category_id',
            ),
            'article_type' => $this->check(
                label: 'Тип на објава',
                complete: filled($article->type),
                blocking: true,
                message: 'Не е избран тип на објава.',
                why: 'Типот ги одредува уредничките правила, ознаките и потребните дополнителни податоци.',
                how: 'Изберете соодветен тип во делот за организација на објавата.',
                actionLabel: 'Избери тип',
                target: 'data.type',
            ),
            'featured_image' => $this->check(
                label: 'Главна слика',
                complete: (bool) $article->featured_image_id,
                blocking: false,
                message: 'Недостига главна слика.',
                why: 'Почетната страница, рубриката, заглавието на објавата и социјалниот преглед ја користат главната слика.',
                how: 'Отворете „Главна слика“, прикачете или изберете слика и зачувајте.',
                actionLabel: 'Додај главна слика',
                target: 'data.featured_image_upload',
            ),
            'alt_text' => $this->check(
                label: 'Алтернативен текст',
                complete: ! $article->featured_image_id || filled($article->featuredImage?->alt_text),
                blocking: false,
                message: 'Главната слика нема алтернативен текст.',
                why: 'Алтернативниот текст ја објаснува сликата за читатели што користат помошни технологии.',
                how: 'Отворете ја избраната слика во Медиуми, додадете краток опис и зачувајте.',
                actionLabel: 'Уреди ја сликата',
                target: 'data.featured_image_id',
            ),
            'sources' => $this->check(
                label: 'Извори',
                complete: ! $sourcesRequired || $this->hasSources($article),
                blocking: $sourcesRequired,
                message: $sourcesRequired ? 'Недостига најмалку еден проверлив извор.' : null,
                why: $sourcesRequired ? 'Изворите му овозможуваат на уредникот да ги провери клучните факти.' : null,
                how: $sourcesRequired ? 'Во структурираниот дел додадете назив и адреса на најмалку еден релевантен извор.' : null,
                actionLabel: $sourcesRequired ? 'Додај извор' : null,
                target: $sourcesRequired ? 'data.guide_metadata.sources' : null,
            ),
            'sponsored_disclosure' => $this->check(
                label: 'Ознака за спонзорство',
                complete: $sponsoredDisclosureComplete,
                blocking: $sponsored,
                message: $sponsored ? 'Недостига име на спонзор или јавна ознака за спонзорство.' : null,
                why: $sponsored ? 'Платената содржина мора јасно да му биде означена на читателот.' : null,
                how: $sponsored ? 'Во „Спонзорирана содржина“ внесете спонзор и изберете јавна ознака.' : null,
                actionLabel: $sponsored ? 'Додај ознака' : null,
                target: $sponsored ? 'data.sponsoredContentDetail.disclosure_label' : null,
            ),
            'blocking_comments' => $this->check(
                label: 'Блокирачки коментари',
                complete: $blockingComments === 0,
                blocking: true,
                message: $blockingComments > 0 ? "Има {$blockingComments} нерешени блокирачки коментари." : null,
                why: $blockingComments > 0 ? 'Овие уреднички забелешки мора да се разгледаат пред одобрување.' : null,
                how: $blockingComments > 0 ? 'Отворете ги уредничките коментари, направете ги измените и означете ги ставките како адресирани.' : null,
                actionLabel: $blockingComments > 0 ? 'Отвори коментари' : null,
                target: $blockingComments > 0 ? 'article-editorial-comments' : null,
            ),
            'approval_status' => $this->check(
                label: 'Одобрување',
                complete: $approvalComplete,
                blocking: false,
                message: 'Објавата сè уште не е одобрена.',
                why: 'Уредничкото одобрување потврдува дека содржината е подготвена за јавноста.',
                how: 'Испратете ја објавата на преглед. Овластен уредник може да ја одобри или директно да ја објави.',
                actionLabel: null,
                target: null,
                severity: 'information',
            ),
            'scheduled_date' => $this->check(
                label: 'Термин за објавување',
                complete: $scheduledDateComplete,
                blocking: $article->status === 'scheduled',
                message: $article->status === 'scheduled' ? 'Закажаната објава нема термин.' : null,
                why: $article->status === 'scheduled' ? 'Без точен термин системот не може автоматски да ја објави содржината.' : null,
                how: $article->status === 'scheduled' ? 'Изберете датум и време во дејството „Закажи“.' : null,
                actionLabel: $article->status === 'scheduled' ? 'Постави термин' : null,
                target: $article->status === 'scheduled' ? 'data.scheduled_at' : null,
            ),
            'slug_conflict' => $this->check(
                label: 'URL назив',
                complete: $slugComplete,
                blocking: true,
                message: 'URL називот недостига или веќе го користи друга објава.',
                why: 'Секоја објава мора да има единствена и стабилна јавна адреса.',
                how: 'Внесете единствен URL назив без да ја менувате адресата на веќе објавена содржина.',
                actionLabel: 'Провери URL назив',
                target: 'data.slug',
            ),
            'seo_title' => $this->check(
                label: 'SEO наслов',
                complete: filled($article->seoMetadata?->seo_title),
                blocking: false,
                message: 'Недостига SEO наслов.',
                why: 'SEO насловот помага резултатот во пребарувачот да биде јасен и релевантен.',
                how: 'Во делот SEO внесете краток наслов што точно ја опишува објавата.',
                actionLabel: 'Додај SEO наслов',
                target: 'data.seoMetadata.seo_title',
            ),
            'meta_description' => $this->check(
                label: 'Мета опис',
                complete: filled($article->seoMetadata?->meta_description),
                blocking: false,
                message: 'Недостига мета опис.',
                why: 'Мета описот му дава краток контекст на читателот во резултатите од пребарување.',
                how: 'Во делот SEO напишете точен опис со најважната информација.',
                actionLabel: 'Додај мета опис',
                target: 'data.seoMetadata.meta_description',
            ),
            'canonical' => $this->check(
                label: 'Канонска адреса',
                complete: filled($article->seoMetadata?->canonical_url),
                blocking: false,
                message: 'Не е внесена канонска адреса.',
                why: 'Канонската адреса му помага на пребарувачот да ја препознае главната верзија на содржината.',
                how: 'Внесете ја јавната адреса само кога е потребно експлицитно канонско правило.',
                actionLabel: 'Провери канонска адреса',
                target: 'data.seoMetadata.canonical_url',
                severity: 'information',
            ),
            'open_graph_image' => $this->check(
                label: 'Open Graph слика',
                complete: (bool) ($article->seoMetadata?->og_image_id || $article->featured_image_id),
                blocking: false,
                message: 'Нема слика за преглед при споделување.',
                why: 'Социјалните мрежи користат Open Graph слика за препознатлив преглед на линкот.',
                how: 'Изберете Open Graph слика во SEO или додадете главна слика што ќе се користи како замена.',
                actionLabel: 'Додај слика за споделување',
                target: 'data.seoMetadata.og_image_id',
            ),
        ];

        if ($authorView) {
            unset(
                $checklist['seo_title'],
                $checklist['meta_description'],
                $checklist['canonical'],
                $checklist['open_graph_image'],
            );
        }

        return $checklist;
    }

    /**
     * Approval has its own workflow exception for an administrator emergency
     * publish. Every content blocker remains mandatory for every actor.
     */
    public function assertReady(Article $article, bool $requireApproval = true): void
    {
        // Never use an author-filtered view here. Publication checks must see
        // internal blocking comments as well as author-visible comments.
        $checklist = $this->checklist($article, publicationCheck: true);
        $errors = collect($checklist)
            ->filter(fn (array $item): bool => $item['state'] === 'blocking')
            ->mapWithKeys(fn (array $item, string $key): array => [
                $key => $item['message'] ?? "Publication requirement [{$key}] is incomplete.",
            ])
            ->all();

        if ($article->author && ! $article->author->is_active) {
            $errors['author'] = 'Авторот мора да биде активен пред објавување.';
        }

        if ($requireApproval && ! $checklist['approval_status']['complete']) {
            $errors['approval_status'] = $checklist['approval_status']['message'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function failureSummary(ValidationException $exception): string
    {
        $errors = $exception->errors();

        if (array_keys($errors) === ['approval_status']) {
            return 'Scheduled article is missing approval metadata.';
        }

        if (isset($errors['author']) && str_contains(implode(' ', $errors['author']), 'активен')) {
            return 'Scheduled article author is inactive.';
        }

        return collect($exception->errors())
            ->flatten()
            ->filter(fn ($message): bool => is_string($message) && filled($message))
            ->implode(' ');
    }

    public function sourcesRequired(Article $article): bool
    {
        return in_array($article->type, config('newsroom.source_required_types', ['guide']), true)
            || in_array($article->presentation_type, config('newsroom.source_required_presentations', ['event_guide']), true);
    }

    private function hasSources(Article $article): bool
    {
        $sources = collect(data_get($article->guide_metadata, 'sources', []));
        $events = collect(data_get($article->guide_metadata, 'events', []));

        return $sources->contains(fn ($source): bool => filled(data_get($source, 'url')))
            || $events->contains(fn ($event): bool => filled(data_get($event, 'source_url')));
    }

    /**
     * @return array{
     *     label: string,
     *     state: string,
     *     severity: string,
     *     complete: bool,
     *     message: string|null,
     *     why: string|null,
     *     how: string|null,
     *     action_label: string|null,
     *     target: string|null
     * }
     */
    private function check(
        string $label,
        bool $complete,
        bool $blocking,
        ?string $message,
        ?string $why = null,
        ?string $how = null,
        ?string $actionLabel = null,
        ?string $target = null,
        ?string $severity = null,
    ): array {
        $severity ??= $blocking ? 'blocking' : 'warning';

        return [
            'label' => $label,
            'state' => $complete ? 'complete' : ($blocking ? 'blocking' : 'warning'),
            'severity' => $complete ? 'information' : $severity,
            'complete' => $complete,
            'message' => $complete ? null : $message,
            'why' => $complete ? null : $why,
            'how' => $complete ? null : $how,
            'action_label' => $complete ? null : $actionLabel,
            'target' => $complete ? null : $target,
        ];
    }
}
