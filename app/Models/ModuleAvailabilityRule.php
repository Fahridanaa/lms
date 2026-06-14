<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleAvailabilityRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'learning_module_id',
        'rule_type',
        'required_module_id',
        'grade_item_id',
        'course_group_id',
        'course_grouping_id',
        'condition_group',
        'operator',
        'value',
    ];

    /**
     * The learning module that this rule applies to.
     *
     * @return BelongsTo<LearningModule, $this>
     */
    public function learningModule(): BelongsTo
    {
        return $this->belongsTo(LearningModule::class);
    }

    /**
     * The course grouping that this rule references.
     *
     * @return BelongsTo<CourseGrouping, $this>
     */
    public function courseGrouping(): BelongsTo
    {
        return $this->belongsTo(CourseGrouping::class);
    }
}
