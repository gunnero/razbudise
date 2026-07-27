<?php

namespace Database\Seeders;

use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Illuminate\Database\Seeder;

class RazbudiseRolePermissionSeeder extends Seeder
{
    public function run(RazbudiseRolePermissionMatrix $matrix): void
    {
        $synced = $matrix->sync();

        $this->command?->info("Synced {$synced['permissions']} RazbudiSe permissions.");
        $this->command?->info("Synced {$synced['roles']} RazbudiSe roles.");
    }
}
