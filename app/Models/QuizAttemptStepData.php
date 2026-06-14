<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttemptStepData extends Model
{
    /** @use HasFactory<\Database\Factories\QuizAttemptStepDataFactory> */
    use HasFactory;

    protected $fillable = [
        'quiz_attempt_step_id',
        'name',
        'value',
    ];

    /**
     * Step this data row belongs to.
     *
     * @return BelongsTo<QuizAttemptStep, $this>
     */
    public function quizAttemptStep(): BelongsTo
    {
        return $this->belongsTo(QuizAttemptStep::class);
    }
}
