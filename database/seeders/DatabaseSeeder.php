<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Grade;
use App\Models\Material;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Naikkan memory limit untuk proses seeding
        ini_set('memory_limit', '512M');

        $this->command->info('Memulai Seeding Database');

        // ── Users ────────────────────────────────────────────
        $this->command->info('Membuat 100 instruktur');
        $instructors = User::factory()->instructor()->count(100)->create();

        $this->command->info('Membuat 4.900 siswa');
        $students = User::factory()->student()->count(4900)->create();

        // ── Courses ──────────────────────────────────────────
        $this->command->info('Membuat 50 course');
        $courses = Course::factory()->count(50)->create([
            'instructor_id' => fn() => $instructors->random()->id,
        ]);

        // ── Enrollments (~100 students per course) ────────────
        $this->command->info('Membuat enrollments course');
        foreach ($courses as $course) {
            $enrollmentCount = rand(80, 120);
            $randomStudents  = $students->random(min($enrollmentCount, $students->count()));
            $attachData = [];
            foreach ($randomStudents as $student) {
                $attachData[$student->id] = [
                    'enrolled_at' => fake()->dateTimeBetween('-6 months', 'now'),
                ];
            }
            $course->students()->attach($attachData);
        }
        unset($students); // Bebaskan memory setelah attach selesai

        // ── Quizzes (5 per course = 250) ──────────────────────
        $this->command->info('Membuat 250 quizzes');
        // Simpan hanya ID, bukan full model collection
        $quizIds = collect();
        foreach ($courses as $course) {
            $ids = Quiz::factory()->count(5)->create(['course_id' => $course->id])->pluck('id');
            $quizIds = $quizIds->merge($ids);
        }

        // ── Questions (20 per quiz = 5.000) ──────────────────
        $this->command->info('Membuat 5.000 pertanyaan');
        foreach ($quizIds as $quizId) {
            Question::factory()->count(20)->create(['quiz_id' => $quizId]);
        }

        // ── Materials (10 per course = 500) ──────────────────
        $this->command->info('Membuat 500 materi');
        foreach ($courses as $course) {
            Material::factory()->count(10)->create(['course_id' => $course->id]);
        }

        // ── Assignments (5 per course = 250) ─────────────────
        $this->command->info('Membuat 250 assignments');
        $assignmentIds = collect();
        foreach ($courses as $course) {
            $ids = Assignment::factory()->count(5)->create(['course_id' => $course->id])->pluck('id');
            $assignmentIds = $assignmentIds->merge($ids);
        }

        unset($courses, $quizIds, $assignmentIds); // tidak dipakai lagi

        // ── Quiz Attempts (~100 per quiz = ~25.000) ───────────
        // PENTING: jangan kumpulkan di $collection — langsung insert, query nanti dari DB
        $this->command->info('Membuat quiz attempts');
        $quizzes = Quiz::with('course.students')->get();
        $attemptCount = 0;
        foreach ($quizzes as $quiz) {
            $enrolledStudents = $quiz->course->students;
            if ($enrolledStudents->isEmpty()) continue;

            $sample = $enrolledStudents->random(min(100, $enrolledStudents->count()));
            foreach ($sample as $student) {
                QuizAttempt::factory()->create([
                    'quiz_id' => $quiz->id,
                    'user_id' => $student->id,
                ]);
                $attemptCount++;
            }
            unset($enrolledStudents, $sample);
        }
        $this->command->info("  → {$attemptCount} quiz attempts dibuat");
        unset($quizzes);

        // ── Submissions (~50 per assignment = ~12.500) ────────
        $this->command->info('Membuat submissions');
        $assignments = Assignment::with('course.students')->get();
        $submissionCount = 0;
        foreach ($assignments as $assignment) {
            $enrolledStudents = $assignment->course->students;
            if ($enrolledStudents->isEmpty()) continue;

            $sample = $enrolledStudents->random(min(50, $enrolledStudents->count()));
            foreach ($sample as $student) {
                Submission::factory()->create([
                    'assignment_id' => $assignment->id,
                    'user_id'       => $student->id,
                ]);
                $submissionCount++;
            }
            unset($enrolledStudents, $sample);
        }
        $this->command->info("  → {$submissionCount} submissions dibuat");
        unset($assignments);

        // ── Grades dari Quiz Attempts ─────────────────────────
        $this->command->info('Membuat grades untuk quiz attempts...');
        $gradeCount = 0;
        QuizAttempt::with('quiz')
            ->whereNotNull('completed_at')
            ->whereNotNull('score')
            ->chunk(500, function ($attempts) use (&$gradeCount) {
                $insert = [];
                foreach ($attempts as $attempt) {
                    $insert[] = [
                        'user_id'        => $attempt->user_id,
                        'course_id'      => $attempt->quiz->course_id,
                        'gradeable_type' => QuizAttempt::class,
                        'gradeable_id'   => $attempt->id,
                        'score'          => $attempt->score,
                        'max_score'      => 100,
                        'percentage'     => $attempt->score,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];
                    $gradeCount++;
                }
                DB::table('grades')->insert($insert);
            });
        $this->command->info("  → {$gradeCount} grades dari quiz attempts");

        // ── Grades dari Submissions ───────────────────────────
        $this->command->info('Membuat grades untuk submissions...');
        Submission::with('assignment')
            ->whereNotNull('graded_at')
            ->whereNotNull('score')
            ->chunk(500, function ($submissions) use (&$gradeCount) {
                $insert = [];
                foreach ($submissions as $submission) {
                    $maxScore   = $submission->assignment->max_score ?: 100;
                    $insert[] = [
                        'user_id'        => $submission->user_id,
                        'course_id'      => $submission->assignment->course_id,
                        'gradeable_type' => Submission::class,
                        'gradeable_id'   => $submission->id,
                        'score'          => $submission->score,
                        'max_score'      => $maxScore,
                        'percentage'     => ($submission->score / $maxScore) * 100,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];
                    $gradeCount++;
                }
                DB::table('grades')->insert($insert);
            });

        // ── Summary ───────────────────────────────────────────
        $this->command->info('');
        $this->command->info('Database seeding selesai dengan sukses!');
        $this->command->info('Ringkasan:');
        $this->command->info('- Users: '        . User::count());
        $this->command->info('- Courses: '       . Course::count());
        $this->command->info('- Enrollments: '   . DB::table('course_enrollments')->count());
        $this->command->info('- Quizzes: '       . Quiz::count());
        $this->command->info('- Questions: '     . Question::count());
        $this->command->info('- Materials: '     . Material::count());
        $this->command->info('- Assignments: '   . Assignment::count());
        $this->command->info('- Quiz Attempts: ' . QuizAttempt::count());
        $this->command->info('- Submissions: '   . Submission::count());
        $this->command->info('- Grades: '        . Grade::count());
    }
}
