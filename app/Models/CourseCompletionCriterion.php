<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseCompletionCriterion extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'criteriatype',
        'module_instance_id',
        'grade_item_id',
        'pass_threshold',
        'time_end',
    ];

    protected function casts(): array
    {
        return [
            'pass_threshold' => 'decimal:2',
            'time_end' => 'datetime',
        ];
    }

    /**
     * The course this criterion belongs to.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The learning module this criterion references (for module-type criteria).
     *
     * @return BelongsTo<LearningModule, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(LearningModule::class, 'module_instance_id');
    }

    /**
     * The grade item this criterion references (for grade-type criteria).
     *
     * @return BelongsTo<GradeItem, $this>
     */
    public function gradeItem(): BelongsTo
    {
        return $this->belongsTo(GradeItem::class, 'grade_item_id');
    }

    /**
     * Per-user completions for this criterion.
     *
     * @return HasMany<CourseCompletionCriterionCompletion, $this>
     */
    public function criterionCompletions(): HasMany
    {
        return $this->hasMany(CourseCompletionCriterionCompletion::class, 'course_completion_criterion_id');
    }
}
