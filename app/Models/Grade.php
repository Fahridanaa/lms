<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Course this grade is for
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Gradeable item (polymorphic - Quiz, QuizAttempt, Assignment, or Submission)
     */
    public function gradeable()
    {
        return $this->morphTo();
    }
}
