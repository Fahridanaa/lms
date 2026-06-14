<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Context extends Model
{
    use HasFactory;

    public const LEVEL_SYSTEM = 10;

    public const LEVEL_COURSE = 50;

    public const LEVEL_MODULE = 70;

    protected $fillable = [
        'contextlevel',
        'instance_id',
        'path',
        'depth',
    ];

    /**
     * Role assignments at this context.
     *
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * Get ancestor contexts ordered from root to parent.
     *
     * @return Collection<int, Context>
     */
    public function ancestors(): Collection
    {
        if ($this->path === '/1') {
            return collect();
        }

        $segments = array_filter(explode('/', $this->path));

        $ancestorPaths = [];
        $current = '';
        $idSegments = $segments;
        array_pop($idSegments);

        foreach ($idSegments as $seg) {
            $current .= '/'.$seg;
            $ancestorPaths[] = $current;
        }

        return static::query()
            ->whereIn('path', $ancestorPaths)
            ->orderBy('depth')
            ->get();
    }
}
