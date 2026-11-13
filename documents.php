<?php
require_once __DIR__ . '/db.php';
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
$db = null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Queue</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="page-shell">
        <aside class="sidebar">
            <div class="brand">Jumamil Law</div>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="upload.php">Upload Document</a>
                <a class="active" href="documents.php">Review Queue</a>
                <a href="logout.php">Logout</a>
            </nav>
        </aside>

        <main class="main-panel">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Attorney Review</p>
                    <h2>Review Queue</h2>
                </div>
            </header>

            <section class="panel">
                <div class="panel-header">
                    <div class="search-stack">
                        <form method="get" class="search-form filter-form">
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Client, document number, or OCR text">
                            <input type="date" name="notarized_date" value="<?= htmlspecialchars($notarizedDate) ?>" aria-label="Notarized date">
                            <select name="status" aria-label="Review status">
                                <option value="">All statuses</option>
                                <?php foreach (['Draft', 'Under Review', 'Needs Revision', 'Approved', 'Approved for Printing'] as $status) : ?>
                                    <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="primary-btn small">Search</button>
                            <a class="secondary-btn small" href="documents.php">Clear</a>
                        </form>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
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
                                <td colspan="7" class="empty-state">No documents match the selected filters.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['title']) ?></td>
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
