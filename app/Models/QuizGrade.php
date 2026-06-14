<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizGrade extends Model
{
    /** @use HasFactory<\Database\Factories\QuizGradeFactory> */
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'user_id',
        'grade',
        'max_score',
        'percentage',
        'grading_method',
        'attempt_count',
        'last_attempt_id',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'grade' => 'decimal:2',
            'max_score' => 'decimal:2',
            'percentage' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    /**
     * Quiz this grade belongs to.
     *
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * User who owns this grade.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Latest attempt that contributed to this grade.
     *
     * @return BelongsTo<QuizAttempt, $this>
     */
    public function lastAttempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'last_attempt_id');
    }
}
