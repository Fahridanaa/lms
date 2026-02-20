<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizAttempt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'quiz_id',
        'user_id',
        'answers',
        'score',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Quiz this attempt belongs to
     *
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * User who made this attempt
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Grade for this attempt (polymorphic)
     *
     * @return MorphOne<Grade, $this>
     */
    public function grade(): MorphOne
    {
        return $this->morphOne(Grade::class, 'gradeable');
    }
}
