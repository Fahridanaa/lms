<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradebookRecalculation extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'reason',
        'source_type',
        'source_id',
        'marked_at',
        'recalculated_at',
    ];

    protected $casts = [
        'marked_at' => 'datetime',
        'recalculated_at' => 'datetime',
    ];

    /**
     * The course that needs recalculation.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
