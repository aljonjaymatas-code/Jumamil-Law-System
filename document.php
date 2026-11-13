<?php
require_once __DIR__ . '/db.php';
ensureSchema();
requireLogin();

$db = openDb();
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT d.*, u.name AS created_by_name, r.name AS reviewed_by_name, COALESCE(c.full_name, d.client_name) AS client_name FROM documents d LEFT JOIN users u ON u.id = d.created_by LEFT JOIN users r ON r.id = d.reviewed_by LEFT JOIN clients c ON c.id = d.client_id WHERE d.id = ?');
$stmt->execute([$id]);
$document = $stmt->fetch();

if (!$document) {
    header('Location: dashboard.php');
    exit;
}

$pageError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'review' && currentRole() === ROLE_ATTORNEY) {
        $status = trim($_POST['status'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $allowedStatuses = ['Draft', 'Under Review', 'Needs Revision', 'Approved for Printing'];

        if (!in_array($status, $allowedStatuses, true)) {
            $pageError = 'Select a valid review decision.';
        } else {
            $update = $db->prepare('UPDATE documents SET status = ?, notes = ?, reviewed_by = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([$status, $notes, (int)$_SESSION['user_id'], $id]);
            $review = $db->prepare('INSERT INTO document_reviews (document_id, reviewer_id, decision, remarks) VALUES (?, ?, ?, ?)');
            $review->execute([$id, (int)$_SESSION['user_id'], $status, $notes !== '' ? $notes : null]);
            logActivity($id, 'document.reviewed', ['decision' => $status]);
            header('Location: document.php?id=' . $id . '&saved=review');
            exit;
        }
    } elseif ($action === 'version') {
        $upload = $_FILES['document_file'] ?? null;
        if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
            $pageError = 'Please select a valid file to upload.';
        } else {
            $originalName = basename($upload['name']);
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['pdf', 'doc', 'docx', 'txt'], true)) {
                $pageError = 'Only PDF, DOC, DOCX, and TXT files are allowed.';
            } elseif (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
                throw new RuntimeException('The document storage directory could not be created.');
            } else {
                $targetPath = UPLOAD_DIR . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
                if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
                    $pageError = 'The document version could not be stored.';
                } else {
                    $versionNumber = addDocumentVersion(
                        $id,
                        $originalName,
                        $targetPath,
                        (int)$_SESSION['user_id'],
                        trim($_POST['change_note'] ?? '') ?: null
                    );
                    $ocr = runOcrPipeline($targetPath);
                    $update = $db->prepare(
                        'UPDATE documents SET file_name = ?, stored_path = ?, status = ?, reviewed_by = NULL, ocr_status = ?, ocr_text = ?, document_number = COALESCE(?, document_number), page_number = COALESCE(?, page_number), book_number = COALESCE(?, book_number), series_year = COALESCE(?, series_year), date_notarized = COALESCE(?, date_notarized), updated_at = NOW() WHERE id = ?'
                    );
                    $update->execute([
                        $originalName,
                        $targetPath,
                        'Under Review',
                        ocrStatusLabel($ocr),
                        $ocr['text'] ?? '',
                        $ocr['document_number'] ?? null,
                        $ocr['page_number'] ?? null,
                        $ocr['book_number'] ?? null,
                        $ocr['series_year'] ?? null,
                        $ocr['date_notarized'] ?? null,
                        $id,
                    ]);
                    logActivity($id, 'document.version_uploaded', [
                        'file_name' => $originalName,
                        'version' => $versionNumber,
                    ]);
                    logActivity($id, ($ocr['status'] ?? '') === 'completed' ? 'ocr.completed' : 'ocr.failed', [
                        'status' => $ocr['status'] ?? 'failed',
                        'message' => $ocr['message'] ?? '',
                        'version' => $versionNumber,
                    ]);
                    header('Location: document.php?id=' . $id . '&saved=version');
                    exit;
                }
            }
        }
    }
}

logActivity($id, 'document.viewed');

$versionsStmt = $db->prepare('SELECT v.*, u.name AS uploaded_by_name FROM document_versions v LEFT JOIN users u ON u.id = v.uploaded_by WHERE v.document_id = ? ORDER BY v.version_number DESC');
$versionsStmt->execute([$id]);
$versions = $versionsStmt->fetchAll();

$reviewsStmt = $db->prepare('SELECT r.*, u.name AS reviewer_name FROM document_reviews r LEFT JOIN users u ON u.id = r.reviewer_id WHERE r.document_id = ? ORDER BY r.created_at DESC');
$reviewsStmt->execute([$id]);
$reviews = $reviewsStmt->fetchAll();

$activityStmt = $db->prepare('SELECT a.*, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.document_id = ? ORDER BY a.created_at DESC LIMIT 50');
$activityStmt->execute([$id]);
$activity = $activityStmt->fetchAll();

$storedPath = $document['stored_path'] ?? '';
$previewExtension = $storedPath !== '' ? strtolower(pathinfo($storedPath, PATHINFO_EXTENSION)) : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Details</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="page-shell narrow">
        <aside class="sidebar">
            <div class="brand">Jumamil Law</div>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="upload.php">Upload Document</a>
                <a href="logout.php">Logout</a>
            </nav>
        </aside>

        <main class="main-panel">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Document Record</p>
                    <h2><?= htmlspecialchars($document['title']) ?></h2>
                </div>
                <span class="status-badge"><?= htmlspecialchars($document['status']) ?></span>
            </header>

            <?php if ($pageError !== '') : ?>
                <div class="error-box"><?= htmlspecialchars($pageError) ?></div>
            <?php elseif (($_GET['saved'] ?? '') === 'review') : ?>
                <div class="success-box">Review decision saved.</div>
            <?php elseif (($_GET['saved'] ?? '') === 'version') : ?>
                <div class="success-box">New version uploaded and returned to the attorney review queue.</div>
            <?php endif; ?>

            <section class="panel">
                <div class="detail-grid">
                    <div><strong>Category</strong><p><?= htmlspecialchars($document['category']) ?></p></div>
                    <div><strong>Client</strong><p><?= htmlspecialchars($document['client_name'] ?: 'N/A') ?></p></div>
                    <div><strong>Document Number</strong><p><?= htmlspecialchars($document['document_number'] ?? '') ?></p></div>
                    <div><strong>Page Number</strong><p><?= htmlspecialchars($document['page_number'] ?? '') ?></p></div>
                    <div><strong>Book Number</strong><p><?= htmlspecialchars($document['book_number'] ?? '') ?></p></div>
                    <div><strong>Series Year</strong><p><?= htmlspecialchars($document['series_year'] ?? '') ?></p></div>
                    <div><strong>Date Notarized</strong><p><?= htmlspecialchars($document['date_notarized'] ?? '') ?></p></div>
                    <div><strong>OCR Status</strong><p><?= htmlspecialchars($document['ocr_status'] ?? '') ?></p></div>
                    <div><strong>File Name</strong><p><?= htmlspecialchars($document['file_name'] ?? '') ?></p></div>
                    <div><strong>Created By</strong><p><?= htmlspecialchars($document['created_by_name'] ?: 'System') ?></p></div>
                    <div><strong>Reviewed By</strong><p><?= htmlspecialchars($document['reviewed_by_name'] ?: 'Pending') ?></p></div>
                </div>

                <?php if ($storedPath !== '' && is_file($storedPath)) : ?>
                    <div class="preview-panel">
                        <h4>Document Preview</h4>
                        <?php if ($previewExtension === 'pdf') : ?>
                            <iframe class="pdf-preview" src="file.php?id=<?= $id ?>" title="PDF Preview"></iframe>
                        <?php else : ?>
                            <p><a href="file.php?id=<?= $id ?>">Download current file</a></p>
                            <pre class="file-preview"><?= htmlspecialchars($document['ocr_text'] ?: 'No extracted text available yet.') ?></pre>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <section class="history-section">
                    <h3>Document Versions</h3>
                    <?php if (!$versions) : ?>
                        <p class="muted">No version history is available.</p>
                    <?php else : ?>
                        <div class="history-list">
                            <?php foreach ($versions as $version) : ?>
                                <article class="history-item">
                                    <div>
                                        <strong>Version <?= (int)$version['version_number'] ?> — <?= htmlspecialchars($version['file_name']) ?></strong>
                                        <p><?= htmlspecialchars($version['change_note'] ?: 'No change note') ?></p>
                                        <small><?= htmlspecialchars($version['uploaded_by_name'] ?: 'Former user') ?> · <?= htmlspecialchars($version['created_at']) ?> · <?= number_format((int)$version['file_size']) ?> bytes · SHA-256 <?= htmlspecialchars($version['sha256']) ?></small>
                                    </div>
                                    <a href="file.php?id=<?= $id ?>&amp;version=<?= (int)$version['id'] ?>">Open file</a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <form method="post" enctype="multipart/form-data" class="review-form">
                    <input type="hidden" name="action" value="version">
                    <h3>Upload New Version</h3>
                    <label for="document_file">Replacement or updated document</label>
                    <input id="document_file" name="document_file" type="file" accept=".pdf,.doc,.docx,.txt" required>
                    <label for="change_note">Version note</label>
                    <textarea id="change_note" name="change_note" rows="3" placeholder="Describe the change or scan batch"></textarea>
                    <button type="submit" class="secondary-btn">Upload Version</button>
                </form>

                <?php if (currentRole() === ROLE_ATTORNEY) : ?>
                    <form method="post" class="review-form">
                        <input type="hidden" name="action" value="review">
                        <h3>Attorney Pre-Printing Review</h3>
                        <label for="status">Decision</label>
                        <select id="status" name="status">
                            <?php foreach (['Draft', 'Under Review', 'Needs Revision', 'Approved for Printing'] as $status) : ?>
                                <option value="<?= htmlspecialchars($status) ?>" <?= $document['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="notes">Review Notes</label>
                        <textarea id="notes" name="notes" rows="5"><?= htmlspecialchars($document['notes'] ?? '') ?></textarea>
                        <button type="submit" class="primary-btn">Save Review</button>
                    </form>
                <?php else : ?>
                    <div class="notes-box">
                        <h4>Review Notes</h4>
                        <p><?= htmlspecialchars($document['notes'] ?? 'No notes available.') ?></p>
                    </div>
                <?php endif; ?>

                <section class="history-section">
                    <h3>Attorney Review History</h3>
                    <?php if (!$reviews) : ?>
                        <p class="muted">No attorney review actions recorded yet.</p>
                    <?php else : ?>
                        <div class="history-list">
                            <?php foreach ($reviews as $review) : ?>
                                <article class="history-item">
                                    <div>
                                        <strong><?= htmlspecialchars($review['decision']) ?></strong>
                                        <p><?= nl2br(htmlspecialchars($review['remarks'] ?: 'No remarks')) ?></p>
                                    </div>
                                    <small><?= htmlspecialchars($review['reviewer_name'] ?: 'Former user') ?> · <?= htmlspecialchars($review['created_at']) ?></small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="history-section">
                    <h3>Activity Log</h3>
                    <div class="history-list">
                        <?php foreach ($activity as $item) : ?>
                            <?php $details = json_decode((string)($item['details'] ?? ''), true) ?: []; ?>
                            <article class="history-item">
                                <div>
                                    <strong><?= htmlspecialchars($item['action']) ?></strong>
                                    <?php if ($details) : ?>
                                        <p><?= htmlspecialchars(implode(' · ', array_map(
                                            static function ($key, $value): string {
                                                return $key . ': ' . (is_scalar($value) ? (string)$value : json_encode($value));
                                            },
                                            array_keys($details),
                                            array_values($details)
                                        ))) ?></p>
                                    <?php endif; ?>
                                </div>
                                <small><?= htmlspecialchars($item['user_name'] ?: 'Former user') ?> · <?= htmlspecialchars($item['created_at']) ?></small>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$activity) : ?>
                            <p class="muted">No activity recorded yet.</p>
                        <?php endif; ?>
                    </div>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
