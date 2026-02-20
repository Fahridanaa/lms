<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ["course_id", "title", "description", "due_date", "max_score", "is_active"];

    protected function casts(): array
    {
        return [
            "due_date" => "datetime",
        ];
    }

    /**
     * Course this assignment belongs to
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Submissions for this assignment
     *
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * Grades for this assignment (polymorphic)
     *
     * @return MorphMany<Grade, $this>
     */
    public function grades(): MorphMany
    {
        return $this->morphMany(Grade::class, "gradeable");
    }
}
