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
        'course_category_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

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
            ->withPivot('enrolled_at', 'role', 'status', 'starts_at', 'ends_at');
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
     * Sections in this course.
     *
     * @return HasMany<CourseSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('sort_order');
    }

    /**
     * Moodle-like activity wrappers in this course.
     *
     * @return HasMany<LearningModule, $this>
     */
    public function learningModules(): HasMany
    {
        return $this->hasMany(LearningModule::class);
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

    /**
     * Groups in this course.
     *
     * @return HasMany<CourseGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(CourseGroup::class);
    }

    /**
     * Grade items in this course.
     *
     * @return HasMany<GradeItem, $this>
     */
    public function gradeItems(): HasMany
    {
        return $this->hasMany(GradeItem::class);
    }

    /**
     * The category this course belongs to.
     *
     * @return BelongsTo<CourseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'course_category_id');
    }

    /**
     * Groupings in this course.
     *
     * @return HasMany<CourseGrouping, $this>
     */
    public function groupings(): HasMany
    {
        return $this->hasMany(CourseGrouping::class);
    }
}
