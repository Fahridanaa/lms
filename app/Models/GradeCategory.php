<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeCategory extends Model
{
    /** @use HasFactory<\Database\Factories\GradeCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'parent_id',
        'name',
        'depth',
        'path',
        'aggregation_method',
        'weight',
        'hidden',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'weight' => 'decimal:4',
            'hidden' => 'boolean',
        ];
    }

    /**
     * Course this category belongs to.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Parent category.
     *
     * @return BelongsTo<GradeCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(GradeCategory::class, 'parent_id');
    }

    /**
     * Child categories.
     *
     * @return HasMany<GradeCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(GradeCategory::class, 'parent_id');
    }

    /**
     * Grade items in this category.
     *
     * @return HasMany<GradeItem, $this>
     */
    public function gradeItems(): HasMany
    {
        return $this->hasMany(GradeItem::class);
    }

    /**
     * History entries for this category.
     *
     * @return HasMany<GradeCategoryHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(GradeCategoryHistory::class);
    }
}
