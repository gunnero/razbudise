<?php

namespace App\Jobs;

use App\Services\Wordpress\WordpressRestImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportWordpressPostsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $perPage = 20,
        private readonly ?int $page = null,
    ) {}

    public function handle(WordpressRestImporter $importer): void
    {
        if ($this->page) {
            $importer->importPostsPage($this->baseUrl, $this->perPage, $this->page);

            return;
        }

        $importer->importPosts($this->baseUrl, $this->perPage);
    }
}
