<?php
require_once __DIR__ . '/db.php';
ensureSchema();

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jumamil Law System</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="landing-page">
    <div class="landing-shell">
        <div class="landing-card">
            <p class="eyebrow">Legal Document Workflow</p>
            <h1>Jumamil Law System</h1>
            <p class="lead">A web-based legal document archiving, review, and tracking system for staff and attorneys.</p>
            <div class="landing-actions">
                <a class="primary-btn" href="login.php">Open Login</a>
                <a class="secondary-btn" href="Staff/index.php">Staff Portal</a>
                <a class="secondary-btn" href="Attorney/index.php">Attorney Portal</a>
            </div>
            <div class="demo-box">
                <strong>Demo accounts</strong>
                <ul>
                    <li>Staff: staff@jumamil.local / staff123</li>
                    <li>Attorney: attorney@jumamil.local / attorney123</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
