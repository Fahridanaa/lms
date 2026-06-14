<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Grade extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'course_id',
        'grade_item_id',
        'grader_id',
        'gradeable_type',
        'gradeable_id',
        'score',
        'max_score',
        'percentage',
        'feedback',
        'status',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'percentage' => 'decimal:2',
        ];
    }

    /**
     * User who received this grade
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Course this grade is for
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Instructor or grader who assigned this grade.
     *
     * @return BelongsTo<User, $this>
     */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grader_id');
    }

    /**
     * Gradeable item (polymorphic - Quiz, QuizAttempt, Assignment, or Submission)
     *
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function gradeable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Grade item that this grade belongs to.
     *
     * @return BelongsTo<GradeItem, $this>
     */
    public function gradeItem(): BelongsTo
    {
        return $this->belongsTo(GradeItem::class);
    }

    /**
     * History entries for this grade.
     *
     * @return HasMany<GradeHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(GradeHistory::class);
    }
}
