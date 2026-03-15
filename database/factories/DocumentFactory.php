<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'content' => fake()->paragraphs(3, true),
            'embedding' => array_map(fn () => fake()->randomFloat(6, -1, 1), range(1, 4096)),
            'source' => fake()->word().'.txt',
            'chunk_index' => 0,
        ];
    }
}
