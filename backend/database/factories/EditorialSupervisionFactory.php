<?php

namespace Database\Factories;

use App\Models\Author;
use App\Models\EditorialSupervision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EditorialSupervision>
 */
class EditorialSupervisionFactory extends Factory
{
    protected $model = EditorialSupervision::class;

    public function definition(): array
    {
        return [
            'editor_id' => User::factory(),
            'author_id' => Author::factory(),
            'is_primary' => true,
            'starts_at' => now(),
            'ends_at' => null,
            'created_by' => null,
        ];
    }
}
