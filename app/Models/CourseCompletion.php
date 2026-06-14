<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseCompletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'user_id',
        'timeenrolled',
        'timestarted',
        'timecompleted',
        'reaggregate',
    ];

    protected function casts(): array
    {
        return [
            'timeenrolled' => 'datetime',
            'timestarted' => 'datetime',
            'timecompleted' => 'datetime',
            'reaggregate' => 'boolean',
        ];
    }

    /**
     * The course being completed.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The user who completed the course.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
