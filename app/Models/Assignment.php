<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'due_date',
        'max_score',
        'is_active',
        'available_from',
        'cutoff_date',
        'max_attempts',
        'allow_late_submission',
        'submission_type',
        'marking_allocation_enabled',
        'marker_count',
        'multi_mark_method',
        'anonymous_marking_enabled',
        'hide_grader_from_student',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'datetime',
            'available_from' => 'datetime',
            'cutoff_date' => 'datetime',
            'is_active' => 'boolean',
            'allow_late_submission' => 'boolean',
        ];
    }

    /**
     * Course this assignment belongs to
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Submissions for this assignment
     *
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * Grades for this assignment (polymorphic)
     *
     * @return MorphMany<Grade, $this>
     */
    public function grades(): MorphMany
    {
        return $this->morphMany(Grade::class, 'gradeable');
    }

    /**
     * Moodle-like course module wrapper.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<LearningModule, $this>
     */
    public function learningModule(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LearningModule::class, 'module_id')
            ->where('module_type', LearningModule::TYPE_ASSIGNMENT);
    }

    /**
     * Assignment overrides for this assignment.
     *
     * @return HasMany<AssignmentOverride, $this>
     */
    /**
     * Allocated markers for this assignment.
     *
     * @return HasMany<AssignmentAllocatedMarker, $this>
     */
    public function allocatedMarkers(): HasMany
    {
        return $this->hasMany(AssignmentAllocatedMarker::class);
    }

    /**
     * Marker marks for this assignment.
     *
     * @return HasMany<AssignmentMark, $this>
     */
    public function assignmentMarks(): HasMany
    {
        return $this->hasMany(AssignmentMark::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(AssignmentOverride::class);
    }

    public function isOpenForSubmission(): bool
    {
        $now = now();

        return $this->is_active
            && ($this->available_from === null || $this->available_from->lte($now))
            && ($this->cutoff_date === null || $this->cutoff_date->gte($now));
    }
}
