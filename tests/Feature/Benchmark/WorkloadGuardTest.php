<?php

namespace Tests\Feature\Benchmark;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkloadGuardTest extends TestCase
{
    private function getScenarioPath(string $name): string
    {
        return base_path("tests/Benchmark/k6/{$name}");
    }

    #[Test]
    public function only_two_workload_files_exist(): void
    {
        $files = scandir(base_path('tests/Benchmark/k6/'));
        $workloadFiles = array_values(array_filter($files, function ($f) {
            return str_ends_with($f, '-scenario.js');
        }));

        $this->assertEquals(
            ['read-heavy-scenario.js', 'write-heavy-scenario.js'],
            array_values($workloadFiles),
            'Only read-heavy and write-heavy scenarios should exist in tests/Benchmark/k6/'
        );
    }

    #[Test]
    public function read_heavy_header_declares_80_20(): void
    {
        $this->assertStringContainsString(
            '80% Read, 20% Write',
            file_get_contents($this->getScenarioPath('read-heavy-scenario.js'))
        );
    }

    #[Test]
    public function write_heavy_header_declares_40_60(): void
    {
        $this->assertStringContainsString(
            '40% Read, 60% Write',
            file_get_contents($this->getScenarioPath('write-heavy-scenario.js'))
        );
    }

    #[Test]
    public function read_heavy_exact_branch_weights(): void
    {
        $branches = $this->parseBranches($this->getScenarioPath('read-heavy-scenario.js'));

        $expected = [
            'Course Structure' => ['weight' => 0.25, 'type' => 'read'],
            'Material Detail' => ['weight' => 0.10, 'type' => 'read'],
            'Quiz Detail' => ['weight' => 0.05, 'type' => 'read'],
            'Assignment Detail' => ['weight' => 0.08, 'type' => 'read'],
            'Gradebook' => ['weight' => 0.12, 'type' => 'read'],
            'User Grades' => ['weight' => 0.05, 'type' => 'read'],
            'Course Materials List' => ['weight' => 0.03, 'type' => 'read'],
            'Course Completion State' => ['weight' => 0.02, 'type' => 'read'],
            'Controlled Failures' => ['weight' => 0.07, 'type' => 'read'],
            'Quiz Attempt Result' => ['weight' => 0.03, 'type' => 'read'],
            'Start Quiz Attempt' => ['weight' => 0.10, 'type' => 'write'],
            'Submit Assignment' => ['weight' => 0.10, 'type' => 'write'],
        ];

        foreach ($expected as $label => $spec) {
            $this->assertArrayHasKey($label, $branches, "Missing expected branch: {$label}");
            $this->assertEquals(
                $spec['weight'],
                $branches[$label]['weight'],
                "Branch '{$label}' weight mismatch. Expected {$spec['weight']}, got {$branches[$label]['weight']}"
            );
            $this->assertEquals(
                $spec['type'],
                $branches[$label]['type'],
                "Branch '{$label}' type mismatch. Expected {$spec['type']}, got {$branches[$label]['type']}"
            );
        }

        // Verify exact read/write totals
        $this->verifyReadWriteTotals($branches, 0.80, 0.20);
    }

    #[Test]
    public function write_heavy_exact_branch_weights(): void
    {
        $branches = $this->parseBranches($this->getScenarioPath('write-heavy-scenario.js'));

        $expected = [
            'Course Structure' => ['weight' => 0.10, 'type' => 'read'],
            'Gradebook' => ['weight' => 0.10, 'type' => 'read'],
            'Activity Detail' => ['weight' => 0.05, 'type' => 'read'],
            'User Grades/Performance' => ['weight' => 0.05, 'type' => 'read'],
            'Completion Cascade' => ['weight' => 0.05, 'type' => 'read'],
            'Controlled Failures' => ['weight' => 0.05, 'type' => 'read'],
            'Assignment Submission' => ['weight' => 0.20, 'type' => 'write'],
            'Quiz Submit Chain' => ['weight' => 0.15, 'type' => 'write'],
            'Material Download' => ['weight' => 0.05, 'type' => 'write'],
            'Marker Grade' => ['weight' => 0.05, 'type' => 'write'],
            'Grade Submission' => ['weight' => 0.05, 'type' => 'write'],
            'Grade Update via PUT' => ['weight' => 0.05, 'type' => 'write'],
            'Unauthorized Grade Update' => ['weight' => 0.05, 'type' => 'write'],
        ];

        foreach ($expected as $label => $spec) {
            $this->assertArrayHasKey($label, $branches, "Missing expected branch: {$label}");
            $this->assertEquals(
                $spec['weight'],
                $branches[$label]['weight'],
                "Branch '{$label}' weight mismatch. Expected {$spec['weight']}, got {$branches[$label]['weight']}"
            );
            $this->assertEquals(
                $spec['type'],
                $branches[$label]['type'],
                "Branch '{$label}' type mismatch. Expected {$spec['type']}, got {$branches[$label]['type']}"
            );
        }

        // Verify exact read/write totals
        $this->verifyReadWriteTotals($branches, 0.40, 0.60);
    }

    #[Test]
    public function read_heavy_no_hidden_writes(): void
    {
        $this->assertStringNotContainsString(
            '/download',
            file_get_contents($this->getScenarioPath('read-heavy-scenario.js')),
            'Read-heavy scenario must not call material download (hidden write)'
        );
    }

    #[Test]
    public function write_heavy_material_download_classified_as_write(): void
    {
        $branches = $this->parseBranches($this->getScenarioPath('write-heavy-scenario.js'));
        $this->assertArrayHasKey('Material Download', $branches);
        $this->assertEquals('write', $branches['Material Download']['type']);
    }

    #[Test]
    public function read_heavy_uses_writable_pools_not_broad_pools(): void
    {
        $content = file_get_contents($this->getScenarioPath('read-heavy-scenario.js'));
        $branches = $this->parseBranches($this->getScenarioPath('read-heavy-scenario.js'));

        // No broad course-only pools should be imported or used
        $this->assertStringNotContainsString('materialIdsForCourse', $content);
        $this->assertStringNotContainsString('quizIdsForCourse', $content);
        $this->assertStringNotContainsString('assignmentIdsForCourse', $content);

        // Write branches must use WRITABLE pools
        foreach ($branches as $label => $branch) {
            if ($branch['type'] !== 'write') {
                continue;
            }

            if (str_contains($label, 'Quiz Attempt')) {
                $this->assertStringContainsString('WRITABLE_QUIZ_ATTEMPT_TARGETS', $branch['source']);
            }
            if (str_contains($label, 'Assignment')) {
                $this->assertStringContainsString('WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS', $branch['source']);
            }
        }
    }

    #[Test]
    public function write_heavy_uses_writable_pools_not_broad_pools(): void
    {
        $content = file_get_contents($this->getScenarioPath('write-heavy-scenario.js'));
        $branches = $this->parseBranches($this->getScenarioPath('write-heavy-scenario.js'));

        // No broad course-only pools in write sections
        $this->assertStringNotContainsString('materialIdsForCourse', $content);
        $this->assertStringNotContainsString('quizIdsForCourse', $content);
        $this->assertStringNotContainsString('assignmentIdsForCourse', $content);

        // Write branches must use WRITABLE pools
        foreach ($branches as $label => $branch) {
            if ($branch['type'] !== 'write') {
                continue;
            }

            if (str_contains($label, 'Assignment Submission')) {
                $this->assertStringContainsString('WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS', $branch['source']);
            }
            if (str_contains($label, 'Quiz Submit')) {
                $this->assertStringContainsString('WRITABLE_QUIZ_ATTEMPT_TARGETS', $branch['source']);
            }
            if (str_contains($label, 'Material Download')) {
                $this->assertStringContainsString('WRITABLE_MATERIAL_DOWNLOAD_TARGETS', $branch['source']);
            }
            if (str_contains($label, 'Completion Cascade')) {
                $this->assertStringContainsString('WRITABLE_MATERIAL_DOWNLOAD_TARGETS', $branch['source']);
            }
        }
    }

    #[Test]
    public function write_heavy_grade_update_uses_bounded_score_helper(): void
    {
        $content = file_get_contents($this->getScenarioPath('write-heavy-scenario.js'));

        // Must NOT contain the old hard-coded 70-100 range
        $this->assertStringNotContainsString(
            'Math.floor(Math.random() * 31) + 70',
            $content,
            'Grade update score must use scoreWithinMax(target), not a fixed 70-100 range'
        );

        // Must import scoreWithinMax from fixtures
        $this->assertStringContainsString(
            'scoreWithinMax',
            $content,
            'write-heavy-scenario.js must import scoreWithinMax helper'
        );

        // Both authorized and unauthorized grade update branches must use the helper
        $branches = $this->parseBranches($this->getScenarioPath('write-heavy-scenario.js'));

        $gradeUpdateSource = $branches['Grade Update via PUT']['source'] ?? '';
        $this->assertStringContainsString(
            'scoreWithinMax(target)',
            $gradeUpdateSource,
            'Authorized grade update branch must use scoreWithinMax(target)'
        );

        $unauthUpdateSource = $branches['Unauthorized Grade Update']['source'] ?? '';
        $this->assertStringContainsString(
            'scoreWithinMax(target)',
            $unauthUpdateSource,
            'Unauthorized grade update branch must use scoreWithinMax(target)'
        );
    }

    #[Test]
    public function read_heavy_uses_grouping_restricted_targets(): void
    {
        $content = file_get_contents($this->getScenarioPath('read-heavy-scenario.js'));

        $this->assertStringContainsString(
            'GROUPING_RESTRICTED_MODULE_TARGETS',
            $content,
            'read-heavy-scenario.js must import and use GROUPING_RESTRICTED_MODULE_TARGETS'
        );

        $this->assertStringContainsString(
            'cf-grouping-restricted',
            $content,
            'read-heavy-scenario.js must have a grouping-restricted controlled failure branch'
        );

        $this->assertStringContainsString(
            'target.expectedStatus || 404',
            $content,
            'Grouping-restricted branch must default expectedStatus to 404'
        );

        // Verify the pool is referenced via activityPath and headersFor
        $this->assertStringContainsString('GROUPING_RESTRICTED_MODULE_TARGETS', $content);
        $this->assertStringContainsString('activityPath(target)', $content);
        $this->assertStringContainsString('headersFor(target.userId)', $content);
    }

    #[Test]
    public function read_heavy_quiz_attempt_result_uses_detail_pool(): void
    {
        $content = file_get_contents($this->getScenarioPath('read-heavy-scenario.js'));

        $this->assertStringContainsString(
            'QUIZ_DETAIL_ATTEMPT_TARGETS',
            $content,
            'read-heavy-scenario.js must import and use QUIZ_DETAIL_ATTEMPT_TARGETS'
        );

        $this->assertStringContainsString(
            'target.attemptId',
            $content,
            'read-heavy-scenario.js must reference the attempt ID in the quiz attempt result route'
        );
        $this->assertStringContainsString(
            'target.quizId',
            $content,
            'read-heavy-scenario.js must reference the quiz ID in the quiz attempt result route'
        );
        $this->assertStringContainsString(
            'attempts/',
            $content,
            'read-heavy-scenario.js must call the attempts/ route'
        );

        // Verify the branch weight is correct
        $branches = $this->parseBranches($this->getScenarioPath('read-heavy-scenario.js'));
        $this->assertArrayHasKey('Quiz Attempt Result', $branches);
        $this->assertEquals(0.03, $branches['Quiz Attempt Result']['weight']);
        $this->assertEquals('read', $branches['Quiz Attempt Result']['type']);
    }

    #[Test]
    public function write_heavy_cascade_checks_all_three_steps(): void
    {
        $branches = $this->parseBranches($this->getScenarioPath('write-heavy-scenario.js'));
        $this->assertArrayHasKey('Completion Cascade', $branches);
        $source = $branches['Completion Cascade']['source'];

        $this->assertStringContainsString('wh-cascade-pre', $source);
        $this->assertStringContainsString('wh-cascade-write', $source);
        $this->assertStringContainsString('wh-cascade-post', $source);
    }

    // ─── Helper Methods ────────────────────────────────────

    /**
     * Verify that read and write branches total to expected ratios.
     */
    private function verifyReadWriteTotals(array $branches, float $expectedRead, float $expectedWrite): void
    {
        $readTotal = 0.0;
        $writeTotal = 0.0;
        foreach ($branches as $branch) {
            if ($branch['type'] === 'read') {
                $readTotal += $branch['weight'];
            } else {
                $writeTotal += $branch['weight'];
            }
        }

        $this->assertEquals($expectedRead, round($readTotal, 2), "Read total must be {$expectedRead}");
        $this->assertEquals($expectedWrite, round($writeTotal, 2), "Write total must be {$expectedWrite}");
    }

    /**
     * Parse a k6 scenario file and return structured branch data.
     *
     * Reads comment headers in the format:
     *   // ── <N>% — <Label> (TYPE, ...) ──
     *
     * Associates each header with its action threshold from:
     *   } else if (action < X) {
     *
     * Or the final:
     *   } else {
     *
     * Branches are returned keyed by label with ['weight' => float, 'type' => 'read'|'write', 'source' => string].
     * Weights are individual (not cumulative).
     */
    private function parseBranches(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        $raw = []; // ordered list of {label, type, threshold, startLine}
        $currentLabel = null;
        $currentType = null;
        $currentThreshold = null;
        $currentStartLine = null;

        foreach ($lines as $i => $line) {
            // Match: // ── <N>% — <Label> (TYPE[,...]) ──
            // Using /u for unicode box-drawing chars
            if (preg_match('/\/\/.*?─+\s*(\d+)%\s*[—–-]\s*(.+?)\s*\((\w+)/u', $line, $m)) {
                // Save previous
                if ($currentLabel !== null && $currentThreshold !== null && $currentThreshold !== 'final') {
                    $raw[] = [
                        'label' => $currentLabel,
                        'type' => $currentType,
                        'cumulative' => $currentThreshold,
                        'startLine' => $currentStartLine,
                    ];
                } elseif ($currentLabel !== null && $currentThreshold === 'final') {
                    // Will compute after
                    $raw[] = [
                        'label' => $currentLabel,
                        'type' => $currentType,
                        'cumulative' => 'final',
                        'startLine' => $currentStartLine,
                    ];
                }

                $pct = (int) $m[1];
                $fullLabel = trim($m[2]);
                $typePrefix = strtolower($m[3]);

                // Simplify label to a canonical form
                $label = $this->canonicalLabel($fullLabel, $typePrefix);

                $currentLabel = $label;
                $currentType = $this->canonicalType($typePrefix);
                $currentThreshold = null;
                $currentStartLine = $i;

                continue;
            }

            // Match cumulative threshold: if (action < X) or } else if (action < X) {
            if ($currentLabel !== null && preg_match('/(?:\}\s*else\s+)?if\s*\(action\s*<\s*([\d.]+)\)/', $line, $m)) {
                $currentThreshold = (float) $m[1];

                continue;
            }

            // Match final } else {
            if ($currentLabel !== null && preg_match('/\}\s*else\s*\{/', $line) && $currentThreshold === null) {
                $currentThreshold = 'final';
            }
        }

        // Save last branch
        if ($currentLabel !== null && $currentThreshold !== null) {
            if ($currentThreshold === 'final') {
                $raw[] = [
                    'label' => $currentLabel,
                    'type' => $currentType,
                    'cumulative' => 'final',
                    'startLine' => $currentStartLine,
                ];
            } else {
                $raw[] = [
                    'label' => $currentLabel,
                    'type' => $currentType,
                    'cumulative' => $currentThreshold,
                    'startLine' => $currentStartLine,
                ];
            }
        }

        // Convert cumulative to individual weights
        $prevCumulative = 0;
        $result = [];
        $lastIndex = count($raw) - 1;

        foreach ($raw as $idx => $entry) {
            $cumulative = $entry['cumulative'];

            if ($cumulative === 'final') {
                $weight = round(1.0 - $prevCumulative, 2);
            } else {
                $weight = round($cumulative - $prevCumulative, 2);
                $prevCumulative = $cumulative;
            }

            // Collect source lines (up to 30 lines from start)
            $sourceLines = [];
            $endLine = min($entry['startLine'] + 30, count($lines) - 1);
            for ($i = $entry['startLine']; $i <= $endLine; $i++) {
                $sourceLines[] = $lines[$i];
            }

            $result[$entry['label']] = [
                'weight' => $weight,
                'type' => $entry['type'],
                'source' => implode("\n", $sourceLines),
            ];
        }

        return $result;
    }

    /**
     * Convert full branch label to a canonical test label.
     */
    private function canonicalLabel(string $fullLabel, string $typePrefix): string
    {
        $label = trim($fullLabel);

        // Normalize separators
        $label = str_replace("\u{2192}", '→', $label);

        // Write-heavy specific mappings
        $label = str_replace(
            [
                'User Grades/Performance',
                'Completion Cascade: read → write → read',
                'Grade Update via PUT /api/grades/{id}',
                'Controlled Failure: unauthorized grade update',
                'Quiz Submit Chain: start → submit',
            ],
            [
                'User Grades/Performance',
                'Completion Cascade',
                'Grade Update via PUT',
                'Unauthorized Grade Update',
                'Quiz Submit Chain',
            ],
            $label
        );

        return $label;
    }

    /**
     * Map type prefix from comment header to canonical type.
     */
    private function canonicalType(string $typePrefix): string
    {
        $t = strtolower(trim($typePrefix));

        // 'cascade' branches are reads that cascade into writes
        if ($t === 'cascade') {
            return 'read';
        }

        // These are write operations even if header says 'expect'
        if ($t === 'expect') {
            return 'write';
        }

        return $t === 'write' ? 'write' : 'read';
    }
}
