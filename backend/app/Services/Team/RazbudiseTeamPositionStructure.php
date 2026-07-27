<?php

namespace App\Services\Team;

use App\Models\TeamRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RazbudiseTeamPositionStructure
{
    /**
     * @return Collection<string, TeamRole>
     */
    public function sync(): Collection
    {
        return DB::transaction(function (): Collection {
            foreach ($this->definitions() as $definition) {
                $position = TeamRole::query()
                    ->where('slug', $definition['slug'])
                    ->first()
                    ?? TeamRole::query()
                        ->where('name', $definition['name'])
                        ->where(function ($query): void {
                            $query->whereNull('group_key')->orWhere('group_key', '');
                        })
                        ->first()
                    ?? new TeamRole;

                $position->fill([
                    'name' => $definition['name'],
                    'slug' => $definition['slug'],
                    'description' => $definition['description'],
                    'group_key' => $definition['group_key'],
                    'sort_order' => $definition['sort_order'],
                    'status' => 'active',
                ]);

                $position->save();
            }

            $this->assertValidStructure();

            return TeamRole::query()
                ->whereIn('slug', array_column($this->definitions(), 'slug'))
                ->orderBy('sort_order')
                ->get()
                ->keyBy('slug');
        });
    }

    /**
     * @return array<int, array{name: string, slug: string, description: string, group_key: string, sort_order: int}>
     */
    public function definitions(): array
    {
        return [
            ['name' => 'Основач', 'slug' => 'osnovac', 'description' => 'Стратешка насока, развој и долгорочна одговорност за платформата.', 'group_key' => 'technology', 'sort_order' => 10],
            ['name' => 'Главен уредник', 'slug' => 'glaven-urednik', 'description' => 'Уредничка насока, стандарди и финална одговорност за објавување.', 'group_key' => 'editorial', 'sort_order' => 20],
            ['name' => 'Уредник', 'slug' => 'urednik', 'description' => 'Планирање, уредување и подготовка на содржина за објавување.', 'group_key' => 'editorial', 'sort_order' => 30],
            ['name' => 'Автор', 'slug' => 'avtor', 'description' => 'Авторски текстови, интервјуа, колумни и локални приказни.', 'group_key' => 'editorial', 'sort_order' => 40],
            ['name' => 'Новинар', 'slug' => 'novinar', 'description' => 'Вести, теренски информации и проверени локални случувања.', 'group_key' => 'news', 'sort_order' => 50],
            ['name' => 'Фото и видео', 'slug' => 'foto-video', 'description' => 'Фотографија, видео и визуелна продукција за редакциски материјали.', 'group_key' => 'media-production', 'sort_order' => 60],
            ['name' => 'Социјални медиуми', 'slug' => 'socijalni-mediumi', 'description' => 'Пакување, објавување и комуникација со публиката на социјални мрежи.', 'group_key' => 'audience-social', 'sort_order' => 70],
            ['name' => 'Продажба и рекламирање', 'slug' => 'prodazba-reklamiranje', 'description' => 'Комуникација со партнери, реклами и комерцијални кампањи.', 'group_key' => 'sales-advertising', 'sort_order' => 80],
            ['name' => 'Технологија и развој', 'slug' => 'tehnologija-razvoj', 'description' => 'Техничка поддршка, развој и одржување на платформата.', 'group_key' => 'technology', 'sort_order' => 90],
        ];
    }

    private function assertValidStructure(): void
    {
        $slugs = array_column($this->definitions(), 'slug');

        $duplicates = TeamRole::query()
            ->select('slug')
            ->whereIn('slug', $slugs)
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('slug')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException('Duplicate team position slugs: '.implode(', ', $duplicates));
        }

        $count = TeamRole::query()->whereIn('slug', $slugs)->where('status', 'active')->count();

        if ($count !== count($slugs)) {
            throw new RuntimeException('Not all required RazbudiSe team positions are active.');
        }
    }
}
