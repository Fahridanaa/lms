<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
     */
    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * User who made this attempt
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Grade for this attempt (polymorphic)
     */
    public function grade()
    {
        return $this->morphOne(Grade::class, 'gradeable');
    }
}
