<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Grade extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'course_id',
        'gradeable_type',
        'gradeable_id',
        'score',
        'max_score',
        'percentage',
    ];

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
     * Gradeable item (polymorphic - Quiz, QuizAttempt, Assignment, or Submission)
     *
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function gradeable(): MorphTo
    {
        return $this->morphTo();
    }
}
