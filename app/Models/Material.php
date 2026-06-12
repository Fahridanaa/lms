<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Material extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'title',
        'file_path',
        'file_size',
        'type',
        'mime_type',
        'revision',
        'checksum',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Course this material belongs to
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Moodle-like course module wrapper.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<LearningModule, $this>
     */
    public function learningModule(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LearningModule::class, 'module_id')
            ->where('module_type', LearningModule::TYPE_MATERIAL);
    }
}
