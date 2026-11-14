<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
ensureSchema();
requireLogin();

if (currentRole() !== ROLE_ATTORNEY) {
    header('Location: dashboard.php');
    exit;
}

$db = openDb();
$search = trim((string)($_GET['search'] ?? ''));
$notarizedDate = validDateFilter(trim((string)($_GET['notarized_date'] ?? '')));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$rows = fetchDocuments($search, $notarizedDate, $statusFilter);
$queueCounts = ['Under Review' => 0, 'Needs Revision' => 0, 'Approved for Printing' => 0];
$countsStmt = $db->query("SELECT status, COUNT(*) AS total FROM documents WHERE status IN ('Under Review', 'Needs Revision', 'Approved for Printing') GROUP BY status");
foreach ($countsStmt as $countRow) {
    $queueCounts[$countRow['status']] = (int)$countRow['total'];
}
$db = null;
?>
<?php renderAppStart('Attorney review queue', 'review', 'Check uploaded documents, review details and notes, then mark the ones ready for printing.', 'dashboard-view'); ?>
<section class="stats-grid">
    <?php foreach ([
        ['Awaiting review', $queueCounts['Under Review'], 'RV'],
        ['Needs staff revision', $queueCounts['Needs Revision'], 'FIX'],
        ['Approved for printing', $queueCounts['Approved for Printing'], 'OK'],
        ['Visible records', count($rows), 'ALL'],
    ] as [$label, $value, $icon]) : ?>
        <article class="stat-card">
            <div class="stat-top"><span class="stat-label"><?= htmlspecialchars($label) ?></span><span class="stat-icon" aria-hidden="true"><?= htmlspecialchars($icon) ?></span></div>
            <strong><?= (int)$value ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel">
    <div class="panel-header">
        <div>
            <p class="eyebrow">Attorney review</p>
            <h2>Documents for review</h2>
        </div>
    </div>
    <form method="get" class="search-form filter-form" role="search">
        <label class="sr-only" for="review-search">Search client, document number, or OCR text</label>
        <input id="review-search" type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Client, document number, title, or OCR text">
        <label class="sr-only" for="review-date">Filter by notarized date</label>
        <input id="review-date" type="date" name="notarized_date" value="<?= htmlspecialchars($notarizedDate) ?>">
        <label class="sr-only" for="review-status">Filter by document status</label>
        <select id="review-status" name="status">
            <option value="">All statuses</option>
            <?php foreach (['Draft', 'Under Review', 'Needs Revision', 'Approved for Printing'] as $status) : ?>
                <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="primary-btn small">Apply filters</button>
        <a class="secondary-btn small" href="documents.php">Clear</a>
    </form>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Document</th><th>Client</th><th>Document #</th><th>Notarized</th><th>Status</th><th>Submitted by</th><th>Review</th></tr>
            </thead>
            <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="7" class="empty-state">No documents match the selected filters.</td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['title']) ?></strong><br><small><?= htmlspecialchars($row['category']) ?></small></td>
                            <td><?= htmlspecialchars($row['client_name'] ?: 'Not linked') ?></td>
                            <td><?= htmlspecialchars($row['document_number'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($row['date_notarized'] ?? '—') ?></td>
                            <td><span class="status-badge <?= appStatusClass($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                            <td><?= htmlspecialchars($row['created_by_name'] ?: 'System') ?></td>
                            <td><a class="table-action" href="document.php?id=<?= (int)$row['id'] ?>">Review file</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php renderAppEnd(); ?>
