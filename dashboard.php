<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
ensureSchema();
requireLogin();

$db = openDb();
$userRole = currentRole();
$search = trim((string)($_GET['search'] ?? ''));
$notarizedDate = validDateFilter(trim((string)($_GET['notarized_date'] ?? '')));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$stats = [
    'total_docs' => 0,
    'draft' => 0,
    'under_review' => 0,
    'approved' => 0,
];

$statsStmt = $db->query("SELECT status, COUNT(*) AS total FROM documents GROUP BY status");
foreach ($statsStmt as $row) {
    $statusKey = strtolower(str_replace(' ', '_', $row['status']));
    $stats['total_docs'] += (int)$row['total'];
    if ($statusKey === 'draft') {
        $stats['draft'] = (int)$row['total'];
    }
    if ($statusKey === 'under_review' || $statusKey === 'needs_revision') {
        $stats['under_review'] += (int)$row['total'];
    }
    if ($statusKey === 'approved' || $statusKey === 'approved_for_printing') {
        $stats['approved'] = (int)$row['total'];
    }
}

$rows = fetchDocuments($search, $notarizedDate, $statusFilter);
$db = null;
?>
<?php renderAppStart('Document dashboard', 'dashboard', 'Track legal records, review status, and recent office document activity.', 'dashboard-view'); ?>
<section class="stats-grid">
    <?php foreach ([
        ['Total documents', $stats['total_docs'], 'ALL'],
        ['Drafts', $stats['draft'], 'DR'],
        ['In review', $stats['under_review'], 'RV'],
        ['Approved for printing', $stats['approved'], 'OK'],
    ] as [$label, $value, $icon]) : ?>
        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label"><?= htmlspecialchars($label) ?></span>
                <span class="stat-icon" aria-hidden="true"><?= htmlspecialchars($icon) ?></span>
            </div>
            <strong><?= (int)$value ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel">
    <div class="panel-header">
        <div>
            <p class="eyebrow">Central document archive</p>
            <h2>Recent documents</h2>
        </div>
        <div class="dashboard-actions">
            <a class="secondary-btn small" href="upload.php">Upload document</a>
            <?php if ($userRole === ROLE_ATTORNEY) : ?>
                <a class="primary-btn small" href="documents.php">Open review queue</a>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" class="search-form filter-form" role="search">
        <label class="sr-only" for="document-filter">Filter by client, document number, or OCR text</label>
        <input id="document-filter" type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Client, document number, title, or OCR text">
        <label class="sr-only" for="date-filter">Filter by notarized date</label>
        <input id="date-filter" type="date" name="notarized_date" value="<?= htmlspecialchars($notarizedDate) ?>">
        <label class="sr-only" for="status-filter">Filter by status</label>
        <select id="status-filter" name="status">
            <option value="">All statuses</option>
            <?php foreach (['Draft', 'Under Review', 'Needs Revision', 'Approved for Printing'] as $status) : ?>
                <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="primary-btn small">Apply filters</button>
        <a class="secondary-btn small" href="dashboard.php">Clear</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Client</th>
                    <th>Document #</th>
                    <th>Notarized</th>
                    <th>Status</th>
                    <th>Added by</th>
                    <th>Open</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="7" class="empty-state">No documents match those filters. Try a different search or upload a record.</td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['title']) ?></strong><br><small><?= htmlspecialchars($row['category']) ?></small></td>
                            <td><?= htmlspecialchars($row['client_name'] ?: 'Not linked') ?></td>
                            <td><?= htmlspecialchars($row['document_number'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($row['date_notarized'] ?? '—') ?></td>
                            <td><span class="status-badge <?= appStatusClass($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                            <td><?= htmlspecialchars($row['created_by_name'] ?: 'System') ?></td>
                            <td><a class="table-action" href="document.php?id=<?= (int)$row['id'] ?>">View record</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php renderAppEnd(); ?>0