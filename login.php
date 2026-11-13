<?php
require_once __DIR__ . '/db.php';
ensureSchema();

$selectedRole = $_GET['role'] ?? ROLE_STAFF;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? ROLE_STAFF;

    $user = authenticate($email, $password, $role);
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        header('Location: dashboard.php');
        exit;
    }

    $loginError = 'Invalid login details for the selected role.';
}

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
    <title>Login</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="auth-page">
    <div class="auth-card">
        <h1>Sign in</h1>
        <p>Access the Jumamil Law System</p>

        <?php if (!empty($loginError)) : ?>
            <div class="error-box"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="staff" <?= $selectedRole === ROLE_STAFF ? 'selected' : '' ?>>Staff</option>
                <option value="attorney" <?= $selectedRole === ROLE_ATTORNEY ? 'selected' : '' ?>>Attorney</option>
            </select>

            <label for="email">Email</label>
            <input id="email" name="email" type="email" placeholder="name@jumamil.local" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" placeholder="Enter password" required>

            <button type="submit" class="primary-btn">Login</button>
        </form>

        <div class="demo-box small">
            <strong>Demo accounts</strong>
            <ul>
                <li>Staff: staff@jumamil.local / staff123</li>
                <li>Attorney: attorney@jumamil.local / attorney123</li>
            </ul>
        </div>
    </div>
</body>
</html>
