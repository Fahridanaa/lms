<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttemptQuestion extends Model
{
    /** @use HasFactory<\Database\Factories\QuizAttemptQuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'quiz_attempt_id',
        'quiz_question_slot_id',
        'question_id',
        'slot',
        'max_points',
        'score',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'max_points' => 'decimal:2',
            'score' => 'decimal:2',
        ];
    }

    /**
     * Quiz attempt this question belongs to.
     *
     * @return BelongsTo<QuizAttempt, $this>
     */
    public function quizAttempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class);
    }

    /**
     * Quiz question slot.
     *
     * @return BelongsTo<QuizQuestionSlot, $this>
     */
    public function quizQuestionSlot(): BelongsTo
    {
        return $this->belongsTo(QuizQuestionSlot::class);
    }

    /**
     * The question for this slot.
     *
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Steps for this attempt question.
     *
     * @return HasMany<QuizAttemptStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(QuizAttemptStep::class);
    }
}
