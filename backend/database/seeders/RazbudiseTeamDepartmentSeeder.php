<?php

namespace Database\Seeders;

use App\Services\Team\RazbudiseTeamDepartmentStructure;
use Illuminate\Database\Seeder;

class RazbudiseTeamDepartmentSeeder extends Seeder
{
    public function run(RazbudiseTeamDepartmentStructure $departments): void
    {
        $synced = $departments->sync();

        $this->command?->info("Synced {$synced->count()} RazbudiSe team departments.");
    }
}
