<?php
require_once __DIR__ . '/db.php';
requireLogin();

$documentId = (int)($_GET['id'] ?? 0);
if ($documentId < 1) {
    http_response_code(400);
    exit('Invalid document record.');
}

header('Location: document.php?id=' . $documentId . '#document-preview', true, 302);
exit;
