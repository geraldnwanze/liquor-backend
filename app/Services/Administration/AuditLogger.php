<?php

namespace App\Services\Administration;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /** @param array<string, mixed> $metadata Never include secrets or payment credentials. */
    public function record(string $action, ?Model $subject = null, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
