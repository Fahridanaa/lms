<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'instructor_id',
    ];

    /**
     * Instructor who teaches this course
     */
    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * Students enrolled in this course
     */
    public function students()
    {
        return $this->belongsToMany(User::class, 'course_enrollments')
            ->withTimestamps()
            ->withPivot('enrolled_at');
    }

    /**
     * Enrollments for this course
     */
    public function enrollments()
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    /**
     * Quizzes in this course
     */
    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }

    /**
     * Materials in this course
     */
    public function materials()
    {
        return $this->hasMany(Material::class);
    }

    /**
     * Assignments in this course
     */
    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * Grades for this course
     */
    public function grades()
    {
        return $this->hasMany(Grade::class);
    }
}
