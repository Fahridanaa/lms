<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'grade_category_id',
        'item_type',
        'item_id',
        'name',
        'max_score',
        'pass_score',
        'weight',
        'hidden',
        'locked',
        'source',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
            'pass_score' => 'decimal:2',
            'weight' => 'decimal:4',
            'hidden' => 'boolean',
            'locked' => 'boolean',
        ];
    }

    /**
     * Course that this grade item belongs to.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Grade category this item belongs to.
     *
     * @return BelongsTo<GradeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(GradeCategory::class, 'grade_category_id');
    }

    /**
     * Grades for this grade item.
     *
     * @return HasMany<Grade, $this>
     */
    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    /**
     * History entries for this grade item.
     *
     * @return HasMany<GradeItemHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(GradeItemHistory::class);
    }
}
