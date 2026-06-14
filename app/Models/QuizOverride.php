<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'user_id',
        'course_group_id',
        'available_from',
        'available_until',
        'time_limit',
        'max_attempts',
        'grace_period',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'available_from' => 'datetime',
            'available_until' => 'datetime',
        ];
    }

    /**
     * The quiz this override belongs to.
     *
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
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
