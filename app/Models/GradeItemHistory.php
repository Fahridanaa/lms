<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeItemHistory extends Model
{
    /** @use HasFactory<\Database\Factories\GradeItemHistoryFactory> */
    use HasFactory;

    protected $fillable = [
        'grade_item_id',
        'action',
        'old_name',
        'new_name',
        'old_max_score',
        'new_max_score',
        'old_pass_score',
        'new_pass_score',
        'old_weight',
        'new_weight',
        'old_hidden',
        'new_hidden',
        'old_locked',
        'new_locked',
        'old_category_id',
        'new_category_id',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'old_max_score' => 'decimal:2',
            'new_max_score' => 'decimal:2',
            'old_pass_score' => 'decimal:2',
            'new_pass_score' => 'decimal:2',
            'old_weight' => 'decimal:4',
            'new_weight' => 'decimal:4',
            'old_hidden' => 'boolean',
            'new_hidden' => 'boolean',
            'old_locked' => 'boolean',
            'new_locked' => 'boolean',
        ];
    }

    /**
     * Grade item this history entry belongs to.
     *
     * @return BelongsTo<GradeItem, $this>
     */
    public function gradeItem(): BelongsTo
    {
        return $this->belongsTo(GradeItem::class);
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
