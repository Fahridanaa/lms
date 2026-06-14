<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentMark extends Model
{
    /** @use HasFactory<\Database\Factories\AssignmentMarkFactory> */
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'submission_id',
        'marker_id',
        'score',
        'feedback',
        'workflow_state',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marker_id');
    }
}
