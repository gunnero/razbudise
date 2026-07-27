<?php

$activityEvents = [
    'article.submitted' => ['label' => 'поднесе објава за преглед', 'icon' => 'heroicon-o-paper-airplane'],
    'article.review_started' => ['label' => 'започна уреднички преглед', 'icon' => 'heroicon-o-eye'],
    'article.changes_requested' => ['label' => 'побара измени', 'icon' => 'heroicon-o-arrow-uturn-left'],
    'article.approved' => ['label' => 'одобри објава', 'icon' => 'heroicon-o-check-circle'],
    'article.scheduled' => ['label' => 'закажа објава', 'icon' => 'heroicon-o-calendar'],
    'article.published' => ['label' => 'објави текст', 'icon' => 'heroicon-o-globe-alt'],
    'article.archived' => ['label' => 'архивираше објава', 'icon' => 'heroicon-o-archive-box'],
    'article.publication_failed' => ['label' => 'евидентираше неуспешно објавување', 'icon' => 'heroicon-o-exclamation-triangle'],
    'article.editor_assigned' => ['label' => 'додели одговорен уредник', 'icon' => 'heroicon-o-user-plus'],
    'article.comment_added' => ['label' => 'додаде уреднички коментар', 'icon' => 'heroicon-o-chat-bubble-left-ellipsis'],
    'article.comment_replied' => ['label' => 'одговори на уреднички коментар', 'icon' => 'heroicon-o-arrow-uturn-left'],
    'article.comment_resolved' => ['label' => 'реши уреднички коментар', 'icon' => 'heroicon-o-check-circle'],
    'article.comment_reopened' => ['label' => 'повторно отвори уреднички коментар', 'icon' => 'heroicon-o-arrow-path'],
    'author.created' => ['label' => 'создаде авторски профил', 'icon' => 'heroicon-o-user-plus'],
    'author.profile_updated' => ['label' => 'ажурираше авторски профил', 'icon' => 'heroicon-o-identification'],
    'author.self_profile_updated' => ['label' => 'го ажурираше својот авторски профил', 'icon' => 'heroicon-o-identification'],
    'editorial_supervision.created' => ['label' => 'додели уреднички надзор', 'icon' => 'heroicon-o-user-group'],
    'editorial_supervision.updated' => ['label' => 'ажурираше уреднички надзор', 'icon' => 'heroicon-o-user-group'],
    'user.created' => ['label' => 'создаде корисничка сметка', 'icon' => 'heroicon-o-user-plus'],
    'user.updated' => ['label' => 'ажурираше корисничка сметка', 'icon' => 'heroicon-o-user'],
    'user.disabled' => ['label' => 'оневозможи корисничка сметка', 'icon' => 'heroicon-o-no-symbol'],
    'user.reactivated' => ['label' => 'активираше корисничка сметка', 'icon' => 'heroicon-o-check-badge'],
];

return [
    /*
    |--------------------------------------------------------------------------
    | Editorial clock and service levels
    |--------------------------------------------------------------------------
    |
    | Article timestamps are stored in UTC. Newsroom day boundaries and human
    | waiting-time calculations are deliberately evaluated in Skopje time.
    |
    */
    'timezone' => env('NEWSROOM_TIMEZONE', 'Europe/Skopje'),
    'approval_sla_hours' => (int) env('NEWSROOM_APPROVAL_SLA_HOURS', 24),
    'scheduled_soon_hours' => (int) env('NEWSROOM_SCHEDULED_SOON_HOURS', 6),

    'approval_statuses' => ['submitted', 'review', 'in_review'],
    'review_statuses' => ['review', 'in_review'],
    'source_required_types' => ['guide'],
    'source_required_presentations' => ['event_guide'],

    'activity_actions' => array_keys($activityEvents),
    'activity_events' => $activityEvents,
];
