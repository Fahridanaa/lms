<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'file_path',
        'file_size',
        'type',
    ];

    /**
     * Course this material belongs to
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
