<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'due_date',
        'max_score',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'datetime',
        ];
    }

    /**
     * Course this assignment belongs to
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Submissions for this assignment
     */
    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * Grades for this assignment (polymorphic)
     */
    public function grades()
    {
        return $this->morphMany(Grade::class, 'gradeable');
    }
}
