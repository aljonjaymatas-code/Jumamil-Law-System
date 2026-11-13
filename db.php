<?php
require_once __DIR__ . '/config.php';

function openDb(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    $db = new PDO(
        dbDsn(),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ]
    );

    return $db;
}

function ensureDatabase(): void
{
    $admin = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $admin->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

function ensureSchema(): void
{
    ensureDatabase();
    $db = openDb();

    $db->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('staff', 'attorney') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS clients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(200) NOT NULL,
            contact_number VARCHAR(50) NULL,
            email VARCHAR(150) NULL,
            address TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_client_name (full_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS documents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(120) NOT NULL,
            client_name VARCHAR(200) NULL,
            document_number VARCHAR(120) NULL,
            page_number VARCHAR(50) NULL,
            book_number VARCHAR(50) NULL,
            series_year VARCHAR(20) NULL,
            date_notarized DATE NULL,
            file_name VARCHAR(255) NULL,
            stored_path VARCHAR(500) NULL,
            status ENUM('Draft', 'Under Review', 'Needs Revision', 'Approved', 'Approved for Printing') NOT NULL DEFAULT 'Draft',
            notes TEXT NULL,
            ocr_status VARCHAR(100) NULL,
            ocr_text LONGTEXT NULL,
            created_by INT UNSIGNED NULL,
            reviewed_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_documents_client_id (client_id),
            KEY idx_documents_number (document_number),
            KEY idx_documents_status (status),
            CONSTRAINT fk_documents_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
            CONSTRAINT fk_documents_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_documents_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS document_reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id INT UNSIGNED NOT NULL,
            reviewer_id INT UNSIGNED NOT NULL,
            decision VARCHAR(50) NOT NULL,
            remarks TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_reviews_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            CONSTRAINT fk_reviews_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS document_versions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id INT UNSIGNED NOT NULL,
            version_number INT UNSIGNED NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(500) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            change_note TEXT NULL,
            uploaded_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_document_version (document_id, version_number),
            CONSTRAINT fk_versions_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            CONSTRAINT fk_versions_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            document_id INT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            details JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_activity_document_time (document_id, created_at),
            KEY idx_activity_user_time (user_id, created_at),
            CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_activity_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $statusColumn = $db->query("SHOW COLUMNS FROM documents LIKE 'status'")->fetch();
    if ($statusColumn && strpos((string)$statusColumn['Type'], 'Approved for Printing') === false) {
        $db->exec("ALTER TABLE documents MODIFY status ENUM('Draft', 'Under Review', 'Needs Revision', 'Approved', 'Approved for Printing') NOT NULL DEFAULT 'Draft'");
    }
    $db->exec("UPDATE documents SET status = 'Approved for Printing' WHERE status = 'Approved'");

    $triggerCheck = $db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
    foreach ([
        'activity_logs_no_update' => 'UPDATE',
        'activity_logs_no_delete' => 'DELETE',
    ] as $triggerName => $event) {
        $triggerCheck->execute([$triggerName]);
        if ((int)$triggerCheck->fetchColumn() === 0) {
            $db->exec("CREATE TRIGGER `{$triggerName}` BEFORE {$event} ON activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity log records are immutable'");
        }
    }

    $legacyFiles = $db->query(
        'SELECT d.id, d.file_name, d.stored_path, d.created_by
         FROM documents d
         LEFT JOIN document_versions v ON v.document_id = d.id
         WHERE v.id IS NULL AND d.stored_path IS NOT NULL'
    )->fetchAll();
    foreach ($legacyFiles as $legacyFile) {
        if (is_file($legacyFile['stored_path'])) {
            addDocumentVersion(
                (int)$legacyFile['id'],
                (string)$legacyFile['file_name'],
                (string)$legacyFile['stored_path'],
                $legacyFile['created_by'] !== null ? (int)$legacyFile['created_by'] : null,
                'Existing file (version history initialized)'
            );
        }
    }

    $seedUsers = [
        ['name' => 'Administrative Staff', 'email' => 'staff@jumamil.local', 'password' => 'staff123', 'role' => ROLE_STAFF],
        ['name' => 'Managing Attorney', 'email' => 'attorney@jumamil.local', 'password' => 'attorney123', 'role' => ROLE_ATTORNEY],
    ];

    $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = VALUES(role)');
    foreach ($seedUsers as $user) {
        $stmt->execute([
            $user['name'],
            $user['email'],
            password_hash($user['password'], PASSWORD_BCRYPT),
            $user['role'],
        ]);
    }
}

function getClientById(int $id): ?array
{
    $db = openDb();
    $stmt = $db->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    $client = $stmt->fetch();
    return $client ?: null;
}

function getClientOptions(): array
{
    $db = openDb();
    $stmt = $db->prepare('SELECT id, full_name, contact_number, email FROM clients ORDER BY full_name ASC');
    $stmt->execute();
    return $stmt->fetchAll();
}

function saveClient(array $data): int
{
    $db = openDb();
    $fullName = trim((string)($data['full_name'] ?? ''));
    if ($fullName === '') {
        throw new InvalidArgumentException('Client name is required.');
    }

    $existing = $db->prepare('SELECT id FROM clients WHERE full_name = ? LIMIT 1');
    $existing->execute([$fullName]);
    $row = $existing->fetch();
    if ($row) {
        $existingId = (int)$row['id'];
        $update = $db->prepare('UPDATE clients SET contact_number = ?, email = ?, address = ? WHERE id = ?');
        $update->execute([
            $data['contact_number'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $existingId,
        ]);
        return $existingId;
    }

    $stmt = $db->prepare('INSERT INTO clients (full_name, contact_number, email, address) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $fullName,
        $data['contact_number'] ?? null,
        $data['email'] ?? null,
        $data['address'] ?? null,
    ]);
    return (int)$db->lastInsertId();
}

function fetchDocuments(string $search = '', string $notarizedDate = '', string $status = ''): array
{
    $db = openDb();
    $conditions = [];
    $params = [];
    $term = trim($search);

    if ($term !== '') {
        $conditions[] = '(COALESCE(c.full_name, d.client_name) LIKE ? OR d.document_number LIKE ? OR d.title LIKE ? OR d.ocr_text LIKE ?)';
        $like = '%' . $term . '%';
        $params = [$like, $like, $like, $like];
    }

    if ($notarizedDate !== '') {
        $conditions[] = 'd.date_notarized = ?';
        $params[] = $notarizedDate;
    }

    $allowedStatuses = ['Draft', 'Under Review', 'Needs Revision', 'Approved', 'Approved for Printing'];
    if ($status !== '' && in_array($status, $allowedStatuses, true)) {
        $conditions[] = 'd.status = ?';
        $params[] = $status;
    }

    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $sql = 'SELECT d.*, u.name AS created_by_name, COALESCE(c.full_name, d.client_name) AS client_name
            FROM documents d
            LEFT JOIN clients c ON c.id = d.client_id
            LEFT JOIN users u ON u.id = d.created_by' . $where . '
            ORDER BY d.updated_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function logActivity(?int $documentId, string $action, array $details = []): void
{
    $db = openDb();
    $stmt = $db->prepare('INSERT INTO activity_logs (user_id, document_id, action, details) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        $documentId,
        $action,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function validDateFilter(string $date): string
{
    if ($date === '') {
        return '';
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date ? $date : '';
}

function addDocumentVersion(
    int $documentId,
    string $originalName,
    string $storedPath,
    ?int $uploadedBy,
    ?string $changeNote = null
): int {
    $db = openDb();
    $nextStmt = $db->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM document_versions WHERE document_id = ?');
    $nextStmt->execute([$documentId]);
    $versionNumber = (int)$nextStmt->fetchColumn();
    $fileSize = filesize($storedPath);
    $sha256 = hash_file('sha256', $storedPath);

    if ($fileSize === false || $sha256 === false) {
        throw new RuntimeException('Unable to verify the uploaded document version.');
    }

    $stmt = $db->prepare('INSERT INTO document_versions (document_id, version_number, file_name, stored_path, file_size, sha256, change_note, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $documentId,
        $versionNumber,
        $originalName,
        $storedPath,
        $fileSize,
        $sha256,
        $changeNote,
        $uploadedBy,
    ]);

    return $versionNumber;
}

function runOcrPipeline(string $filePath): array
{
    if (!is_file($filePath)) {
        return ['status' => 'failed', 'message' => 'File not found for OCR.'];
    }

    if (!is_file(OCR_SCRIPT)) {
        return ['status' => 'skipped', 'message' => 'OCR worker script is not available.'];
    }

    $cmd = escapeshellarg(OCR_PYTHON) . ' ' . escapeshellarg(OCR_SCRIPT) . ' ' . escapeshellarg($filePath);
    $process = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        return ['status' => 'failed', 'message' => 'Unable to start the OCR worker.'];
    }

    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $raw = trim((string)$raw);

    if ($raw === '') {
        return ['status' => 'failed', 'message' => 'OCR processing failed.', 'raw' => trim((string)$errorOutput)];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['status' => 'failed', 'message' => 'OCR output was not valid JSON.', 'raw' => $raw];
    }
    if ($code !== 0 && ($decoded['status'] ?? '') !== 'failed') {
        return ['status' => 'failed', 'message' => 'OCR worker exited unexpectedly.', 'raw' => trim((string)$errorOutput)];
    }

    return [
        'status' => $decoded['status'] ?? 'completed',
        'message' => $decoded['message'] ?? 'OCR completed.',
        'text' => (string)($decoded['text'] ?? ''),
        'document_number' => $decoded['document_number'] ?? null,
        'client_name' => $decoded['client_name'] ?? null,
        'page_number' => $decoded['page_number'] ?? null,
        'book_number' => $decoded['book_number'] ?? null,
        'series_year' => $decoded['series_year'] ?? null,
        'date_notarized' => $decoded['date_notarized'] ?? null,
    ];
}

function ocrStatusLabel(array $result): string
{
    $status = $result['status'] ?? 'failed';
    if ($status === 'completed') {
        return 'OCR completed';
    }
    if ($status === 'skipped') {
        return 'OCR skipped';
    }
    return 'OCR failed';
}

function authenticate(string $email, string $password, string $role): ?array
{
    $db = openDb();
    $stmt = $db->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = ? AND role = ?');
    $stmt->execute([$email, $role]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}
