<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseGroupingGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_grouping_id',
        'course_group_id',
    ];

    /**
     * The grouping this relationship belongs to.
     *
     * @return BelongsTo<CourseGrouping, $this>
     */
    public function grouping(): BelongsTo
    {
        return $this->belongsTo(CourseGrouping::class, 'course_grouping_id');
    }

    /**
     * The group in this relationship.
     *
     * @return BelongsTo<CourseGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CourseGroup::class, 'course_group_id');
    }
}
