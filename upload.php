<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
ensureSchema();
requireLogin();

$db = openDb();
$clients = getClientOptions();
$uploadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? 'Untitled Document');
    $category = trim($_POST['category'] ?? 'Affidavit');
    $recordType = trim($_POST['record_type'] ?? 'archive_record');
    $clientName = trim($_POST['client_name'] ?? '');
    $selectedClientId = (int)($_POST['client_id'] ?? 0);
    $documentNumber = trim($_POST['document_number'] ?? '');
    $pageNumber = trim($_POST['page_number'] ?? '');
    $bookNumber = trim($_POST['book_number'] ?? '');
    $seriesYear = trim($_POST['series_year'] ?? '');
    $dateNotarized = trim($_POST['date_notarized'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($selectedClientId > 0) {
        $clientRecord = getClientById($selectedClientId);
        if ($clientRecord) {
            $clientName = (string)($clientRecord['full_name'] ?? $clientName);
        }
    }

    if (!in_array($recordType, ['archive_record', 'draft'], true)) {
        $uploadError = 'Select a valid record type.';
    } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'Please select a valid file to upload.';
    } else {
        $originalName = basename($_FILES['document_file']['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'txt'];

        if (!in_array($ext, $allowed, true)) {
            $uploadError = 'Only PDF, DOC, DOCX, and TXT files are allowed.';
        } else {
            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0777, true);
            }

            $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
            $targetPath = UPLOAD_DIR . '/' . $safeName;

            if (move_uploaded_file($_FILES['document_file']['tmp_name'], $targetPath)) {
                $clientId = null;
                if ($selectedClientId > 0) {
                    $clientId = $selectedClientId;
                } elseif ($clientName !== '') {
                    $clientId = saveClient(['full_name' => $clientName]);
                }

                $documentNumber = $documentNumber !== '' ? $documentNumber : 'AUTO-' . date('YmdHis');
                $stmt = $db->prepare(
                    'INSERT INTO documents (client_id, title, category, record_type, client_name, document_number, page_number, book_number, series_year, date_notarized, file_name, stored_path, status, notes, ocr_status, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                );
                $stmt->execute([
                    $clientId,
                    $title,
                    $category,
                    $recordType,
                    $clientName,
                    $documentNumber,
                    $pageNumber,
                    $bookNumber,
                    $seriesYear,
                    $dateNotarized !== '' ? $dateNotarized : null,
                    $originalName,
                    $targetPath,
                    'Draft',
                    $notes,
                    'OCR pending',
                    (int)$_SESSION['user_id'],
                ]);

                $documentId = (int)$db->lastInsertId();
                $versionNumber = addDocumentVersion(
                    $documentId,
                    $originalName,
                    $targetPath,
                    (int)$_SESSION['user_id'],
                    'Initial upload'
                );
                logActivity($documentId, 'document.uploaded', [
                    'file_name' => $originalName,
                    'version' => $versionNumber,
                ]);

                $ocr = runOcrPipeline($targetPath);
                $effectiveDocumentNumber = $documentNumber;
                $effectivePageNumber = $pageNumber;
                $effectiveBookNumber = $bookNumber;
                $effectiveSeriesYear = $seriesYear;
                $effectiveDate = $dateNotarized !== '' ? $dateNotarized : null;

                if (($ocr['status'] ?? '') === 'completed') {
                    if (!empty($ocr['document_number'])) {
                        $effectiveDocumentNumber = $ocr['document_number'];
                    }
                    if (!empty($ocr['page_number'])) {
                        $effectivePageNumber = $ocr['page_number'];
                    }
                    if (!empty($ocr['book_number'])) {
                        $effectiveBookNumber = $ocr['book_number'];
                    }
                    if (!empty($ocr['series_year'])) {
                        $effectiveSeriesYear = $ocr['series_year'];
                    }
                    if (!empty($ocr['date_notarized'])) {
                        $effectiveDate = $ocr['date_notarized'];
                    }
                    if (!empty($ocr['client_name']) && $clientName === '') {
                        $clientName = $ocr['client_name'];
                        if ($clientName !== '') {
                            $clientId = saveClient(['full_name' => $clientName]);
                        }
                    }
                }

                $update = $db->prepare(
                    'UPDATE documents SET client_id = ?, client_name = ?, document_number = ?, page_number = ?, book_number = ?, series_year = ?, date_notarized = ?, ocr_status = ?, ocr_text = ?, draft_content = ?, updated_at = NOW() WHERE id = ?'
                );
                $update->execute([
                    $clientId,
                    $clientName,
                    $effectiveDocumentNumber,
                    $effectivePageNumber !== '' ? $effectivePageNumber : null,
                    $effectiveBookNumber !== '' ? $effectiveBookNumber : null,
                    $effectiveSeriesYear !== '' ? $effectiveSeriesYear : null,
                    $effectiveDate,
                    ocrStatusLabel($ocr),
                    $ocr['text'] ?? '',
                    $recordType === 'draft' ? plainTextToRichText((string)($ocr['text'] ?? '')) : null,
                    $documentId,
                ]);
                logActivity($documentId, ($ocr['status'] ?? '') === 'completed' ? 'ocr.completed' : 'ocr.failed', [
                    'status' => $ocr['status'] ?? 'failed',
                    'message' => $ocr['message'] ?? '',
                    'metadata_fields_detected' => array_values(array_filter([
                        !empty($ocr['document_number']) ? 'document_number' : null,
                        !empty($ocr['page_number']) ? 'page_number' : null,
                        !empty($ocr['book_number']) ? 'book_number' : null,
                        !empty($ocr['series_year']) ? 'series_year' : null,
                        !empty($ocr['date_notarized']) ? 'date_notarized' : null,
                    ])),
                ]);

                header('Location: dashboard.php');
                exit;
            }

            $uploadError = 'The document could not be stored.';
        }
    }
}
?>
<?php renderAppStart('Upload a document', 'upload', 'Add a client-linked file to the archive. OCR will read available text and metadata after upload.', 'upload-view'); ?>
<?php if ($uploadError !== '') : ?>
    <div class="error-box" role="alert"><?= htmlspecialchars($uploadError) ?></div>
<?php endif; ?>

<section class="panel form-panel upload-panel">
    <div class="panel-header">
        <div>
            <p class="eyebrow">New archive record</p>
            <h2>Document details</h2>
        </div>
        <span class="role-pill">Staff intake</span>
    </div>
    <form method="post" enctype="multipart/form-data">
        <div class="form-grid">
            <div>
                <label for="title">Document title</label>
                <input id="title" name="title" type="text" placeholder="e.g. Affidavit of support" required>
            </div>
            <div>
                <label for="category">Document category</label>
                <select id="category" name="category">
                    <option value="Affidavit">Affidavit</option>
                    <option value="Special Power of Attorney">Special Power of Attorney</option>
                    <option value="Deed of Sale">Deed of Sale</option>
                    <option value="Pleading">Pleading</option>
                    <option value="Contract">Contract</option>
                </select>
            </div>
            <div>
                <label for="record_type">Record type</label>
                <select id="record_type" name="record_type" required>
                    <option value="archive_record" selected>Scanned archive record (read-only)</option>
                    <option value="draft">Draft for editing</option>
                </select>
                <small class="field-hint">Scanned files are read-only; drafts can be edited.</small>
            </div>
            <div>
                <label for="client_id">Select an existing client</label>
                <select id="client_id" name="client_id">
                    <option value="">Choose an existing client (optional)</option>
                    <?php foreach ($clients as $client) : ?>
                        <option value="<?= (int)$client['id'] ?>" data-client-name="<?= htmlspecialchars($client['full_name'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($client['full_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($client['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="field-hint">Open the list to view existing client names, or hover over a name for its full text.</small>
            </div>
            <div>
                <label for="client_name">Client name (existing or new)</label>
                <input id="client_name" name="client_name" type="text" list="existing-client-names" placeholder="Search or enter a client name">
                <datalist id="existing-client-names">
                    <?php foreach ($clients as $client) : ?>
                        <option value="<?= htmlspecialchars($client['full_name'], ENT_QUOTES, 'UTF-8') ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <small id="client-link-status" class="field-hint" aria-live="polite">Start typing to match an existing client or enter a new name.</small>
            </div>
            <div>
                <label for="document_number">Document number</label>
                <input id="document_number" name="document_number" type="text">
            </div>
            <div>
                <label for="date_notarized">Date notarized</label>
                <input id="date_notarized" name="date_notarized" type="date">
            </div>
            <div>
                <label for="page_number">Page number</label>
                <input id="page_number" name="page_number" type="text">
            </div>
            <div>
                <label for="book_number">Book number</label>
                <input id="book_number" name="book_number" type="text">
            </div>
            <div>
                <label for="series_year">Series year</label>
                <input id="series_year" name="series_year" type="text">
            </div>
            <div class="upload-notes-field">
                <label for="notes">Internal notes</label>
                <textarea id="notes" name="notes" rows="2" placeholder="Optional notes for the attorney review process"></textarea>
            </div>
            <div class="upload-file-field full-span">
                <label for="document_file">Document file</label>
                <input id="document_file" name="document_file" type="file" accept=".pdf,.doc,.docx,.txt" required>
                <small class="field-hint">Stored on the server; originals are not sent to workstations.</small>
            </div>
        </div>
        <div class="button-row">
            <button type="submit" class="primary-btn">Save and process document</button>
            <a class="secondary-btn" href="dashboard.php">Cancel</a>
        </div>
    </form>
</section>
<script>
(() => {
    const clientSelect = document.getElementById('client_id');
    const clientName = document.getElementById('client_name');
    const clientStatus = document.getElementById('client-link-status');

    clientSelect.addEventListener('change', () => {
        const selectedOption = clientSelect.selectedOptions[0];
        if (clientSelect.value !== '') {
            clientName.value = selectedOption.dataset.clientName || selectedOption.textContent.trim();
            clientStatus.textContent = `Existing client selected: ${clientName.value}`;
        } else {
            clientStatus.textContent = clientName.value.trim()
                ? 'This name will match an existing client if available; otherwise a new client record will be created.'
                : 'Start typing to match an existing client or enter a new name.';
        }
    });

    clientName.addEventListener('input', () => {
        const enteredName = clientName.value.trim().toLocaleLowerCase();
        const matchingOption = Array.from(clientSelect.options).find(option =>
            option.value !== '' && (option.dataset.clientName || option.textContent).trim().toLocaleLowerCase() === enteredName
        );

        clientSelect.value = matchingOption ? matchingOption.value : '';
        if (matchingOption) {
            clientStatus.textContent = `Existing client selected: ${matchingOption.dataset.clientName || matchingOption.textContent.trim()}`;
        } else if (enteredName !== '') {
            clientStatus.textContent = 'A client record will be created for this name.';
        } else {
            clientStatus.textContent = 'Start typing to match an existing client or enter a new name.';
        }
    });
})();
</script>
<?php renderAppEnd(); ?>
