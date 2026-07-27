<x-filament-panels::page>
    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Launch readiness</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Import blockers, editorial follow-up, and old WordPress upload references that still affect launch.</p>
            </div>
            <span class="rounded-full px-3 py-1 text-sm font-semibold {{ ($report['ready'] ?? false) ? 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' }}">
                {{ ($report['ready'] ?? false) ? 'Ready' : 'Blockers found' }}
            </span>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-5">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Imported posts</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['posts']['imported'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Old upload links</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['unresolved_wordpress_links'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Media issues</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['media']['issues'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">SEO issues</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['seo']['issues'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Manual review</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['migration_issues']['manual_review'] ?? 0) }}</p>
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Migration completion audit</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Local import evidence for final WordPress migration checks. Use the command below for a source-vs-CMS audit against the configured WordPress API.</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-6">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Post records</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['completion']['post_records'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Failed records</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['completion']['failed_import_records'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Recorded not imported</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['completion']['recorded_not_imported'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Missing redirects</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['completion']['missing_redirects'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Body cleanup</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['completion']['body_cleanup_candidates'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Author conflicts</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['completion']['author_mapping_conflicts'] ?? 0) }}</p>
            </div>
        </div>

        <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
            <div>
                <dt class="font-medium text-gray-700 dark:text-gray-200">Source audit</dt>
                <dd class="mt-1 rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $report['totals']['completion']['audit_command'] ?? 'php artisan wordpress:migration-completion --json' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-700 dark:text-gray-200">Opt-in completion run</dt>
                <dd class="mt-1 rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $report['totals']['completion']['completion_command'] ?? 'php artisan wordpress:migration-completion --sync-taxonomies --import-missing --generate-redirects --clean-bodies' }}</dd>
            </div>
        </dl>

        @if (! empty($report['samples']['completion_body_cleanup']))
            <div class="mt-5 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Article</th>
                            <th class="py-2 pr-4">WP ID</th>
                            <th class="py-2">Cleanup warnings</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach (($report['samples']['completion_body_cleanup'] ?? []) as $item)
                            <tr>
                                <td class="py-3 pr-4">{{ $item['slug'] ?? $item['article_id'] }}</td>
                                <td class="py-3 pr-4">{{ $item['wordpress_id'] ?? '-' }}</td>
                                <td class="py-3">{{ implode(', ', $item['warnings'] ?? []) ?: 'markup cleanup' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Old link repair progress</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Current dry-run totals and the next safe repair commands. Reachable media can be downloaded; confirmed 404 sources need an editor to replace or remove the article reference.</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-5">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Articles scanned</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['old_link_repair']['articles_checked'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Articles with old links</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['old_link_repair']['articles_with_references'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Remaining references</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['old_link_repair']['references_found'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Failed repair warnings</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['old_link_repair']['failed_repair_warnings'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Shortcodes remaining</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['old_link_repair']['shortcodes_remaining'] ?? 0) }}</p>
            </div>
        </div>

        <dl class="mt-4 grid gap-3 text-sm md:grid-cols-3">
            <div>
                <dt class="font-medium text-gray-700 dark:text-gray-200">Next repair</dt>
                <dd class="mt-1 rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $report['totals']['old_link_repair']['next_repair_command'] ?? '-' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-700 dark:text-gray-200">Media cleanup</dt>
                <dd class="mt-1 rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $report['totals']['old_link_repair']['cleanup_command'] ?? '-' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-700 dark:text-gray-200">Remaining export</dt>
                <dd class="mt-1 rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $report['totals']['old_link_repair']['export_command'] ?? '-' }}</dd>
            </div>
        </dl>

        <div class="mt-5 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Broken source and manual editor workflow</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Use this loop for old upload rows that remain after repair, especially rows already marked with failed repair or manual-review warnings.
            </p>
            <ol class="mt-4 grid gap-3 text-sm md:grid-cols-4">
                <li class="rounded-lg bg-white p-3 dark:bg-gray-900">
                    <p class="font-medium text-gray-950 dark:text-white">1. Export rows</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-300">Run the remaining export and group by source URL.</p>
                    <p class="mt-2 rounded bg-gray-50 p-2 font-mono text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $report['totals']['old_link_repair']['export_command'] ?? 'php artisan wordpress:old-link-export' }}</p>
                </li>
                <li class="rounded-lg bg-white p-3 dark:bg-gray-900">
                    <p class="font-medium text-gray-950 dark:text-white">2. Confirm source</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-300">Check the old media URL. If it is a real 404 or repeatedly unreachable, keep it as manual editorial work.</p>
                </li>
                <li class="rounded-lg bg-white p-3 dark:bg-gray-900">
                    <p class="font-medium text-gray-950 dark:text-white">3. Edit article</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-300">Open the article link below, replace the media, or remove the broken reference. Keep migrated SEO fields and redirects unless approved.</p>
                </li>
                <li class="rounded-lg bg-white p-3 dark:bg-gray-900">
                    <p class="font-medium text-gray-950 dark:text-white">4. Re-run readiness</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-300">Run repair, media cleanup, then migration readiness again after each editorial batch.</p>
                </li>
            </ol>
            <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-200">
                Confirmed 404 sources are not automatically repairable. The readiness gate should stay blocked until the article body no longer points at the old missing file.
            </p>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Checks</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4">Code</th>
                        <th class="py-2">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach (($report['checks'] ?? []) as $check)
                        <tr>
                            <td class="py-3 pr-4 font-medium">{{ strtoupper($check['status'] ?? '-') }}</td>
                            <td class="py-3 pr-4">{{ str_replace('_', ' ', $check['code'] ?? '-') }}</td>
                            <td class="py-3">{{ $check['message'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Old upload link samples</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            These samples are from the readiness dry run. Pending rows may still be fixable when the source exists; confirmed broken sources should be handled through the manual editor workflow above.
        </p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Article</th>
                        <th class="py-2 pr-4">WP ID</th>
                        <th class="py-2 pr-4">Source URL</th>
                        <th class="py-2 pr-4">Literal in body</th>
                        <th class="py-2">Status / reason</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse (($report['samples']['unresolved_wordpress_links'] ?? []) as $item)
                        <tr>
                            <td class="py-3 pr-4">
                                <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ url('/backoffice/articles/'.$item['article_id'].'/edit') }}">{{ $item['slug'] ?? $item['article_id'] }}</a>
                            </td>
                            <td class="py-3 pr-4">{{ $item['wordpress_id'] ?? '-' }}</td>
                            <td class="py-3 pr-4">{{ $item['source_url'] ?? '-' }}</td>
                            <td class="py-3 pr-4">{{ $item['literal'] ?? '-' }}</td>
                            <td class="py-3">
                                <p class="font-medium text-gray-950 dark:text-white">{{ str_replace('_', ' ', strtoupper($item['status'] ?? 'pending')) }}</p>
                                @if (! empty($item['reason']))
                                    <p class="mt-1 text-xs uppercase text-gray-500 dark:text-gray-400">{{ str_replace('_', ' ', $item['reason']) }}</p>
                                @endif
                                <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $item['message'] ?? '-' }}</p>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-3 text-gray-500" colspan="5">No unresolved old upload references.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
