<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseCategory extends Model
{
    /** @use HasFactory<\Database\Factories\CourseCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'description',
        'sort_order',
        'visible',
        'depth',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'depth' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Parent category.
     *
     * @return BelongsTo<CourseCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'parent_id');
    }

    /**
     * Child categories.
     *
     * @return HasMany<CourseCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(CourseCategory::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * Courses in this category.
     *
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'course_category_id');
    }
}
