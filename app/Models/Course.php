<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'instructor_id',
        'is_active',
    ];

    /**
     * Instructor who teaches this course
     *
     * @return BelongsTo<User, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * Students enrolled in this course
     *
     * @return BelongsToMany<User, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_enrollments')
            ->withTimestamps()
            ->withPivot('enrolled_at');
    }

    /**
     * Enrollments for this course
     *
     * @return HasMany<CourseEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    /**
     * Quizzes in this course
     *
     * @return HasMany<Quiz, $this>
     */
    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    /**
     * Materials in this course
     *
     * @return HasMany<Material, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    /**
     * Assignments in this course
     *
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * Grades for this course
     *
     * @return HasMany<Grade, $this>
     */
    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}
