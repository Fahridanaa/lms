<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Submission extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'assignment_id',
        'user_id',
        'file_path',
        'score',
        'feedback',
        'grader_id',
        'status',
        'attempt_number',
        'is_latest',
        'submitted_at',
        'graded_at',
        'returned_at',
        'reopened_at',
        'late',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'returned_at' => 'datetime',
            'reopened_at' => 'datetime',
            'is_latest' => 'boolean',
            'late' => 'boolean',
        ];
    }

    /**
     * Assignment this submission belongs to
     *
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * User who submitted
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * User who graded this submission.
     *
     * @return BelongsTo<User, $this>
     */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grader_id');
    }

    /**
     * Grade for this submission (polymorphic)
     *
     * @return MorphOne<Grade, $this>
     */
    /**
     * Allocated markers for this submission.
     *
     * @return HasMany<AssignmentAllocatedMarker, $this>
     */
    public function allocatedMarkers(): HasMany
    {
        return $this->hasMany(AssignmentAllocatedMarker::class);
    }

    /**
     * Marker marks for this submission.
     *
     * @return HasMany<AssignmentMark, $this>
     */
    public function assignmentMarks(): HasMany
    {
        return $this->hasMany(AssignmentMark::class);
    }

    public function grade(): MorphOne
    {
        return $this->morphOne(Grade::class, 'gradeable');
    }
}
