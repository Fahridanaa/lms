<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseGrouping extends Model
{
    /** @use HasFactory<\Database\Factories\CourseGroupingFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'name',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The course this grouping belongs to.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Groups in this grouping.
     *
     * @return BelongsToMany<CourseGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CourseGroup::class, 'course_grouping_groups');
    }

    /**
     * Pivot rows for groups in this grouping.
     *
     * @return HasMany<CourseGroupingGroup, $this>
     */
    public function groupingGroups(): HasMany
    {
        return $this->hasMany(CourseGroupingGroup::class, 'course_grouping_id');
    }
}
