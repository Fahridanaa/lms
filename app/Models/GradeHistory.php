<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeHistory extends Model
{
    /** @use HasFactory<\Database\Factories\GradeHistoryFactory> */
    use HasFactory;

    protected $fillable = [
        'grade_id',
        'action',
        'old_score',
        'new_score',
        'old_percentage',
        'new_percentage',
        'old_status',
        'new_status',
        'old_feedback',
        'new_feedback',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'old_score' => 'decimal:2',
            'new_score' => 'decimal:2',
            'old_percentage' => 'decimal:2',
            'new_percentage' => 'decimal:2',
        ];
    }

    /**
     * Grade this history entry belongs to.
     *
     * @return BelongsTo<Grade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
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
