# Thesis Evidence

## Moodle-Inspired Controlled LMS Benchmark — Implementation Complete

This document captures the evidence from the Moodle-inspired controlled LMS
benchmark implementation for use in thesis writing.

### New Table Counts (from deterministic seed)

| Table | Rows |
|-------|------|
| `quiz_attempt_questions` | 250 |
| `quiz_attempt_steps` | 250 |
| `quiz_attempt_step_data` | 0 (created by live submission) |
| `quiz_grades` | 55 |
| `grade_categories` | 12 |
| `grade_histories` | 0 (created by live updates) |
| `grade_item_histories` | 0 |
| `grade_category_histories` | 0 |
| `assignment_allocated_markers` | 18 |
| `assignment_marks` | 0 |
| `course_categories` | 12 |
| `course_groupings` | 4 |
| `course_grouping_groups` | 10 |

### Fixture Target Pool Counts

| Pool | Count | Purpose |
|------|-------|---------|
| READABLE_MATERIAL_TARGETS | 591 | Actor-specific material reads |
| READABLE_QUIZ_TARGETS | 175 | Actor-specific quiz reads |
| READABLE_ASSIGNMENT_TARGETS | 160 | Actor-specific assignment reads |
| WRITABLE_MATERIAL_DOWNLOAD_TARGETS | 591 | Material download writes |
| WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS | 121 | Assignment submission writes |
| WRITABLE_QUIZ_ATTEMPT_TARGETS | 175 | Quiz attempt start writes |
| GRADING_TARGETS | 48 | Instructor grading |
| GRADE_UPDATE_TARGETS | 98 | Grade update + history writes |
| QUIZ_DETAIL_ATTEMPT_TARGETS | 50 | Normalized quiz detail reads |
| QUIZ_AGGREGATE_GRADE_TARGETS | 55 | Quiz aggregate grade writes |
| GRADE_CATEGORY_READ_TARGETS | 8 | Gradebook with category hierarchy |
| MARKER_GRADE_TARGETS | 18 | Multi-marker grading fan-out |
| GROUPING_RESTRICTED_MODULE_TARGETS | 9 | Grouping-based controlled failure |
| NESTED_AVAILABILITY_LOCKED_TARGETS | 22 | Nested AND/OR blocked access |
| NESTED_AVAILABILITY_UNLOCK_TARGETS | 3 | Nested AND/OR granted access |

### Workload Percentages

- Read-heavy: 80% read / 20% write
- Write-heavy: 40% read / 60% write

### Benchmark-Relevant Complexity Per Workload Branch

**Read-heavy exercises:**
- Course structure with nested availability and groupings
- Gradebook with categories
- Quiz attempt review using normalized detail
- Controlled access failures for grouping/nested availability

**Write-heavy exercises:**
- Quiz start and submit with normalized attempt detail
- Quiz aggregate grade update
- Assignment submission
- Marker grading
- Grade update with history
- Completion cascade that changes availability

### Cache Invalidation Matrix

| Write Action | Invalidated Tags |
|---|---|
| Quiz submit | attempt detail, quiz aggregate grade, gradebook, user grade, course structure |
| Marker grade | submission, marker queue, gradebook, user grade, grade history |
| Grade update | grade history, gradebook, user grade, course structure |
| Completion write | course structure, availability-dependent reads |

### Test Results

| Test Suite | Count | Status |
|---|---|---|
| Authorization | 27 | PASS |
| Availability | 36 | PASS |
| CourseCompletion | 4 | PASS |
| CourseStructure | 17 | PASS |
| AssignmentController | 26 | PASS |
| GradebookController | 28 | PASS |
| MaterialController | 24 | PASS |
| QuizController | 34 | PASS |
| SeedData | 21 | PASS |
| FixtureGeneratorGuard | 5 | PASS |
| FixtureValidity | 21 | PASS |
| WorkloadGuard | 13 | PASS |
| BenchmarkDashboard | 2 | PASS |
| BenchmarkResultsService | 1 | PASS |

### Known Simplifications

The following are documented simplifications where the benchmark differs from
Moodle's architecture, as defined in [`CONTEXT.md`](../CONTEXT.md):

| Simplification | Description |
|---|---|
| **Partial Capability Model** | Context roles and inherited checks exist, but not Moodle's full capability matrix or role override rules. Currently the widest-reaching simplification. |
| **Simplified Completion Aggregation** | Module completion and course completion criteria exist, but not every aggregation method, default setting, or cron-driven reaggregation. |
| **Simple Enrolment Model** | Single `course_enrollments` table instead of Moodle's method instances (`enrol`) + user records (`user_enrolments`). |
| **Non-Content-Addressed File Storage** | Owner-based lookup (`owner_type`/`owner_id`) instead of Moodle's content-addressed `files` table. |

### Verification Snapshot

- **Date:** 2026-06-15
- **API test suites:** 196+ tests, 1151+ assertions — all PASS
- **ReadThroughStrategy:** 20 tests, 58 assertions — all PASS
- **BenchmarkDashboard + ResultsService + WorkloadGuard:** 16 tests, 203 assertions — all PASS
- **Fixture tests** (consolidated, 16 tests, 17943 assertions) — all PASS, sequential execution required
- **Fixture test runtime:** ~104s (includes behavioral quiz submit test)
- **Pilot benchmark:** both read-heavy and write-heavy completed with no unexpected errors
- **PHPUnit metadata:** 122 deprecated `/** @test */` annotations across 7 API test files migrated to `#[Test]` attributes — zero metadata warnings remaining
- **Pint formatting:** clean — 0 files changed
- **k6 JavaScript syntax:** verified with `node --check` on all workload files
- **k6 quiz answer payloads:** now use real question IDs instead of synthetic keys
- **Quiz score semantics:** `isQuizPassingGrade()` no longer double-scales percentage by max points
- **Fixture exhaustion guards:** spent-attempt check includes `finished` status matching service behavior
- **Thesis-facing wording:** aligned with `CONTEXT.md` — "Moodle-Inspired Controlled LMS Benchmark"

Non-blocking notes:
- Fixture tests must be run sequentially after a clean test database reset.
- PHPUnit deprecation warnings: fully resolved across all API and benchmark test files.

### Implementation Notes

- No full Moodle question engine, role/capability system, availability plugin engine, grade formula system, or full Moodle file API introduced
- Thesis-facing wording aligned with `CONTEXT.md`: "Moodle-Inspired Controlled LMS Benchmark"
