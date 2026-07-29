<?php
/**
 * PHP QA site — entrypoint.
 * Works whether the host serves the repo root or a public/ docroot
 * (public/index.php just requires this file).
 */

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}

$status = class_exists(\App\App::class)
    ? \App\App::status()
    : ['app' => 'PHP QA Site', 'ok' => true, 'php_version' => PHP_VERSION, 'time' => date('c')];

// JSON health endpoint: /?health
if (isset($_GET['health'])) {
    header('Content-Type: application/json');
    echo json_encode($status, JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($status['app']) ?></title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 640px; margin: 4rem auto; padding: 0 1rem; }
    h1 { color: #4f46e5; }
    code { background: #f3f4f6; padding: .15rem .4rem; border-radius: 4px; }
    .ok { color: #16a34a; font-weight: 600; }
  </style>
</head>
<body>
  <h1><?= htmlspecialchars($status['app']) ?></h1>
  <p class="ok">&#10003; Deploy OK — PHP</p>
  <p>PHP version: <code><?= htmlspecialchars($status['php_version']) ?></code></p>
  <p>Deploy marker: <code><?= htmlspecialchars($status['deploy_marker'] ?? 'n/a') ?></code></p>
  <p>Node build ran: <code><?= !empty($status['build']['ran']) ? 'yes' : 'no' ?></code></p>
  <p>Server time: <code><?= htmlspecialchars($status['time']) ?></code></p>
  <p>Health JSON: <a href="?health">/?health</a></p>
</body>
</html>
