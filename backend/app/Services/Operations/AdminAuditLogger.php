<?php

namespace App\Services\Operations;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AdminAuditLogger
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        Model $auditable,
        ?User $actor = null,
        array $before = [],
        array $after = [],
        array $metadata = [],
    ): AdminAuditLog {
        $actor ??= auth()->user();

        return AdminAuditLog::query()->create([
            'actor_id' => $actor?->id,
            'auditable_type' => $auditable::class,
            'auditable_id' => $auditable->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
        ]);
    }
}
