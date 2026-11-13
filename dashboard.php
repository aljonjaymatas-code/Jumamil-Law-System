<?php
require_once __DIR__ . '/db.php';
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
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="page-shell">
        <aside class="sidebar">
            <div class="brand">Jumamil Law</div>
            <nav>
                <a class="active" href="dashboard.php">Dashboard</a>
                <a href="upload.php">Upload Document</a>
                <?php if ($userRole === 'attorney') : ?>
                    <a href="documents.php">Review Queue</a>
                <?php endif; ?>
                <a href="logout.php">Logout</a>
            </nav>
        </aside>

        <main class="main-panel">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Welcome</p>
                    <h2><?= htmlspecialchars(userName()) ?></h2>
                </div>
                <span class="role-pill"><?= htmlspecialchars(strtoupper($userRole)) ?></span>
            </header>

            <section class="stats-grid">
                <div class="stat-card">
                    <span>Total Documents</span>
                    <strong><?= $stats['total_docs'] ?></strong>
                </div>
                <div class="stat-card">
                    <span>Drafts</span>
                    <strong><?= $stats['draft'] ?></strong>
                </div>
                <div class="stat-card">
                    <span>Under Review</span>
                    <strong><?= $stats['under_review'] ?></strong>
                </div>
                <div class="stat-card">
                    <span>Approved</span>
                    <strong><?= $stats['approved'] ?></strong>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h3>Recent Documents</h3>
                    <div class="search-stack">
                        <form method="get" class="search-form filter-form">
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Client, document number, or OCR text">
                            <input type="date" name="notarized_date" value="<?= htmlspecialchars($notarizedDate) ?>" aria-label="Notarized date">
                            <select name="status" aria-label="Document status">
                                <option value="">All statuses</option>
                                <?php foreach (['Draft', 'Under Review', 'Needs Revision', 'Approved', 'Approved for Printing'] as $status) : ?>
                                    <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="primary-btn small">Search</button>
                            <a class="secondary-btn small" href="dashboard.php">Clear</a>
                        </form>
                        <a class="primary-btn small" href="upload.php">New Upload</a>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Client</th>
                            <th>Document #</th>
                            <th>Notarized</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows) : ?>
                            <tr>
                                <td colspan="8" class="empty-state">No documents match your search. Upload the first legal file or try a different filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['title']) ?></td>
                                    <td><?= htmlspecialchars($row['category']) ?></td>
                                    <td><?= htmlspecialchars($row['client_name'] ?: 'N/A') ?></td>
                                    <td><?= htmlspecialchars($row['document_number'] ?: 'N/A') ?></td>
                                    <td><?= htmlspecialchars($row['date_notarized'] ?? 'N/A') ?></td>
                                    <td><span class="status-badge"><?= htmlspecialchars($row['status']) ?></span></td>
                                    <td><?= htmlspecialchars($row['created_by_name'] ?: 'System') ?></td>
                                    <td><a href="document.php?id=<?= (int)$row['id'] ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
