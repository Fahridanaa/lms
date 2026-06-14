<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeCategoryHistory extends Model
{
    /** @use HasFactory<\Database\Factories\GradeCategoryHistoryFactory> */
    use HasFactory;

    protected $fillable = [
        'grade_category_id',
        'action',
        'old_name',
        'new_name',
        'old_weight',
        'new_weight',
        'old_hidden',
        'new_hidden',
        'old_aggregation_method',
        'new_aggregation_method',
        'old_parent_id',
        'new_parent_id',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'old_weight' => 'decimal:4',
            'new_weight' => 'decimal:4',
            'old_hidden' => 'boolean',
            'new_hidden' => 'boolean',
        ];
    }

    /**
     * Grade category this history entry belongs to.
     *
     * @return BelongsTo<GradeCategory, $this>
     */
    public function gradeCategory(): BelongsTo
    {
        return $this->belongsTo(GradeCategory::class);
    }

    /**
     * User who made the change.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
