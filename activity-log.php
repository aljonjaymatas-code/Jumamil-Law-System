<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
ensureSchema();
requireLogin();

$db = openDb();
$pageSize = 50;
$pageValue = $_GET['page'] ?? '1';
$pageNumber = filter_var(is_scalar($pageValue) ? (string)$pageValue : '', FILTER_VALIDATE_INT);
$page = $pageNumber === false || $pageNumber === null ? 1 : max(1, $pageNumber);
$totalActivities = (int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$totalPages = max(1, (int)ceil($totalActivities / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$stmt = $db->query(
    'SELECT a.document_id, a.action, a.details, a.created_at,
            u.name AS user_name, d.title AS document_title, d.document_number,
            COALESCE(c.full_name, d.client_name) AS client_name
     FROM activity_logs a
     LEFT JOIN users u ON u.id = a.user_id
     LEFT JOIN documents d ON d.id = a.document_id
     LEFT JOIN clients c ON c.id = d.client_id
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT ' . $pageSize . ' OFFSET ' . $offset
);
$activities = $stmt->fetchAll();
$db = null;

$actionLabels = [
    'document.uploaded' => 'Uploaded document',
    'document.viewed' => 'Viewed record',
    'document.reviewed' => 'Reviewed document',
    'document.draft_content_saved' => 'Saved draft',
    'document.version_uploaded' => 'Added document version',
    'ocr.completed' => 'OCR completed',
    'ocr.failed' => 'OCR failed',
];
$firstResult = $totalActivities === 0 ? 0 : $offset + 1;
$lastResult = min($offset + $pageSize, $totalActivities);
?>
<?php renderAppStart('Activity Log', 'activity', 'Review recent activity across the internal document archive.', 'dashboard-view'); ?>
<section class="panel activity-log-panel">
    <div class="panel-header">
        <div>
            <p class="eyebrow">System history</p>
            <h2>Activity Log</h2>
        </div>
        <span class="role-pill">Immutable audit trail</span>
    </div>

    <div class="table-wrap activity-log-wrap">
        <table class="activity-log-table">
            <thead>
                <tr>
                    <th scope="col">Date and time</th>
                    <th scope="col">User</th>
                    <th scope="col">Activity</th>
                    <th scope="col">Document</th>
                    <th scope="col">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$activities) : ?>
                    <tr><td colspan="5" class="empty-state">No activity has been recorded yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($activities as $activity) : ?>
                        <?php
                        $details = $activity['details'] === null
                            ? []
                            : json_decode((string)$activity['details'], true, 512, JSON_THROW_ON_ERROR);
                        $details = is_array($details) ? $details : ['value' => $details];
                        $action = (string)$activity['action'];
                        $actionLabel = $actionLabels[$action] ?? ucwords(str_replace(['.', '_'], ' ', $action));
                        ?>
                        <tr>
                            <td><time><?= appEscape((string)$activity['created_at']) ?></time></td>
                            <td><?= appEscape((string)($activity['user_name'] ?: 'System')) ?></td>
                            <td><?= appEscape($actionLabel) ?></td>
                            <td>
                                <?php if ($activity['document_id'] !== null && $activity['document_title'] !== null) : ?>
                                    <a href="document.php?id=<?= (int)$activity['document_id'] ?>"><?= appEscape((string)$activity['document_title']) ?></a>
                                    <small class="activity-document-meta">
                                        <?= appEscape((string)($activity['client_name'] ?: '')) ?>
                                        <?= $activity['document_number'] ? ' · ' . appEscape((string)$activity['document_number']) : '' ?>
                                    </small>
                                <?php else : ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($details) : ?>
                                    <div class="activity-details">
                                        <?php foreach ($details as $key => $value) : ?>
                                            <?php
                                            $detailValue = is_scalar($value) || $value === null
                                                ? (string)($value ?? '—')
                                                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                                            ?>
                                            <span><strong><?= appEscape(ucwords(str_replace('_', ' ', (string)$key))) ?>:</strong> <?= appEscape($detailValue) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else : ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="activity-pagination" aria-label="Activity log pages">
        <span>Showing <?= $firstResult ?>-<?= $lastResult ?> of <?= $totalActivities ?> activities</span>
        <div>
            <?php if ($page > 1) : ?>
                <a class="secondary-btn small" href="activity-log.php?page=<?= $page - 1 ?>">Previous</a>
            <?php else : ?>
                <span class="secondary-btn small pagination-disabled" aria-disabled="true">Previous</span>
            <?php endif; ?>
            <?php if ($page < $totalPages) : ?>
                <a class="primary-btn small" href="activity-log.php?page=<?= $page + 1 ?>">Next</a>
            <?php else : ?>
                <span class="primary-btn small pagination-disabled" aria-disabled="true">Next</span>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php renderAppEnd(); ?>
