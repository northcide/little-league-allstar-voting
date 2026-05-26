<?php
// Allstar — SPA shell. All logic lives in /js/app.js; this file just paints the chrome.
require_once __DIR__ . '/api/security_headers.php';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <meta name="theme-color" content="#0F1B33">
  <title>Allstar — Anonymous Coach Voting</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Source+Serif+4:ital,wght@0,400;0,600;0,700;1,400&display=swap">
  <link rel="stylesheet" href="css/app.css?v=<?= filemtime(__DIR__ . '/css/app.css') ?>">
</head>
<body>
  <div id="app">
    <div id="topbar">
      <div class="topbar-inner">
        <h1 class="brand"><span class="brand-icon">⚾</span> Allstar</h1>
        <div id="topbar-context"></div>
        <div id="topbar-actions"></div>
      </div>
    </div>
    <main id="main">
      <div class="loading">Loading…</div>
    </main>
    <div id="toast"></div>
  </div>
  <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
</body>
</html>
