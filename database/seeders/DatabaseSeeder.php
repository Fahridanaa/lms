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

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('Memulai Seeding Database');

        // Create Users
        $this->command->info('Membuat 100 instruktur');
        $instructors = User::factory()->instructor()->count(100)->create();

        $this->command->info('Membuat 4.900 siswa');
        $students = User::factory()->student()->count(4900)->create();

        // Create Courses
        $this->command->info('Membuat 50 course');
        $courses = Course::factory()->count(50)->create([
            'instructor_id' => fn() => $instructors->random()->id,
        ]);

        // Create Course Enrollments (~100 students per course)
        $this->command->info('Membuat enrollments course');
        foreach ($courses as $course) {
            $enrollmentCount = rand(80, 120);
            $randomStudents = $students->random(min($enrollmentCount, $students->count()));

            foreach ($randomStudents as $student) {
                $course->students()->attach($student->id, [
                    'enrolled_at' => fake()->dateTimeBetween('-6 months', 'now'),
                ]);
            }
        }

        // Create Quizzes (5 per course = 250 total)
        $this->command->info('Membuat 250 quizzes');
        $quizzes = collect();
        foreach ($courses as $course) {
            $courseQuizzes = Quiz::factory()->count(5)->create([
                'course_id' => $course->id,
            ]);
            $quizzes = $quizzes->merge($courseQuizzes);
        }

        // Create Questions (20 per quiz = 5,000 total)
        $this->command->info('Membuat 5.000 pertanyaan');
        foreach ($quizzes as $quiz) {
            Question::factory()->count(20)->create([
                'quiz_id' => $quiz->id,
            ]);
        }

        // Create Materials (10 per course = 500 total)
        $this->command->info('Membuat 500 materi');
        foreach ($courses as $course) {
            Material::factory()->count(10)->create([
                'course_id' => $course->id,
            ]);
        }

        // Create Assignments (5 per course = 250 total)
        $this->command->info('Membuat 250 assignments');
        $assignments = collect();
        foreach ($courses as $course) {
            $courseAssignments = Assignment::factory()->count(5)->create([
                'course_id' => $course->id,
            ]);
            $assignments = $assignments->merge($courseAssignments);
        }

        // Create Quiz Attempts (~100 attempts per quiz, but distributed = 25,000 total)
        $this->command->info('Membuat 25.000 quiz attempts');
        $quizAttempts = collect();
        foreach ($quizzes as $quiz) {
            $enrolledStudents = $quiz->course->students;

            if ($enrolledStudents->count() > 0) {
                foreach ($enrolledStudents->random(min(100, $enrolledStudents->count())) as $student) {
                    $attempt = QuizAttempt::factory()->create([
                        'quiz_id' => $quiz->id,
                        'user_id' => $student->id,
                    ]);
                    $quizAttempts->push($attempt);
                }
            }
        }

        // Create Submissions (~50 per assignment = 12,500 total)
        $this->command->info('Membuat 12.500 submissions');
        $submissions = collect();
        foreach ($assignments as $assignment) {
            $enrolledStudents = $assignment->course->students;

            if ($enrolledStudents->count() > 0) {
                foreach ($enrolledStudents->random(min(50, $enrolledStudents->count())) as $student) {
                    $submission = Submission::factory()->create([
                        'assignment_id' => $assignment->id,
                        'user_id' => $student->id,
                    ]);
                    $submissions->push($submission);
                }
            }
        }

        // Create Grades (for quiz attempts and submissions)
        $this->command->info('Membuat grades untuk quiz attempts...');
        foreach ($quizAttempts as $attempt) {
            if ($attempt->completed_at && $attempt->score !== null) {
                Grade::create([
                    'user_id' => $attempt->user_id,
                    'course_id' => $attempt->quiz->course_id,
                    'gradeable_type' => QuizAttempt::class,
                    'gradeable_id' => $attempt->id,
                    'score' => $attempt->score,
                    'max_score' => 100,
                    'percentage' => $attempt->score,
                ]);
            }
        }

        $this->command->info('Membuat grades untuk submissions...');
        foreach ($submissions as $submission) {
            if ($submission->graded_at && $submission->score !== null) {
                Grade::create([
                    'user_id' => $submission->user_id,
                    'course_id' => $submission->assignment->course_id,
                    'gradeable_type' => Submission::class,
                    'gradeable_id' => $submission->id,
                    'score' => $submission->score,
                    'max_score' => $submission->assignment->max_score,
                    'percentage' => ($submission->score / $submission->assignment->max_score) * 100,
                ]);
            }
        }

        $this->command->info('Database seeding selesai dengan sukses!');
        $this->command->info('Ringkasan:');
        $this->command->info('- Users: ' . User::count());
        $this->command->info('- Courses: ' . Course::count());
        $this->command->info('- Enrollments: ' . \DB::table('course_enrollments')->count());
        $this->command->info('- Quizzes: ' . Quiz::count());
        $this->command->info('- Questions: ' . Question::count());
        $this->command->info('- Materials: ' . Material::count());
        $this->command->info('- Assignments: ' . Assignment::count());
        $this->command->info('- Quiz Attempts: ' . QuizAttempt::count());
        $this->command->info('- Submissions: ' . Submission::count());
        $this->command->info('- Grades: ' . Grade::count());
    }
}
