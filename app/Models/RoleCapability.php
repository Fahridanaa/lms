<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleCapability extends Model
{
    use HasFactory;

    protected $fillable = [
        'role_id',
        'capability_id',
    ];

    /**
     * The role that has this capability.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The capability assigned to the role.
     *
     * @return BelongsTo<Capability, $this>
     */
    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }
}
