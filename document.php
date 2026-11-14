<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
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
$submittedDraftContent = null;
if (!isset($_SESSION['draft_editor_csrf'])) {
    $_SESSION['draft_editor_csrf'] = bin2hex(random_bytes(32));
}
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
    } elseif ($action === 'save_draft') {
        if (currentRole() !== ROLE_STAFF) {
            $pageError = 'Only staff can edit draft content.';
        } elseif (
            !is_string($_POST['csrf_token'] ?? null)
            || !is_string($_SESSION['draft_editor_csrf'] ?? null)
            || !hash_equals($_SESSION['draft_editor_csrf'], $_POST['csrf_token'])
        ) {
            $pageError = 'The editor session expired. Reload the page before saving again.';
        } else {
            $rawDraftContent = is_string($_POST['draft_content'] ?? null) ? $_POST['draft_content'] : '';
            try {
                $submittedDraftContent = sanitizeRichText($rawDraftContent);
            } catch (InvalidArgumentException $exception) {
                $pageError = $exception->getMessage();
            }

            if ($pageError === '') {
                $db->beginTransaction();
                $lock = $db->prepare('SELECT record_type, status FROM documents WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
                $currentDocument = $lock->fetch();
                if (
                    !$currentDocument
                    || $currentDocument['record_type'] !== 'draft'
                    || !in_array($currentDocument['status'], ['Draft', 'Needs Revision'], true)
                ) {
                    $db->rollBack();
                    $pageError = 'This record is no longer an editable draft.';
                } else {
                    $save = $db->prepare('UPDATE documents SET draft_content = ?, updated_at = NOW() WHERE id = ?');
                    $save->execute([$submittedDraftContent, $id]);
                    logActivity($id, 'document.draft_content_saved', [
                        'character_count' => mb_strlen(strip_tags($submittedDraftContent), 'UTF-8'),
                    ]);
                    $db->commit();
                    header('Location: document.php?id=' . $id . '&saved=draft');
                    exit;
                }
            }
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
                        'UPDATE documents SET file_name = ?, stored_path = ?, status = ?, reviewed_by = NULL, ocr_status = ?, ocr_text = ?, draft_content = IF(record_type = ?, ?, draft_content), document_number = COALESCE(?, document_number), page_number = COALESCE(?, page_number), book_number = COALESCE(?, book_number), series_year = COALESCE(?, series_year), date_notarized = COALESCE(?, date_notarized), updated_at = NOW() WHERE id = ?'
                    );
                    $update->execute([
                        $originalName,
                        $targetPath,
                        'Under Review',
                        ocrStatusLabel($ocr),
                        $ocr['text'] ?? '',
                        'draft',
                        plainTextToRichText((string)($ocr['text'] ?? '')),
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

$storedPath = $document['stored_path'] ?? '';
$previewExtension = $storedPath !== '' ? strtolower(pathinfo($storedPath, PATHINFO_EXTENSION)) : '';
$recordType = $document['record_type'] ?? 'archive_record';
$canEditDraft = $recordType === 'draft'
    && currentRole() === ROLE_STAFF
    && in_array($document['status'], ['Draft', 'Needs Revision'], true);
$draftContent = $submittedDraftContent ?? (string)($document['draft_content'] ?? '');
if ($recordType === 'draft' && $draftContent === '' && !empty($document['ocr_text'])) {
    $draftContent = plainTextToRichText((string)$document['ocr_text']);
}
if ($recordType === 'draft') {
    $draftContent = sanitizeRichText($draftContent);
}
?>
<?php renderAppStart($document['title'], currentRole() === ROLE_ATTORNEY ? 'review' : 'dashboard', 'Review document details, protected previews, and workflow actions.'); ?>
<?php if ($pageError !== '') : ?>
    <div class="error-box" role="alert"><?= htmlspecialchars($pageError) ?></div>
<?php elseif (($_GET['saved'] ?? '') === 'review') : ?>
    <div class="success-box" role="status">Review decision saved.</div>
<?php elseif (($_GET['saved'] ?? '') === 'draft') : ?>
    <div class="success-box" role="status">Draft saved. Staff and Attorney dashboards now show the same saved content.</div>
<?php elseif (($_GET['saved'] ?? '') === 'version') : ?>
    <div class="success-box" role="status">New version uploaded and returned to the attorney review queue.</div>
<?php endif; ?>

<section class="panel document-record-panel">
    <div class="panel-header">
        <div>
            <p class="eyebrow">Document overview</p>
            <h2>Record details</h2>
        </div>
        <span class="status-badge <?= appStatusClass($document['status']) ?>"><?= htmlspecialchars($document['status']) ?></span>
    </div>
                <nav class="document-section-nav" aria-label="Document sections">
                    <a href="#document-overview">Overview</a>
                    <?php if ($recordType === 'draft') : ?>
                        <a href="#draft-editor">Draft content</a>
                    <?php endif; ?>
                    <?php if ($storedPath !== '' && is_file($storedPath)) : ?>
                        <a href="#document-preview">Preview</a>
                    <?php endif; ?>
                    <a href="#document-versions">Versions</a>
                    <a href="#document-version-upload">Upload version</a>
                    <?php if (currentRole() === ROLE_ATTORNEY) : ?>
                        <a href="#attorney-review">Attorney review</a>
                    <?php endif; ?>
                    <a href="#review-history">Review history</a>
                </nav>
                <div class="detail-grid" id="document-overview">
                    <div><strong>Category</strong><p><?= htmlspecialchars($document['category']) ?></p></div>
                    <div><strong>Record Type</strong><p><?= $recordType === 'draft' ? 'Draft document' : 'Scanned archive record' ?></p></div>
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

                <?php if ($recordType === 'draft') : ?>
                    <section class="panel draft-editor-panel" id="draft-editor">
                        <div class="panel-header">
                            <div>
                                <p class="eyebrow">Built-in text editor</p>
                                <h2>Draft content</h2>
                            </div>
                            <span class="role-pill"><?= $canEditDraft ? 'Editing enabled' : 'Read-only' ?></span>
                        </div>
                        <?php if ($canEditDraft) : ?>
                            <form method="post" class="draft-editor-form" data-draft-editor>
                                <input type="hidden" name="action" value="save_draft">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['draft_editor_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="editor-toolbar" role="toolbar" aria-label="Draft formatting">
                                    <button type="button" data-command="bold" aria-label="Bold"><strong>B</strong></button>
                                    <button type="button" data-command="italic" aria-label="Italic"><em>I</em></button>
                                    <button type="button" data-command="underline" aria-label="Underline"><u>U</u></button>
                                    <span class="toolbar-divider" aria-hidden="true"></span>
                                    <select data-block-format aria-label="Text style">
                                        <option value="p">Paragraph</option>
                                        <option value="h1">Heading 1</option>
                                        <option value="h2">Heading 2</option>
                                        <option value="h3">Heading 3</option>
                                    </select>
                                    <button type="button" data-command="insertUnorderedList" aria-label="Bulleted list">Bullets</button>
                                    <button type="button" data-command="insertOrderedList" aria-label="Numbered list">Numbering</button>
                                    <span class="toolbar-divider" aria-hidden="true"></span>
                                    <button type="button" data-command="justifyLeft" aria-label="Align left">Left</button>
                                    <button type="button" data-command="justifyCenter" aria-label="Center text">Center</button>
                                    <button type="button" data-command="justifyRight" aria-label="Align right">Right</button>
                                    <span class="toolbar-divider" aria-hidden="true"></span>
                                    <button type="button" data-command="undo" aria-label="Undo">Undo</button>
                                    <button type="button" data-command="redo" aria-label="Redo">Redo</button>
                                </div>
                                <div
                                    class="rich-text-surface"
                                    contenteditable="true"
                                    role="textbox"
                                    aria-label="Editable draft content"
                                    aria-multiline="true"
                                    spellcheck="true"
                                    data-rich-text-surface
                                ><?= $draftContent !== '' ? $draftContent : '<p><br></p>' ?></div>
                                <input type="hidden" name="draft_content" value="">
                                <div class="editor-footer">
                                    <span>Saved content is available to Staff and Attorney from their separate dashboards.</span>
                                    <div class="editor-actions">
                                        <button type="button" class="secondary-btn" data-print-draft>Print draft</button>
                                        <button type="submit" class="primary-btn">Save draft</button>
                                    </div>
                                </div>
                            </form>
                        <?php elseif ($draftContent !== '') : ?>
                            <div class="rich-text-readonly"><?= $draftContent ?></div>
                        <?php else : ?>
                            <p class="muted">No draft content has been entered yet.</p>
                        <?php endif; ?>
                        <?php if (!$canEditDraft) : ?>
                            <p class="field-hint">Only Staff can edit a Draft or a document returned as Needs Revision. Scanned archive records remain read-only.</p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($storedPath !== '' && is_file($storedPath)) : ?>
                    <section class="preview-panel document-preview-panel" id="document-preview">
                        <div class="section-heading">
                            <div>
                                <p class="eyebrow">Protected original</p>
                                <h3>Document Preview</h3>
                            </div>
                            <span class="role-pill">No source-file download</span>
                        </div>
                        <?php if ($previewExtension === 'pdf') : ?>
                            <div class="pdf-page-preview" data-pdf-preview data-document-id="<?= $id ?>">
                                <div class="pdf-preview-toolbar">
                                    <button type="button" class="secondary-btn" data-page-previous disabled>Previous page</button>
                                    <span data-page-indicator>Preparing page preview...</span>
                                    <button type="button" class="secondary-btn" data-page-next disabled>Next page</button>
                                </div>
                                <div class="pdf-preview-canvas">
                                    <img alt="PDF page preview" data-page-image>
                                </div>
                                <p class="preview-message" role="status" data-preview-message>Rendering one page from the protected source file.</p>
                            </div>
                        <?php else : ?>
                            <p class="field-hint">The source file remains on the server. This read-only text view uses the text extracted during OCR.</p>
                            <pre class="file-preview"><?= htmlspecialchars($document['ocr_text'] ?: 'No extracted text is available for this file.') ?></pre>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <div class="document-support-grid">
                    <section class="history-section" id="document-versions">
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
                                        <span class="read-only-note">Stored in the protected archive; source files are not downloadable.</span>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <form method="post" enctype="multipart/form-data" class="review-form" id="document-version-upload">
                        <input type="hidden" name="action" value="version">
                        <h3>Upload New Version</h3>
                        <label for="document_file">Replacement or updated document</label>
                        <input id="document_file" name="document_file" type="file" accept=".pdf,.doc,.docx,.txt" required>
                        <label for="change_note">Version note</label>
                        <textarea id="change_note" name="change_note" rows="3" placeholder="Describe the change or scan batch"></textarea>
                        <button type="submit" class="secondary-btn">Upload Version</button>
                    </form>

                    <?php if (currentRole() === ROLE_ATTORNEY) : ?>
                        <form method="post" class="review-form" id="attorney-review">
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

                    <section class="history-section" id="review-history">
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
                </div>

</section>
<script src="assets/document-tools.js" defer></script>
<?php renderAppEnd(); ?>
