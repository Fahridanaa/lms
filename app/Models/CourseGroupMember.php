<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseGroupMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_group_id',
        'user_id',
    ];

    /**
     * The group this membership belongs to.
     *
     * @return BelongsTo<CourseGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CourseGroup::class, 'course_group_id');
    }

    /**
     * The user in this group.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
