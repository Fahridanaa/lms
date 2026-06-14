<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'user_id',
        'course_group_id',
        'available_from',
        'due_date',
        'cutoff_date',
        'max_attempts',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'available_from' => 'datetime',
            'due_date' => 'datetime',
            'cutoff_date' => 'datetime',
        ];
    }

    /**
     * The assignment this override belongs to.
     *
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * The user this override applies to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The course group this override applies to.
     *
     * @return BelongsTo<CourseGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CourseGroup::class, 'course_group_id');
    }
}
