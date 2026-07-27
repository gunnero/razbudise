<?php

namespace App\Observers;

use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Services\Newsroom\NewsroomNotificationService;

class NewsroomAuditNotificationObserver
{
    public function created(AdminAuditLog $auditLog): void
    {
        if ($auditLog->auditable_type !== Article::class) {
            return;
        }

        /** @var Article|null $article */
        $article = $auditLog->auditable()->first();

        if (! $article) {
            return;
        }

        app(NewsroomNotificationService::class)->articleAuditEvent(
            $article,
            $auditLog->action,
            $auditLog->actor()->first(),
        );
    }
}
