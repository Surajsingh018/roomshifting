<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
if (!empty($_SESSION['user_id'])) {
  header('Location: dashboard.php');
  exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf();
  $username = trim((string)($_POST['username'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  $rateKey = getenv('ROOMSHIFT_RATE_LIMIT_KEY') ?: hash('sha256', __DIR__ . php_uname());
  $ipHash = hash_hmac('sha256', $ip, $rateKey);
  $count = $pdo->prepare('SELECT COUNT(*) AS total FROM login_attempts WHERE ip_hash=? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
  $count->execute([$ipHash]);
  if ((int)$count->fetchColumn() >= 10) {
    http_response_code(429);
    $error = 'Too many sign-in attempts. Please try again later.';
  } else {
    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, is_active FROM users WHERE username=? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && (int)$user['is_active'] === 1 && roomshift_password_allowed_for_environment($password) && password_verify($password, $user['password_hash'])) {
      $clear = $pdo->prepare('DELETE FROM login_attempts WHERE ip_hash=?');
      $clear->execute([$ipHash]);
      session_regenerate_id(true);
      $_SESSION['user_id'] = (int)$user['id'];
      $_SESSION['username'] = $user['username'];
      $_SESSION['role'] = $user['role'];
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      $update = $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?');
      $update->execute([(int)$user['id']]);
      header('Location: dashboard.php');
      exit;
    }
    $attempt = $pdo->prepare('INSERT INTO login_attempts (ip_hash) VALUES (?)');
    $attempt->execute([$ipHash]);
    $error = 'Username or password is incorrect.';
  }
}
$csrf = admin_csrf_token();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin sign in | RoomShift</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="../website/css/style.css" rel="stylesheet">
</head>

<body class="bg-ivory">
  <main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <section class="card shadow-sm border-0 w-100" style="max-width:440px">
      <div class="card-body p-4 p-md-5">
        <p class="text-uppercase small fw-semibold text-secondary mb-2">RoomShift Admin</p>
        <h1 class="h3 mb-4">Sign in</h1>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post" action="login.php" autocomplete="on">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <label class="form-label" for="username">Username</label>
          <input class="form-control mb-3" id="username" name="username" autocomplete="username" maxlength="50" required>
          <label class="form-label" for="password">Password</label>
          <input class="form-control mb-4" id="password" name="password" type="password" autocomplete="current-password" required>
          <button class="btn btn-gold w-100" type="submit">Sign in securely</button>
        </form>
      </div>
    </section>
  </main>
</body>

</html>