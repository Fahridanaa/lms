<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
     */
    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }
}
