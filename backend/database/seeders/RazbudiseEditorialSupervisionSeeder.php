<?php

namespace Database\Seeders;

use App\Services\Editorial\EditorialSupervisionService;
use Illuminate\Database\Seeder;

class RazbudiseEditorialSupervisionSeeder extends Seeder
{
    public function run(EditorialSupervisionService $supervisions): void
    {
        $supervision = $supervisions->syncLaunchMapping();

        $this->command?->info("Synced primary supervision author_id={$supervision->author_id} editor_id={$supervision->editor_id}.");
    }
}
