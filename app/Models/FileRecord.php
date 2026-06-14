<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FileRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'uploader_id',
        'component',
        'file_path',
        'mime_type',
        'file_size',
        'checksum',
        'revision',
        'visible',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'revision' => 'integer',
            'visible' => 'boolean',
        ];
    }

    /**
     * The user who uploaded this file.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    /**
     * The owning model (material, submission, etc.).
     *
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
