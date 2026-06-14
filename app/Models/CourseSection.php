<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'summary',
        'sort_order',
        'visible',
    ];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
        ];
    }

    /**
     * Course that owns this section.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Learning modules in this section.
     *
     * @return HasMany<LearningModule, $this>
     */
    public function learningModules(): HasMany
    {
        return $this->hasMany(LearningModule::class, 'course_section_id');
    }
}
