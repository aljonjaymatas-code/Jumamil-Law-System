<?php
require_once __DIR__ . '/db.php';
requireLogin();

function previewPageError(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo $message;
    exit;
}

$documentId = (int)($_GET['id'] ?? 0);
$pageNumber = (int)($_GET['page'] ?? 1);
if ($documentId < 1 || $pageNumber < 1) {
    previewPageError(400, 'Invalid document preview request.');
}

$stmt = openDb()->prepare('SELECT stored_path, file_name FROM documents WHERE id = ?');
$stmt->execute([$documentId]);
$document = $stmt->fetch();
if (!$document || strtolower(pathinfo((string)$document['file_name'], PATHINFO_EXTENSION)) !== 'pdf') {
    previewPageError(404, 'PDF preview is not available for this record.');
}

$uploadRoot = realpath(UPLOAD_DIR);
$filePath = realpath((string)$document['stored_path']);
if ($uploadRoot === false || $filePath === false || !is_file($filePath)) {
    previewPageError(404, 'The stored document could not be found.');
}
if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'pdf') {
    previewPageError(415, 'Only scanned PDF pages can be rendered in this preview.');
}

$rootPrefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (stripos($filePath, $rootPrefix) !== 0) {
    previewPageError(403, 'The stored document is outside the protected archive.');
}

$tempBase = tempnam(sys_get_temp_dir(), 'jl-preview-');
if ($tempBase === false) {
    previewPageError(500, 'The page preview could not be prepared.');
}
$outputPath = $tempBase . '.jpg';
$command = escapeshellarg(OCR_PYTHON) . ' '
    . escapeshellarg(PDF_PREVIEW_SCRIPT) . ' '
    . escapeshellarg($filePath) . ' '
    . escapeshellarg((string)$pageNumber) . ' '
    . escapeshellarg($outputPath);
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);

if (!is_resource($process)) {
    unlink($tempBase);
    previewPageError(503, 'The PDF preview service could not be started.');
}

fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$errorOutput = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
$result = json_decode((string)$output, true);

if (
    $exitCode !== 0
    || !is_array($result)
    || empty($result['page_count'])
    || !is_file($outputPath)
) {
    if ($errorOutput !== '') {
        error_log('PDF page preview failed for document ' . $documentId . '.');
    }
    if (is_file($tempBase)) {
        unlink($tempBase);
    }
    if (is_file($outputPath)) {
        unlink($outputPath);
    }
    previewPageError(422, 'This PDF page could not be rendered.');
}

$imageSize = filesize($outputPath);
if ($imageSize === false || $imageSize > 12000000) {
    unlink($tempBase);
    unlink($outputPath);
    previewPageError(422, 'The rendered PDF page is too large to preview.');
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . $imageSize);
header('X-Document-Page-Count: ' . (int)$result['page_count']);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($outputPath);
unlink($tempBase);
unlink($outputPath);
