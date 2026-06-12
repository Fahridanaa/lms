<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quiz extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'time_limit',
        'passing_score',
        'is_active',
        'available_from',
        'available_until',
        'max_attempts',
        'grading_method',
        'shuffle_questions',
        'shuffle_answers',
    ];

    protected function casts(): array
    {
        return [
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'is_active' => 'boolean',
            'shuffle_questions' => 'boolean',
            'shuffle_answers' => 'boolean',
        ];
    }

    /**
     * Course this quiz belongs to
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Questions in this quiz
     *
     * @return HasMany<Question, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Ordered question slots for this quiz.
     *
     * @return HasMany<QuizQuestionSlot, $this>
     */
    public function questionSlots(): HasMany
    {
        return $this->hasMany(QuizQuestionSlot::class)->orderBy('slot');
    }

    /**
     * Moodle-like course module wrapper.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<LearningModule, $this>
     */
    public function learningModule(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LearningModule::class, 'module_id')
            ->where('module_type', LearningModule::TYPE_QUIZ);
    }

    /**
     * Attempts for this quiz
     *
     * @return HasMany<QuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Grades for this quiz (polymorphic)
     *
     * @return MorphMany<Grade, $this>
     */
    public function grades(): MorphMany
    {
        return $this->morphMany(Grade::class, 'gradeable');
    }

    public function isOpen(): bool
    {
        $now = now();

        return $this->is_active
            && ($this->available_from === null || $this->available_from->lte($now))
            && ($this->available_until === null || $this->available_until->gte($now));
    }
}
