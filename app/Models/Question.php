<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'question_text',
        'options',
        'correct_answer',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    /**
     * Quiz this question belongs to
     *
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
