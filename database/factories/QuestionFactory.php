<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuizQuestionSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Question>
 */
class QuestionFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Question $question): void {
            $nextSlot = QuizQuestionSlot::query()
                ->where('quiz_id', $question->quiz_id)
                ->max('slot') + 1;

            QuizQuestionSlot::query()->firstOrCreate([
                'quiz_id' => $question->quiz_id,
                'question_id' => $question->id,
            ], [
                'slot' => $nextSlot,
                'page' => 1,
                'max_points' => $question->points,
                'require_previous' => false,
            ]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $options = [
            'A' => fake()->sentence(4),
            'B' => fake()->sentence(4),
            'C' => fake()->sentence(4),
            'D' => fake()->sentence(4),
        ];

        $correctAnswer = fake()->randomElement(['A', 'B', 'C', 'D']);

        return [
            'quiz_id' => \App\Models\Quiz::factory(),
            'question_text' => fake()->sentence().'?',
            'options' => $options,
            'correct_answer' => $correctAnswer,
            'points' => fake()->numberBetween(1, 5),
        ];
    }
}
