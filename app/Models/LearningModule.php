<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class LearningModule extends Model
{
    use HasFactory;

    public const TYPE_ASSIGNMENT = 'assignment';

    public const TYPE_MATERIAL = 'material';

    public const TYPE_QUIZ = 'quiz';

    protected $fillable = [
        'course_id',
        'module_type',
        'module_id',
        'visible',
        'available_from',
        'available_until',
        'sort_order',
        'completion_enabled',
        'completion_rule',
    ];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'completion_enabled' => 'boolean',
        ];
    }

    /**
     * Course that owns this module.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isAvailable(): bool
    {
        $now = now();

        if (! (bool) $this->getAttribute('visible')) {
            return false;
        }

        $availableFrom = $this->getAttribute('available_from');
        $availableUntil = $this->getAttribute('available_until');

        if ($availableFrom && Carbon::parse($availableFrom)->gt($now)) {
            return false;
        }

        if ($availableUntil && Carbon::parse($availableUntil)->lt($now)) {
            return false;
        }

        return true;
    }
}
