<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseGroup extends Model
{
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
        ];
    }

    /**
     * The course that owns this group.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Memberships in this group.
     *
     * @return HasMany<CourseGroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CourseGroupMember::class);
    }

    /**
     * Users in this group.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_group_members');
    }

    /**
     * Groupings that contain this group.
     *
     * @return BelongsToMany<CourseGrouping, $this>
     */
    public function groupings(): BelongsToMany
    {
        return $this->belongsToMany(CourseGrouping::class, 'course_grouping_groups');
    }
}
