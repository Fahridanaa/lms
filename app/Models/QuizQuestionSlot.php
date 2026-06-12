<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestionSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'question_id',
        'slot',
        'page',
        'max_points',
        'require_previous',
    ];

    protected function casts(): array
    {
        return [
            'max_points' => 'decimal:2',
            'require_previous' => 'boolean',
        ];
    }

    /**
     * Quiz that owns the slot.
     *
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Question placed in the quiz slot.
     *
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
