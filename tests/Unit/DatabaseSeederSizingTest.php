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
        $instructorIds = range(1, 40);
        $categoryIds = range(1, 10);

        $detailedCourseDefs = $reflection->getMethod('detailedCourseDefs')->invoke($seeder, $instructorIds, $categoryIds);
        $generatedCourseDefs = $reflection->getMethod('generatedCourseDefs')->invoke($seeder, $instructorIds, $categoryIds, 40);
        $courseDefs = $reflection->getMethod('withBenchmarkActivityTargets')->invoke($seeder, [...$detailedCourseDefs, ...$generatedCourseDefs]);
        $counts = $this->countActivities($courseDefs);

        $this->assertSame(40, $reflection->getReflectionConstant('INSTRUCTOR_COUNT')->getValue());
        $this->assertSame(1960, $reflection->getReflectionConstant('STUDENT_COUNT')->getValue());
        $this->assertSame(30, $reflection->getReflectionConstant('MIN_STUDENTS_PER_COURSE')->getValue());
        $this->assertSame(60, $reflection->getReflectionConstant('MAX_STUDENTS_PER_COURSE')->getValue());
        $this->assertSame(3, $reflection->getReflectionConstant('QUIZ_ATTEMPTS_PER_STUDENT')->getValue());
        $this->assertSame(25, $reflection->getReflectionConstant('MIN_QUESTIONS_PER_QUIZ')->getValue());
        $this->assertSame(50, $reflection->getReflectionConstant('MAX_QUESTIONS_PER_QUIZ')->getValue());

        $this->assertSame(50, count($courseDefs));
        $this->assertSame(600, $counts['material']);
        $this->assertSame(200, $counts['quiz']);
        $this->assertSame(600, $counts['assignment']);

        foreach ($courseDefs as $courseDef) {
            $courseCounts = $this->countActivities([$courseDef]);

            $this->assertSame(12, $courseCounts['material']);
            $this->assertSame(4, $courseCounts['quiz']);
            $this->assertSame(12, $courseCounts['assignment']);
        }
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
