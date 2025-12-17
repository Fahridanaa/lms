<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quiz extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'time_limit',
        'passing_score',
        'is_active',
    ];

    /**
     * Course this quiz belongs to
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Questions in this quiz
     */
    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Attempts for this quiz
     */
    public function attempts()
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Grades for this quiz (polymorphic)
     */
    public function grades()
    {
        return $this->morphMany(Grade::class, 'gradeable');
    }
}
