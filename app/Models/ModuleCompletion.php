<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleCompletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'learning_module_id',
        'user_id',
        'state',
        'completed_at',
        'source',
        'override_by',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The learning module that was completed.
     *
     * @return BelongsTo<LearningModule, $this>
     */
    public function learningModule(): BelongsTo
    {
        return $this->belongsTo(LearningModule::class);
    }

    /**
     * The user who completed the module.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
