<?php
/**
 * Allstar setup — run once to initialise the database.
 * Visit http://your-server/allstar/api/setup.php in a browser.
 * Delete or restrict this file after setup is complete.
 */

if (file_exists(__DIR__ . '/config.php')) {
    http_response_code(404);
    ?><!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><title>Allstar Setup</title>
    <style>
      body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f0f2f5;
             display: flex; align-items: center; justify-content: center;
             min-height: 100vh; padding: 24px; }
      .card { background: #fff; border-radius: 10px;
              box-shadow: 0 4px 24px rgba(0,0,0,.1);
              padding: 36px 40px; width: 100%; max-width: 480px; }
      h1 { font-size: 22px; font-weight: 800; color: #1e3a5f; margin-bottom: 12px; }
      p  { font-size: 14px; color: #555; }
      code { background: #eef; padding: 2px 6px; border-radius: 4px; }
    </style></head><body>
    <div class="card">
      <h1>⚾ Allstar</h1>
      <p>Setup has already been completed. To reconfigure, remove
         <code>api/config.php</code> and reload this page.</p>
    </div></body></html><?php
    exit;
}

$error   = '';
$success = '';
$step    = isset($_POST['step']) ? (int)$_POST['step'] : 0;

if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host     = trim($_POST['db_host']     ?? 'localhost');
    $name     = trim($_POST['db_name']     ?? 'allstar');
    $user     = trim($_POST['db_user']     ?? '');
    $pass     =       $_POST['db_pass']    ?? '';
    $league   = trim($_POST['league_name'] ?? 'My Little League');
    $adminPin = trim($_POST['admin_pin']   ?? '');

    if (!$user)     $error = 'DB username is required.';
    elseif (!$adminPin || strlen($adminPin) < 4)
                    $error = 'Admin PIN must be at least 4 characters.';
    else {
        try {
            $pdo = new PDO(
                "mysql:host=$host;charset=utf8mb4",
                $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$name`");

            $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
            $schema = preg_replace('/^CREATE DATABASE.*?;\s*$/im', '', $schema);
            $schema = preg_replace('/^USE\s+.*?;\s*$/im', '', $schema);

            foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
                if ($stmt !== '') $pdo->exec($stmt);
            }

            $config = "<?php\n"
                    . "define('DB_HOST', " . var_export($host, true) . ");\n"
                    . "define('DB_NAME', " . var_export($name, true) . ");\n"
                    . "define('DB_USER', " . var_export($user, true) . ");\n"
                    . "define('DB_PASS', " . var_export($pass, true) . ");\n";
            file_put_contents(__DIR__ . '/config.php', $config);
            @chmod(__DIR__ . '/config.php', 0640);

            $stmt = $pdo->prepare(
                "INSERT INTO settings (`key`, value) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)"
            );
            $stmt->execute(['league_name', $league]);
            $stmt->execute(['admin_pin',   password_hash($adminPin, PASSWORD_DEFAULT)]);

            $success = 'Setup complete. <a href="../">Open Allstar</a>.';
        } catch (Exception $e) {
            $error = 'Setup failed: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<title>Allstar Setup</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
  .card { background: #fff; border-radius: 10px; box-shadow: 0 4px 24px rgba(0,0,0,.1); padding: 36px 40px; width: 100%; max-width: 480px; }
  h1 { font-size: 22px; font-weight: 800; color: #1e3a5f; margin-bottom: 6px; }
  p.sub { font-size: 13px; color: #666; margin-bottom: 28px; }
  label { display: block; font-size: 12px; font-weight: 700; color: #444; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; margin-top: 14px; }
  input { width: 100%; padding: 9px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
  input:focus { outline: none; border-color: #1a56db; }
  .sep { margin: 20px 0 6px; font-size: 11px; font-weight: 700; color: #999; text-transform: uppercase; letter-spacing: 1px; border-top: 1px solid #eee; padding-top: 16px; }
  button { width: 100%; margin-top: 24px; padding: 12px; background: #1e3a5f; color: #fff; border: none; border-radius: 6px; font-size: 15px; font-weight: 700; cursor: pointer; }
  button:hover { background: #162d4a; }
  .error   { background: #fee2e2; color: #991b1b; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
  .success { background: #dcfce7; color: #166534; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
  .success a { color: #166534; font-weight: 700; }
  small { font-weight: 400; text-transform: none; color: #888; }
</style></head><body>
<div class="card">
  <h1>⚾ Allstar Setup</h1>
  <p class="sub">Anonymous All-Star coach voting for your Little League.</p>

  <?php if ($error):   ?><div class="error"><?= $error ?></div><?php endif; ?>
  <?php if ($success): ?><div class="success"><?= $success ?></div><?php endif; ?>

  <?php if (!$success): ?>
  <form method="POST">
    <input type="hidden" name="step" value="1">

    <div class="sep">Database</div>
    <label>Host</label>
    <input name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" placeholder="localhost">
    <label>Database Name</label>
    <input name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'allstar') ?>" placeholder="allstar">
    <label>DB Username</label>
    <input name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" placeholder="root">
    <label>DB Password</label>
    <input type="password" name="db_pass" placeholder="(leave blank if none)">

    <div class="sep">League</div>
    <label>League Name</label>
    <input name="league_name" value="<?= htmlspecialchars($_POST['league_name'] ?? 'My Little League') ?>" placeholder="Springfield Little League">
    <label>Admin PIN <small>(controls all elections)</small></label>
    <input type="password" name="admin_pin" placeholder="Choose a PIN (4+ chars)">

    <button type="submit">Run Setup</button>
  </form>
  <?php endif; ?>
</div></body></html>
