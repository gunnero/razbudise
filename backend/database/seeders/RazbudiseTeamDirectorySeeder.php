<?php

namespace Database\Seeders;

use App\Services\Team\RazbudiseTeamDepartmentStructure;
use App\Services\Team\RazbudiseTeamPositionStructure;
use App\Services\Team\RazbudiseUserTeamProfileLinker;
use Illuminate\Database\Seeder;

class RazbudiseTeamDirectorySeeder extends Seeder
{
    public function run(
        RazbudiseTeamDepartmentStructure $departments,
        RazbudiseTeamPositionStructure $positions,
        RazbudiseUserTeamProfileLinker $linker,
    ): void {
        $syncedDepartments = $departments->sync();
        $syncedPositions = $positions->sync();
        $linked = $linker->sync();

        $this->command?->info("Synced {$syncedDepartments->count()} RazbudiSe team departments.");
        $this->command?->info("Synced {$syncedPositions->count()} RazbudiSe team positions.");
        $this->command?->info("Linked {$linked['members']->count()} RazbudiSe user/team profiles.");
        $this->command?->info("Updated {$linked['menu_items_updated']} public team navigation labels.");
    }
}
