<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttemptStep extends Model
{
    /** @use HasFactory<\Database\Factories\QuizAttemptStepFactory> */
    use HasFactory;

    protected $fillable = [
        'quiz_attempt_question_id',
        'sequence_number',
        'state',
        'score',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }

    /**
     * Attempt question this step belongs to.
     *
     * @return BelongsTo<QuizAttemptQuestion, $this>
     */
    public function quizAttemptQuestion(): BelongsTo
    {
        return $this->belongsTo(QuizAttemptQuestion::class);
    }

    /**
     * User who performed this step.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Step data rows.
     *
     * @return HasMany<QuizAttemptStepData, $this>
     */
    public function stepData(): HasMany
    {
        return $this->hasMany(QuizAttemptStepData::class);
    }
}
