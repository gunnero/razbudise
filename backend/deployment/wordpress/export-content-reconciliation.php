<?php

/**
 * Read-only WordPress source exporter for the RazbudiSe content reconciliation.
 *
 * Run only through `wp eval-file` with an authenticated editorial administrator:
 *
 * RAZBUDISE_RECONCILIATION_EXPORT_DIR=/private/backend/storage/app/<freeze-dir> \
 *   wp --path=/path/to/wordpress --user=<approved-user> eval-file \
 *   deployment/wordpress/export-content-reconciliation.php
 */
if (! defined('WP_CLI') || ! WP_CLI) {
    fwrite(STDERR, "This exporter must run through WP-CLI.\n");
    exit(1);
}

if (! current_user_can('edit_others_posts') || ! current_user_can('read_private_posts')) {
    WP_CLI::error('The selected WP-CLI user must be allowed to read and edit private editorial posts.');
}

// Elementor lazily persists this derived asset cache while rendering a post.
// The reconciliation exporter must remain read-only, so report the cache write
// as successful without changing WordPress. Editorial source meta remains fully
// fingerprinted before and after the export.
$readOnlyDerivedPostMetaKeys = ['_elementor_page_assets'];
$suppressDerivedPostMetaWrite = static function (
    $check,
    $objectId,
    $metaKey,
    $metaValue,
    $operationArgument,
) use ($readOnlyDerivedPostMetaKeys) {
    if (in_array((string) $metaKey, $readOnlyDerivedPostMetaKeys, true)) {
        return true;
    }

    return $check;
};

foreach (['add_post_metadata', 'update_post_metadata', 'delete_post_metadata'] as $filter) {
    add_filter($filter, $suppressDerivedPostMetaWrite, PHP_INT_MAX, 5);
}

WP_CLI::log('WORDPRESS_RECONCILIATION_DERIVED_CACHE_WRITES_SUPPRESSED=1');

$destinationInput = getenv('RAZBUDISE_RECONCILIATION_EXPORT_DIR');
$destinationInput = is_string($destinationInput) ? $destinationInput : '';
$destination = $destinationInput !== '' ? realpath($destinationInput) : false;
$wordpressRoot = realpath(ABSPATH);
$destinationPermissions = is_string($destination) ? fileperms($destination) : false;
$destinationOwner = is_string($destination) ? fileowner($destination) : false;
$processOwner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

if (! is_string($destination)
    || is_link($destinationInput)
    || ! is_dir($destination)
    || ! is_writable($destination)
    || ! str_starts_with($destination, DIRECTORY_SEPARATOR)
    || ! is_int($destinationPermissions)
    || (($destinationPermissions & 0777) & 0027) !== 0
    || ! is_int($destinationOwner)
    || ! is_int($processOwner)
    || $destinationOwner !== $processOwner
    || (is_string($wordpressRoot) && ($destination === $wordpressRoot || str_starts_with($destination, $wordpressRoot.DIRECTORY_SEPARATOR)))) {
    WP_CLI::error('RAZBUDISE_RECONCILIATION_EXPORT_DIR must be a non-symlink, process-owned private directory (0750 or stricter) outside the WordPress web root.');
}

$fileNames = [
    'published_posts' => 'published-posts.ndjson',
    'nonpublic_posts' => 'nonpublic-posts.ndjson',
    'auto_drafts' => 'auto-drafts.ndjson',
    'categories' => 'categories.ndjson',
    'tags' => 'tags.ndjson',
    'authors' => 'authors.ndjson',
];

foreach ([...array_values($fileNames), 'metadata.json'] as $fileName) {
    if (file_exists($destination.DIRECTORY_SEPARATOR.$fileName)) {
        WP_CLI::error("Refusing to overwrite frozen export file: {$fileName}");
    }
}

$contentMigrationDecisionPath = __DIR__.DIRECTORY_SEPARATOR.'content-migration-decisions.json';
$contentMigrationDecisionJson = file_get_contents($contentMigrationDecisionPath);

if (! is_string($contentMigrationDecisionJson) || strlen($contentMigrationDecisionJson) > 100_000) {
    WP_CLI::error('The versioned content migration decision artifact could not be read safely.');
}

try {
    $contentMigrationDecisions = json_decode(
        $contentMigrationDecisionJson,
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
} catch (Throwable) {
    WP_CLI::error('The versioned content migration decision artifact is not valid JSON.');
}

if (! is_array($contentMigrationDecisions)
    || array_is_list($contentMigrationDecisions)
    || ($contentMigrationDecisions['schema_version'] ?? null) !== 1
    || ! is_array($contentMigrationDecisions['featured_image_fallbacks'] ?? null)
    || array_is_list($contentMigrationDecisions['featured_image_fallbacks'])
    || ! is_array($contentMigrationDecisions['blob_image_removals'] ?? null)
    || array_is_list($contentMigrationDecisions['blob_image_removals'])) {
    WP_CLI::error('The versioned content migration decision artifact has an invalid schema.');
}

$featuredImageFallbacks = [];

foreach ($contentMigrationDecisions['featured_image_fallbacks'] as $postId => $attachmentId) {
    if (! ctype_digit((string) $postId)
        || (int) $postId < 1
        || ! is_int($attachmentId)
        || $attachmentId < 1) {
        WP_CLI::error('A featured-image fallback decision contains an invalid post or attachment ID.');
    }

    $featuredImageFallbacks[(int) $postId] = $attachmentId;
}

ksort($featuredImageFallbacks, SORT_NUMERIC);
$blobImageRemovals = [];

foreach ($contentMigrationDecisions['blob_image_removals'] as $postId => $decision) {
    if (! ctype_digit((string) $postId)
        || (int) $postId < 1
        || ! is_array($decision)
        || array_is_list($decision)
        || preg_match('/^[a-f0-9]{64}$/', (string) ($decision['raw_post_content_sha256'] ?? '')) !== 1
        || ! is_int($decision['expected_blob_image_count'] ?? null)
        || $decision['expected_blob_image_count'] < 1) {
        WP_CLI::error('A blob-image removal decision contains invalid source evidence.');
    }

    $blobImageRemovals[(int) $postId] = [
        'raw_post_content_sha256' => $decision['raw_post_content_sha256'],
        'expected_blob_image_count' => $decision['expected_blob_image_count'],
    ];
}

ksort($blobImageRemovals, SORT_NUMERIC);
$contentMigrationDecisionSha256 = hash('sha256', $contentMigrationDecisionJson);

$sourceBaseUrl = rtrim((string) home_url('/'), '/');
$sourceBaseParts = parse_url($sourceBaseUrl);

if (! is_array($sourceBaseParts)
    || strtolower((string) ($sourceBaseParts['scheme'] ?? '')) !== 'https'
    || ! is_string($sourceBaseParts['host'] ?? null)
    || isset($sourceBaseParts['user'])
    || isset($sourceBaseParts['pass'])
    || isset($sourceBaseParts['port'])
    || isset($sourceBaseParts['query'])
    || isset($sourceBaseParts['fragment'])) {
    WP_CLI::error('The canonical WordPress source URL must be credential-free, port-free HTTPS.');
}

global $wpdb;

$persistedBlobImageCount = static function (string $html): int {
    if (stripos($html, 'blob:') === false || stripos($html, '<img') === false) {
        return 0;
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previousErrorMode = libxml_use_internal_errors(true);

    try {
        if (! $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        )) {
            return 0;
        }

        $count = 0;
        foreach ($document->getElementsByTagName('img') as $image) {
            if ($image instanceof DOMElement
                && str_starts_with(strtolower(trim($image->getAttribute('src'))), 'blob:')) {
                $count++;
            }
        }

        return $count;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
    }
};

$bodyReferencesAttachmentImage = static function (
    string $html,
    int $attachmentId,
    ?string $sourceUrl = null,
): bool {
    if ($attachmentId < 1 || stripos($html, '<img') === false) {
        return false;
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previousErrorMode = libxml_use_internal_errors(true);

    try {
        if (! $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        )) {
            return false;
        }

        $expectedClass = 'wp-image-'.$attachmentId;
        $sourceUrl = is_string($sourceUrl)
            ? html_entity_decode(trim($sourceUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;

        foreach ($document->getElementsByTagName('img') as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $classes = preg_split('/\s+/u', trim($image->getAttribute('class'))) ?: [];
            if (! in_array($expectedClass, $classes, true)) {
                continue;
            }

            if ($sourceUrl === null) {
                return true;
            }

            $candidates = [trim($image->getAttribute('src'))];
            foreach (explode(',', $image->getAttribute('srcset')) as $candidate) {
                $parts = preg_split('/\s+/u', trim($candidate), 2) ?: [];
                $candidates[] = (string) ($parts[0] ?? '');
            }

            foreach ($candidates as $candidate) {
                if (html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8') === $sourceUrl) {
                    return true;
                }
            }
        }

        return false;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
    }
};

foreach ($featuredImageFallbacks as $postId => $attachmentId) {
    $post = $wpdb->get_row($wpdb->prepare(
        "SELECT p.ID, p.post_status, p.post_content,
                COALESCE((SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                          WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
                          ORDER BY pm.meta_id DESC LIMIT 1), '') AS featured_media_id
         FROM {$wpdb->posts} p WHERE p.ID = %d AND p.post_type = 'post'",
        $postId,
    ), ARRAY_A);
    $attachment = $wpdb->get_row($wpdb->prepare(
        "SELECT ID, post_parent, post_mime_type
         FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment'",
        $attachmentId,
    ), ARRAY_A);

    if (! is_array($post)
        || (string) ($post['post_status'] ?? '') !== 'publish'
        || (int) ($post['featured_media_id'] ?? 0) !== 0
        || ! $bodyReferencesAttachmentImage((string) ($post['post_content'] ?? ''), $attachmentId)
        || ! is_array($attachment)
        || (int) ($attachment['post_parent'] ?? 0) !== $postId
        || ! str_starts_with(strtolower((string) ($attachment['post_mime_type'] ?? '')), 'image/')) {
        WP_CLI::error("Featured-image fallback source evidence drifted for WordPress post {$postId}.");
    }
}

foreach ($blobImageRemovals as $postId => $decision) {
    $post = $wpdb->get_row($wpdb->prepare(
        "SELECT ID, post_status, post_content FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'post'",
        $postId,
    ), ARRAY_A);

    if (! is_array($post)
        || (string) ($post['post_status'] ?? '') !== 'publish'
        || ! hash_equals(
            $decision['raw_post_content_sha256'],
            hash('sha256', (string) ($post['post_content'] ?? '')),
        )) {
        WP_CLI::error("Blob-image removal raw source evidence drifted for WordPress post {$postId}.");
    }
}

$supportedStatuses = ['publish', 'draft', 'pending', 'future', 'private', 'auto-draft'];
$statusCounts = array_fill_keys($supportedStatuses, 0);
$statusRows = $wpdb->get_results(
    "SELECT post_status, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type = 'post' GROUP BY post_status ORDER BY post_status",
    ARRAY_A,
);

foreach ($statusRows as $row) {
    $status = (string) ($row['post_status'] ?? '');
    $count = (int) ($row['total'] ?? 0);

    if (! array_key_exists($status, $statusCounts) && $count > 0) {
        WP_CLI::error("Unsupported post status in frozen source: {$status}");
    }

    if (array_key_exists($status, $statusCounts)) {
        $statusCounts[$status] = $count;
    }
}

/** @return list<int> */
$postIds = static function (array $statuses) use ($wpdb): array {
    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $query = $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ({$placeholders}) ORDER BY ID",
        ...$statuses,
    );

    return array_map('intval', $wpdb->get_col($query));
};

$passwordProtectedIds = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts}
     WHERE post_type = 'post' AND post_password <> ''
     ORDER BY ID",
));
$passwordProtectedIdsSha256 = hash(
    'sha256',
    $passwordProtectedIds === [] ? '' : implode("\n", $passwordProtectedIds)."\n",
);

if ($passwordProtectedIds !== []) {
    WP_CLI::error('Password-protected WordPress posts are unsupported; explicit migration decisions are required before export.');
}

$privateDispositions = [];
$privateDispositionSha256 = null;

if ($statusCounts['private'] > 0) {
    $decisionInput = getenv('RAZBUDISE_PRIVATE_POST_DISPOSITIONS_FILE');
    $decisionInput = is_string($decisionInput) ? $decisionInput : '';
    $decisionPath = $decisionInput !== '' ? realpath($decisionInput) : false;
    $decisionPermissions = is_string($decisionPath) ? fileperms($decisionPath) : false;
    $decisionOwner = is_string($decisionPath) ? fileowner($decisionPath) : false;

    if (! is_string($decisionPath)
        || is_link($decisionInput)
        || ! is_file($decisionPath)
        || ! is_readable($decisionPath)
        || ! is_int($decisionPermissions)
        || (($decisionPermissions & 0777) & 0137) !== 0
        || ! is_int($decisionOwner)
        || $decisionOwner !== $processOwner
        || (is_string($wordpressRoot)
            && ($decisionPath === $wordpressRoot || str_starts_with($decisionPath, $wordpressRoot.DIRECTORY_SEPARATOR)))) {
        WP_CLI::error('Private posts require a process-owned 0640-or-stricter disposition file outside the WordPress web root.');
    }

    $decisionJson = file_get_contents($decisionPath);

    if (! is_string($decisionJson) || strlen($decisionJson) > 1_000_000) {
        WP_CLI::error('The private post disposition file could not be read safely.');
    }

    try {
        $decodedDecisions = json_decode($decisionJson, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        WP_CLI::error('The private post disposition file is not valid JSON.');
    }

    if (! is_array($decodedDecisions) || array_is_list($decodedDecisions)) {
        WP_CLI::error('The private post disposition file must map WordPress IDs to draft or archived.');
    }

    foreach ($decodedDecisions as $wordpressId => $targetStatus) {
        if (! ctype_digit((string) $wordpressId)
            || (int) $wordpressId < 1
            || ! in_array($targetStatus, ['draft', 'archived'], true)) {
            WP_CLI::error('The private post disposition file contains an invalid ID or target status.');
        }

        $privateDispositions[(int) $wordpressId] = $targetStatus;
    }

    ksort($privateDispositions, SORT_NUMERIC);
    $privateIds = $postIds(['private']);

    if (array_keys($privateDispositions) !== $privateIds) {
        WP_CLI::error('The private post disposition file must contain every and only current private post ID.');
    }

    $privateDispositionSha256 = hash_file('sha256', $decisionPath);

    if (! is_string($privateDispositionSha256)) {
        WP_CLI::error('The private post disposition file could not be checksummed.');
    }
}

/** @return string */
$sourceDate = static function (string $gmt, string $local): string {
    if ($gmt === '' || str_starts_with($gmt, '0000-00-00')) {
        $gmt = get_gmt_from_date($local, 'Y-m-d H:i:s');
    }

    $timestamp = strtotime($gmt.' UTC');

    if ($timestamp === false) {
        WP_CLI::error('A WordPress source timestamp could not be normalized.');
    }

    return gmdate('Y-m-d\TH:i:s', $timestamp);
};

/** @return array<string, mixed> */
$normalizePostDates = static function (array $payload) use ($sourceDate): array {
    $payload['date_gmt'] = $sourceDate(
        (string) ($payload['date_gmt'] ?? ''),
        (string) ($payload['date'] ?? ''),
    );
    $payload['modified_gmt'] = $sourceDate(
        (string) ($payload['modified_gmt'] ?? ''),
        (string) ($payload['modified'] ?? ''),
    );

    return $payload;
};

/** @return array<string, mixed> */
$restData = static function (string $route): array {
    $request = new WP_REST_Request('GET', $route);
    $request->set_param('context', 'view');
    $request->set_param('_embed', 1);
    $response = rest_do_request($request);

    if ($response->is_error()) {
        $error = $response->as_error();
        WP_CLI::error('REST source export failed: '.($error->get_error_code() ?: 'unknown_error'));
    }

    $data = rest_get_server()->response_to_data($response, true);

    if (! is_array($data) || array_is_list($data)) {
        WP_CLI::error('REST source export returned an invalid object.');
    }

    return $data;
};

/** @return array<string, mixed> */
$applyContentMigrationDecisions = static function (array $payload) use (
    $featuredImageFallbacks,
    $blobImageRemovals,
    $contentMigrationDecisionSha256,
    $restData,
    $persistedBlobImageCount,
    $bodyReferencesAttachmentImage,
): array {
    $postId = (int) ($payload['id'] ?? 0);
    $attachmentId = $featuredImageFallbacks[$postId] ?? null;
    $blobImageRemoval = $blobImageRemovals[$postId] ?? null;

    if (! is_int($attachmentId) && ! is_array($blobImageRemoval)) {
        return $payload;
    }

    $sourceBody = (string) ($payload['content']['rendered'] ?? '');

    if (isset($payload['_razbudise']) && ! is_array($payload['_razbudise'])) {
        WP_CLI::error("WordPress post {$postId} has a conflicting _razbudise payload namespace.");
    }

    $payload['_razbudise'] ??= [];

    if (is_int($attachmentId)) {
        $media = $restData("/wp/v2/media/{$attachmentId}");
        $expectedSourceUrl = wp_get_attachment_url($attachmentId);

        if ((int) ($payload['featured_media'] ?? 0) !== 0
            || (int) ($media['id'] ?? 0) !== $attachmentId
            || (int) ($media['post'] ?? 0) !== $postId
            || ($media['media_type'] ?? null) !== 'image'
            || ! str_starts_with(strtolower((string) ($media['mime_type'] ?? '')), 'image/')
            || ! is_string($expectedSourceUrl)
            || $expectedSourceUrl === ''
            || (string) ($media['source_url'] ?? '') !== $expectedSourceUrl
            || ! $bodyReferencesAttachmentImage($sourceBody, $attachmentId, $expectedSourceUrl)) {
            WP_CLI::error("Featured-image fallback REST evidence drifted for WordPress post {$postId}.");
        }

        $payload['_razbudise']['featured_image_fallback'] = [
            'strategy' => 'explicit_inline_attachment',
            'post_id' => $postId,
            'attachment_id' => $attachmentId,
            'decision_sha256' => $contentMigrationDecisionSha256,
            'media' => $media,
        ];
    }

    if (is_array($blobImageRemoval)) {
        $blobImageCount = $persistedBlobImageCount($sourceBody);

        if ($blobImageCount !== $blobImageRemoval['expected_blob_image_count']) {
            WP_CLI::error("Blob-image removal REST evidence drifted for WordPress post {$postId}.");
        }

        $payload['_razbudise']['blob_image_removal'] = [
            'strategy' => 'remove_nonpersistent_blob_images',
            'post_id' => $postId,
            'raw_post_content_sha256' => $blobImageRemoval['raw_post_content_sha256'],
            'expected_blob_image_count' => $blobImageCount,
            'decision_sha256' => $contentMigrationDecisionSha256,
        ];
    }

    return $payload;
};

/** @param resource $handle */
$writeRecord = static function ($handle, array $record): void {
    $json = wp_json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (! is_string($json) || fwrite($handle, $json."\n") === false) {
        WP_CLI::error('Could not write the private reconciliation export.');
    }
};

/**
 * @param  callable(resource): int  $writer
 * @return array{filename: string, records: int, sha256: string}
 */
$writeSection = static function (string $key, callable $writer) use ($destination, $fileNames): array {
    $filename = $fileNames[$key];
    $temporary = $destination.DIRECTORY_SEPARATOR.'.'.$filename.'.'.bin2hex(random_bytes(8)).'.tmp';
    $final = $destination.DIRECTORY_SEPARATOR.$filename;
    $handle = fopen($temporary, 'xb');

    if (! is_resource($handle)) {
        WP_CLI::error("Could not create private export file: {$filename}");
    }

    chmod($temporary, 0600);

    try {
        $records = $writer($handle);

        if (! fflush($handle)) {
            WP_CLI::error("Could not flush private export file: {$filename}");
        }
    } finally {
        fclose($handle);
    }

    if (! rename($temporary, $final)) {
        @unlink($temporary);
        WP_CLI::error("Could not finalize private export file: {$filename}");
    }

    chmod($final, 0640);
    $sha256 = hash_file('sha256', $final);

    if (! is_string($sha256)) {
        WP_CLI::error("Could not checksum private export file: {$filename}");
    }

    return compact('filename', 'records', 'sha256');
};

/** @return string */
$sourceFingerprint = static function () use (
    $wpdb,
    $postIds,
    $supportedStatuses,
    $featuredImageFallbacks,
    $blobImageRemovals,
    $contentMigrationDecisionSha256,
): string {
    $context = hash_init('sha256');
    $update = static function (string $section, array $row) use ($context): void {
        $json = wp_json_encode([$section, $row], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($json)) {
            WP_CLI::error('The source freeze fingerprint could not be encoded.');
        }

        hash_update($context, $json."\n");
    };

    $update('fingerprint_version', ['wordpress_content_reconciliation_v2']);
    $update('content_migration_decisions', [
        'sha256' => $contentMigrationDecisionSha256,
        'featured_image_fallbacks' => $featuredImageFallbacks,
        'blob_image_removals' => $blobImageRemovals,
    ]);

    foreach ($postIds($supportedStatuses) as $wordpressId) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.ID, p.post_status, p.post_password, p.post_author, p.post_name, p.post_title, p.post_excerpt,
                    p.post_content, p.post_date, p.post_date_gmt, p.post_modified, p.post_modified_gmt,
                    COALESCE((SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                              WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
                              ORDER BY pm.meta_id DESC LIMIT 1), '') AS featured_media_id
             FROM {$wpdb->posts} p WHERE p.ID = %d AND p.post_type = 'post'",
            $wordpressId,
        ), ARRAY_A);

        if (! is_array($row)) {
            WP_CLI::error('A source post disappeared while computing the freeze fingerprint.');
        }

        $update('post', $row);

        $postMetaRows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d ORDER BY meta_key, meta_id",
            $wordpressId,
        ), ARRAY_A);

        foreach ($postMetaRows as $postMetaRow) {
            $update('post_meta', ['post_id' => $wordpressId, ...$postMetaRow]);
        }

        $featuredMediaId = (int) ($row['featured_media_id'] ?? 0);

        if ($featuredMediaId > 0) {
            $attachment = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_parent, post_modified_gmt, post_mime_type, post_title, post_excerpt, post_content, guid
                 FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment'",
                $featuredMediaId,
            ), ARRAY_A);

            if (! is_array($attachment)) {
                WP_CLI::error('A featured attachment disappeared while computing the freeze fingerprint.');
            }

            $attachment['source_url'] = wp_get_attachment_url($featuredMediaId);
            $update('featured_attachment', $attachment);

            $attachmentMetaRows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d ORDER BY meta_key, meta_id",
                $featuredMediaId,
            ), ARRAY_A);

            foreach ($attachmentMetaRows as $attachmentMetaRow) {
                $update('featured_attachment_meta', ['attachment_id' => $featuredMediaId, ...$attachmentMetaRow]);
            }
        }

        $fallbackAttachmentId = $featuredImageFallbacks[$wordpressId] ?? null;

        if (is_int($fallbackAttachmentId)) {
            $attachment = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_parent, post_modified_gmt, post_mime_type, post_title, post_excerpt, post_content, guid
                 FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment'",
                $fallbackAttachmentId,
            ), ARRAY_A);

            if (! is_array($attachment)) {
                WP_CLI::error('A fallback featured attachment disappeared while computing the freeze fingerprint.');
            }

            $attachment['source_url'] = wp_get_attachment_url($fallbackAttachmentId);
            $update('fallback_featured_attachment', [
                'post_id' => $wordpressId,
                ...$attachment,
            ]);

            $attachmentMetaRows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d ORDER BY meta_key, meta_id",
                $fallbackAttachmentId,
            ), ARRAY_A);

            foreach ($attachmentMetaRows as $attachmentMetaRow) {
                $update('fallback_featured_attachment_meta', [
                    'post_id' => $wordpressId,
                    'attachment_id' => $fallbackAttachmentId,
                    ...$attachmentMetaRow,
                ]);
            }
        }
    }

    $termRows = $wpdb->get_results(
        "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count
         FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
         WHERE tt.taxonomy IN ('category', 'post_tag')
         ORDER BY tt.taxonomy, t.term_id, tt.term_taxonomy_id",
        ARRAY_A,
    );

    foreach ($termRows as $row) {
        $update('term', $row);
    }

    $relationshipRows = $wpdb->get_results(
        "SELECT tr.object_id, tr.term_taxonomy_id, tr.term_order
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
         WHERE p.post_type = 'post' AND tt.taxonomy IN ('category', 'post_tag')
         ORDER BY tr.object_id, tr.term_taxonomy_id, tr.term_order",
        ARRAY_A,
    );

    foreach ($relationshipRows as $row) {
        $update('relationship', $row);
    }

    $users = get_users(['orderby' => 'ID', 'order' => 'ASC']);

    foreach ($users as $user) {
        $update('author', [
            'ID' => (int) $user->ID,
            'user_nicename' => (string) $user->user_nicename,
            'display_name' => (string) $user->display_name,
            'old_url' => get_author_posts_url((int) $user->ID),
        ]);
    }

    return hash_final($context);
};

$sourceFingerprintBefore = $sourceFingerprint();

$files = [];
$files['published_posts'] = $writeSection('published_posts', static function ($handle) use ($postIds, $restData, $writeRecord, $normalizePostDates, $applyContentMigrationDecisions): int {
    $count = 0;

    foreach ($postIds(['publish']) as $wordpressId) {
        $payload = $applyContentMigrationDecisions(
            $normalizePostDates($restData("/wp/v2/posts/{$wordpressId}")),
        );

        if ((int) ($payload['id'] ?? 0) !== $wordpressId || ($payload['status'] ?? null) !== 'publish') {
            WP_CLI::error("Published REST source mismatch for WordPress ID {$wordpressId}");
        }

        $payload['_embedded']['wp:term'] ??= [];
        $writeRecord($handle, $payload);
        $count++;
    }

    return $count;
});

$files['nonpublic_posts'] = $writeSection('nonpublic_posts', static function ($handle) use ($postIds, $restData, $writeRecord, $normalizePostDates, $privateDispositions, $applyContentMigrationDecisions): int {
    $count = 0;
    $targetStatuses = [
        'draft' => 'draft',
        'pending' => 'review',
        'future' => 'scheduled',
    ];

    foreach ($postIds(['draft', 'pending', 'future', 'private']) as $wordpressId) {
        $payload = $applyContentMigrationDecisions(
            $normalizePostDates($restData("/wp/v2/posts/{$wordpressId}")),
        );
        $sourceStatus = (string) ($payload['status'] ?? '');
        $targetStatus = $sourceStatus === 'private'
            ? ($privateDispositions[$wordpressId] ?? null)
            : ($targetStatuses[$sourceStatus] ?? null);

        if ((int) ($payload['id'] ?? 0) !== $wordpressId || ! is_string($targetStatus)) {
            WP_CLI::error("Nonpublic REST source mismatch for WordPress ID {$wordpressId}");
        }

        $payload['_embedded']['wp:term'] ??= [];
        $writeRecord($handle, [
            'payload' => $payload,
            'target_status' => $targetStatus,
        ]);
        $count++;
    }

    return $count;
});

$files['auto_drafts'] = $writeSection('auto_drafts', static function ($handle) use ($wpdb, $sourceDate, $writeRecord): int {
    $rows = $wpdb->get_results(
        "SELECT ID, post_status, post_name, post_title, post_date, post_date_gmt, post_modified, post_modified_gmt
         FROM {$wpdb->posts}
         WHERE post_type = 'post' AND post_status = 'auto-draft'
         ORDER BY ID",
        ARRAY_A,
    );

    foreach ($rows as $row) {
        $writeRecord($handle, [
            'wordpress_id' => (int) $row['ID'],
            'source_status' => 'auto-draft',
            'source_slug' => (string) $row['post_name'],
            'source_title' => (string) $row['post_title'],
            'source_date_gmt' => $sourceDate((string) $row['post_date_gmt'], (string) $row['post_date']),
            'source_modified_gmt' => $sourceDate((string) $row['post_modified_gmt'], (string) $row['post_modified']),
            'disposition' => 'retain_in_verified_wordpress_backup',
        ]);
    }

    return count($rows);
});

foreach (['categories' => 'category', 'tags' => 'post_tag'] as $key => $taxonomy) {
    $files[$key] = $writeSection($key, static function ($handle) use ($taxonomy, $restData, $writeRecord): int {
        $ids = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
            'orderby' => 'term_id',
            'order' => 'ASC',
        ]);

        if (is_wp_error($ids)) {
            WP_CLI::error("Could not enumerate {$taxonomy} terms.");
        }

        $endpoint = $taxonomy === 'category' ? 'categories' : 'tags';

        foreach ($ids as $termId) {
            $payload = $restData("/wp/v2/{$endpoint}/".(int) $termId);
            $payload['taxonomy'] = $taxonomy;
            $writeRecord($handle, $payload);
        }

        return count($ids);
    });
}

$files['authors'] = $writeSection('authors', static function ($handle) use ($writeRecord): int {
    $users = get_users(['orderby' => 'ID', 'order' => 'ASC']);

    foreach ($users as $user) {
        $writeRecord($handle, [
            'wordpress_id' => (int) $user->ID,
            'source_slug' => (string) $user->user_nicename,
            'display_name' => (string) $user->display_name,
            'old_url' => get_author_posts_url((int) $user->ID),
        ]);
    }

    return count($users);
});

$discardFrozenFiles = static function () use ($destination, $fileNames): void {
    foreach ($fileNames as $filename) {
        @unlink($destination.DIRECTORY_SEPARATOR.$filename);
    }
};

$sourceFingerprintAfter = $sourceFingerprint();

if (! hash_equals($sourceFingerprintBefore, $sourceFingerprintAfter)) {
    $discardFrozenFiles();
    WP_CLI::error('WordPress content, taxonomy, or author identity drifted during export; the partial bundle was discarded.');
}

if ($files['published_posts']['records'] !== $statusCounts['publish']
    || $files['nonpublic_posts']['records'] !== $statusCounts['draft'] + $statusCounts['pending'] + $statusCounts['future'] + $statusCounts['private']
    || $files['auto_drafts']['records'] !== $statusCounts['auto-draft']) {
    $discardFrozenFiles();
    WP_CLI::error('Post status cardinality changed during the private export; discard the bundle and freeze again.');
}

$metadata = [
    'schema_version' => 1,
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'source_base_url' => $sourceBaseUrl,
    'source_fingerprint_sha256' => $sourceFingerprintAfter,
    'private_post_dispositions' => [
        'records' => count($privateDispositions),
        'sha256' => $privateDispositionSha256,
    ],
    'password_protected_posts' => [
        'records' => count($passwordProtectedIds),
        'ids_sha256' => $passwordProtectedIdsSha256,
    ],
    'content_migration_decisions' => [
        'records' => count($featuredImageFallbacks) + count($blobImageRemovals),
        'featured_image_fallbacks' => count($featuredImageFallbacks),
        'blob_image_removals' => count($blobImageRemovals),
        'sha256' => $contentMigrationDecisionSha256,
    ],
    'post_status_counts' => $statusCounts,
    'files' => $files,
];
$metadataJson = wp_json_encode(
    $metadata,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
);

if (! is_string($metadataJson)) {
    WP_CLI::error('Could not encode reconciliation metadata.');
}

$metadataTemporary = $destination.DIRECTORY_SEPARATOR.'.metadata.json.'.bin2hex(random_bytes(8)).'.tmp';
$metadataPath = $destination.DIRECTORY_SEPARATOR.'metadata.json';

if (file_put_contents($metadataTemporary, $metadataJson."\n", LOCK_EX) === false) {
    WP_CLI::error('Could not write reconciliation metadata.');
}

chmod($metadataTemporary, 0600);

if (! rename($metadataTemporary, $metadataPath)) {
    @unlink($metadataTemporary);
    WP_CLI::error('Could not finalize reconciliation metadata.');
}

chmod($metadataPath, 0640);
$metadataSha256 = hash_file('sha256', $metadataPath);

if (! is_string($metadataSha256)) {
    WP_CLI::error('Could not checksum reconciliation metadata.');
}

foreach ([...array_values($fileNames), 'metadata.json'] as $fileName) {
    if (! chmod($destination.DIRECTORY_SEPARATOR.$fileName, 0440)) {
        WP_CLI::error("Could not freeze reconciliation file read-only: {$fileName}");
    }
}

if (! chmod($destination, 0550)) {
    WP_CLI::error('Could not freeze the reconciliation bundle directory read-only.');
}

WP_CLI::log('WORDPRESS_CONTENT_RECONCILIATION_EXPORTED=1');
WP_CLI::log('WORDPRESS_CONTENT_RECONCILIATION_IMMUTABLE_BY_MODE=1');
WP_CLI::log('WORDPRESS_CONTENT_RECONCILIATION_METADATA_SHA256='.$metadataSha256);
WP_CLI::log('WORDPRESS_CONTENT_RECONCILIATION_PUBLISHED='.$files['published_posts']['records']);
WP_CLI::log('WORDPRESS_CONTENT_RECONCILIATION_NONPUBLIC='.$files['nonpublic_posts']['records']);
WP_CLI::log('WORDPRESS_CONTENT_RECONCILIATION_AUTO_DRAFTS='.$files['auto_drafts']['records']);
