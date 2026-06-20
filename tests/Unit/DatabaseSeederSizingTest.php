<?php

namespace Tests\Unit;

use Database\Seeders\DatabaseSeeder;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class DatabaseSeederSizingTest extends TestCase
{
    #[Test]
    public function full_benchmark_seeder_targets_requested_dataset_size(): void
    {
        $seeder = new DatabaseSeeder;
        $reflection = new ReflectionClass($seeder);
        $instructorIds = range(1, 100);
        $categoryIds = range(1, 10);

        $detailedCourseDefs = $reflection->getMethod('detailedCourseDefs')->invoke($seeder, $instructorIds, $categoryIds);
        $generatedCourseDefs = $reflection->getMethod('generatedCourseDefs')->invoke($seeder, $instructorIds, $categoryIds, 40);
        $counts = $this->countActivities([...$detailedCourseDefs, ...$generatedCourseDefs]);

        $this->assertSame(50, count($detailedCourseDefs) + count($generatedCourseDefs));
        $this->assertSame(500, $counts['material']);
        $this->assertSame(250, $counts['quiz']);
        $this->assertSame(250, $counts['assignment']);
        $this->assertSame(20, $reflection->getReflectionConstant('QUESTIONS_PER_QUIZ')->getValue());
    }

    /**
     * @param  array<int, array{activities: array<string, array<int, array{type: string}>>}>  $courseDefs
     * @return array{material: int, quiz: int, assignment: int}
     */
    private function countActivities(array $courseDefs): array
    {
        $counts = ['material' => 0, 'quiz' => 0, 'assignment' => 0];

        foreach ($courseDefs as $courseDef) {
            foreach ($courseDef['activities'] as $activities) {
                foreach ($activities as $activity) {
                    $counts[$activity['type']]++;
                }
            }
        }

        return $counts;
    }
}
