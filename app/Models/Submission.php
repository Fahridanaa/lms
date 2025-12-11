<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'user_id',
        'file_path',
        'score',
        'feedback',
        'submitted_at',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    /**
     * Assignment this submission belongs to
     */
    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * User who submitted
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Grade for this submission (polymorphic)
     */
    public function grade()
    {
        return $this->morphOne(Grade::class, 'gradeable');
    }
}
