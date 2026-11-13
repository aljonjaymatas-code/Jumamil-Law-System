<?php
session_start();
date_default_timezone_set('Asia/Manila');

const APP_NAME = 'Jumamil Law System';
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'jumamil_law_system';
const DB_USER = 'root';
const DB_PASS = '';
const UPLOAD_DIR = __DIR__ . '/uploads';
const OCR_SCRIPT = __DIR__ . '/scripts/ocr_worker.py';
const OCR_PYTHON = 'python';
const ROLE_STAFF = 'staff';
const ROLE_ATTORNEY = 'attorney';

function dbDsn(): string
{
    return 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
}

function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function userName(): string
{
    return $_SESSION['user_name'] ?? 'User';
}

function currentRole(): string
{
    return $_SESSION['role'] ?? '';
}
