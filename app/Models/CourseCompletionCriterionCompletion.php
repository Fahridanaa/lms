<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseCompletionCriterionCompletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_completion_criterion_id',
        'user_id',
        'completed',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The criterion this completion belongs to.
     *
     * @return BelongsTo<CourseCompletionCriterion, $this>
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(CourseCompletionCriterion::class, 'course_completion_criterion_id');
    }

    /**
     * The user who completed this criterion.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
