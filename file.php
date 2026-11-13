<?php
require_once __DIR__ . '/db.php';
ensureSchema();
requireLogin();

$documentId = (int)($_GET['id'] ?? 0);
$versionId = (int)($_GET['version'] ?? 0);
$db = openDb();

if ($versionId > 0) {
    $stmt = $db->prepare('SELECT file_name, stored_path FROM document_versions WHERE id = ? AND document_id = ?');
} else {
    $stmt = $db->prepare('SELECT file_name, stored_path FROM documents WHERE id = ?');
}
$stmt->execute($versionId > 0 ? [$versionId, $documentId] : [$documentId]);
$file = $stmt->fetch();

if (!$file || empty($file['stored_path'])) {
    http_response_code(404);
    exit('File not found.');
}

$uploadRoot = realpath(UPLOAD_DIR);
$filePath = realpath($file['stored_path']);
if ($uploadRoot === false || $filePath === false || !is_file($filePath)) {
    http_response_code(404);
    exit('File not found.');
}

$rootPrefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (stripos($filePath, $rootPrefix) !== 0) {
    http_response_code(403);
    exit('Access denied.');
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath) ?: 'application/octet-stream';
$isPdf = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf';
$disposition = $isPdf ? 'inline' : 'attachment';
$downloadName = preg_replace('/[\r\n"]/', '', basename($file['file_name']));
logActivity($documentId, 'document.file_accessed', [
    'file_name' => $file['file_name'],
    'version_id' => $versionId > 0 ? $versionId : null,
]);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($filePath);
