<?php
require_once __DIR__ . '/db.php';
ensureSchema();
requireLogin();

$db = openDb();
$clients = getClientOptions();
$uploadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? 'Untitled Document');
    $category = trim($_POST['category'] ?? 'Affidavit');
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

    if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
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
                    'INSERT INTO documents (client_id, title, category, client_name, document_number, page_number, book_number, series_year, date_notarized, file_name, stored_path, status, notes, ocr_status, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                );
                $stmt->execute([
                    $clientId,
                    $title,
                    $category,
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
                    'UPDATE documents SET client_id = ?, client_name = ?, document_number = ?, page_number = ?, book_number = ?, series_year = ?, date_notarized = ?, ocr_status = ?, ocr_text = ?, updated_at = NOW() WHERE id = ?'
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
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Document</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="page-shell narrow">
        <aside class="sidebar">
            <div class="brand">Jumamil Law</div>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a class="active" href="upload.php">Upload Document</a>
                <a href="logout.php">Logout</a>
            </nav>
        </aside>

        <main class="main-panel">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Document Intake</p>
                    <h2>Upload legal file</h2>
                </div>
            </header>

            <?php if (!empty($uploadError)) : ?>
                <div class="error-box"><?= htmlspecialchars($uploadError) ?></div>
            <?php endif; ?>

            <section class="panel form-panel">
                <form method="post" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div>
                            <label for="title">Document Title</label>
                            <input id="title" name="title" type="text" required>
                        </div>
                        <div>
                            <label for="category">Category</label>
                            <select id="category" name="category">
                                <option value="Affidavit">Affidavit</option>
                                <option value="Special Power of Attorney">Special Power of Attorney</option>
                                <option value="Deed of Sale">Deed of Sale</option>
                                <option value="Pleading">Pleading</option>
                                <option value="Contract">Contract</option>
                            </select>
                        </div>
                        <div>
                            <label for="client_id">Existing Client</label>
                            <select id="client_id" name="client_id">
                                <option value="">Create new client</option>
                                <?php foreach ($clients as $client) : ?>
                                    <option value="<?= (int)$client['id'] ?>"><?= htmlspecialchars($client['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="client_name">Client Name</label>
                            <input id="client_name" name="client_name" type="text" placeholder="New client or override current selection">
                        </div>
                        <div>
                            <label for="document_number">Document Number</label>
                            <input id="document_number" name="document_number" type="text">
                        </div>
                        <div>
                            <label for="page_number">Page Number</label>
                            <input id="page_number" name="page_number" type="text">
                        </div>
                        <div>
                            <label for="book_number">Book Number</label>
                            <input id="book_number" name="book_number" type="text">
                        </div>
                        <div>
                            <label for="series_year">Series Year</label>
                            <input id="series_year" name="series_year" type="text">
                        </div>
                        <div>
                            <label for="date_notarized">Date Notarized</label>
                            <input id="date_notarized" name="date_notarized" type="date">
                        </div>
                        <div class="full-span">
                            <label for="document_file">File</label>
                            <input id="document_file" name="document_file" type="file" accept=".pdf,.doc,.docx,.txt" required>
                        </div>
                        <div class="full-span">
                            <label for="notes">Internal Notes</label>
                            <textarea id="notes" name="notes" rows="4"></textarea>
                        </div>
                    </div>

                    <div class="button-row">
                        <button type="submit" class="primary-btn">Save Document</button>
                        <a class="secondary-btn" href="dashboard.php">Cancel</a>
                    </div>
                </form>
            </section>
        </main>
    </div>
</body>
</html>
