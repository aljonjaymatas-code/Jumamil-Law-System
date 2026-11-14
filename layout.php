<?php
require_once __DIR__ . '/db.php';

function appEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function appStatusClass(string $status): string
{
    switch ($status) {
        case 'Approved':
        case 'Approved for Printing':
            return 'status-approved';
        case 'Needs Revision':
            return 'status-revision';
        case 'Under Review':
            return 'status-review';
        default:
            return 'status-draft';
    }
}

function renderAppStart(string $title, string $activePage, string $description, string $bodyClass = ''): void
{
    $role = currentRole();
    $roleLabel = $role === ROLE_ATTORNEY ? 'Attorney dashboard' : 'Staff dashboard';
    $search = (string)($_GET['search'] ?? '');
    $db = openDb();
    $counts = [
        'Under Review' => 0,
        'Needs Revision' => 0,
    ];
    $stmt = $db->query("SELECT status, COUNT(*) AS total FROM documents WHERE status IN ('Under Review', 'Needs Revision') GROUP BY status");
    foreach ($stmt as $row) {
        $counts[$row['status']] = (int)$row['total'];
    }

    $navigation = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php'],
        ['key' => 'upload', 'label' => 'Upload document', 'href' => 'upload.php'],
    ];
    if ($role === ROLE_ATTORNEY) {
        $navigation[] = ['key' => 'review', 'label' => 'Review queue', 'href' => 'documents.php'];
    }
    $navigation[] = ['key' => 'activity', 'label' => 'Activity Log', 'href' => 'activity-log.php'];
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= appEscape($title) ?> · <?= appEscape(APP_NAME) ?></title>
        <link rel="stylesheet" href="assets/styles.css">
    </head>
    <body class="workspace-body<?= $bodyClass !== '' ? ' ' . appEscape($bodyClass) : '' ?>">
        <div class="app-shell">
            <header class="app-header">
                <div class="header-inner">
                    <a class="brand-lockup" href="dashboard.php">
                        <span class="brand-mark" aria-hidden="true">JL</span>
                        <span class="brand-copy">
                            <strong>Jumamil Law</strong>
                            <small><?= appEscape($roleLabel) ?></small>
                        </span>
                    </a>

                    <form class="global-search" method="get" action="dashboard.php" role="search">
                        <label class="sr-only" for="global-search">Search client, document number, OCR text, or title</label>
                        <svg class="search-mark" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="10.8" cy="10.8" r="6.8"></circle>
                            <path d="m16 16 4.5 4.5"></path>
                        </svg>
                        <input id="global-search" name="search" type="search" value="<?= appEscape($search) ?>" placeholder="Search clients, document numbers, or OCR text">
                        <kbd>Enter</kbd>
                        <button class="global-search-submit" type="submit" aria-label="Search archive">
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="10.8" cy="10.8" r="6.8"></circle>
                                <path d="m16 16 4.5 4.5"></path>
                            </svg>
                        </button>
                    </form>

                    <div class="header-actions">
                        <details class="header-menu">
                            <summary class="icon-button notification-button" aria-label="Open work updates">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                                    <path d="M10 21h4"></path>
                                </svg>
                                <?php if ($role === ROLE_ATTORNEY && $counts['Under Review'] > 0) : ?>
                                    <span class="notification-count"><?= $counts['Under Review'] ?></span>
                                <?php elseif ($role === ROLE_STAFF && $counts['Needs Revision'] > 0) : ?>
                                    <span class="notification-count"><?= $counts['Needs Revision'] ?></span>
                                <?php endif; ?>
                            </summary>
                            <div class="header-popover">
                                <strong>Work updates</strong>
                                <?php if ($role === ROLE_ATTORNEY) : ?>
                                    <a href="documents.php?status=<?= rawurlencode('Under Review') ?>">
                                        <span>Documents awaiting review</span><b><?= $counts['Under Review'] ?></b>
                                    </a>
                                <?php else : ?>
                                    <a href="dashboard.php?status=<?= rawurlencode('Needs Revision') ?>">
                                        <span>Documents needing revision</span><b><?= $counts['Needs Revision'] ?></b>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </details>

                        <details class="header-menu profile-menu">
                            <summary class="profile-button">
                                <span class="profile-avatar" aria-hidden="true"><?= appEscape(strtoupper(substr(userName(), 0, 1))) ?></span>
                                <span class="profile-copy">
                                    <strong><?= appEscape(userName()) ?></strong>
                                    <small><?= appEscape(strtoupper($role)) ?></small>
                                </span>
                                <span class="chevron" aria-hidden="true">&#8964;</span>
                            </summary>
                            <div class="header-popover profile-popover">
                                <strong><?= appEscape(userName()) ?></strong>
                                <span><?= appEscape($roleLabel) ?></span>
                                <a href="logout.php">Sign out</a>
                            </div>
                        </details>
                    </div>
                </div>
            </header>

            <nav class="workspace-nav" aria-label="Main navigation">
                <div class="nav-inner">
                    <?php foreach ($navigation as $item) : ?>
                        <a class="<?= $activePage === $item['key'] ? 'active' : '' ?>" href="<?= appEscape($item['href']) ?>" <?= $activePage === $item['key'] ? 'aria-current="page"' : '' ?>>
                            <?= appEscape($item['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </nav>

            <main class="main-panel">
                <section class="page-heading">
                    <div>
                        <p class="eyebrow"><?= appEscape($roleLabel) ?></p>
                        <h1><?= appEscape($title) ?></h1>
                        <p><?= appEscape($description) ?></p>
                    </div>
                </section>
    <?php
}

function renderAppEnd(): void
{
    ?>
            </main>
            <footer class="app-footer">Jumamil Law System <span>·</span> Separate Staff and Attorney dashboards · Synchronized internal records</footer>
        </div>
    </body>
    </html>
    <?php
}
