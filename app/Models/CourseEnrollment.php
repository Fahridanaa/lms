<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'enrolled_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
        ];
    }

    /**
     * User who enrolled
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Course enrolled in
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
