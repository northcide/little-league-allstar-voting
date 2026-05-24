<?php
// Allstar — SPA shell. All logic lives in /js/app.js; this file just paints the chrome.
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1e3a5f">
  <title>Allstar — Anonymous Coach Voting</title>
  <link rel="stylesheet" href="css/app.css?v=1">
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
  <script src="js/app.js?v=1"></script>
</body>
</html>
