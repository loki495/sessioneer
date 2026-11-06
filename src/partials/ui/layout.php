<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<?php /* viewport-fit=cover is what makes env(safe-area-inset-*) non-zero in the iOS home-screen app. */ ?>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $this->e($title) ?></title>
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" href="/icons/icon-192.png">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="Sessioneer">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="color-scheme" content="light dark">
<?php /* Runs before first paint so a dark-mode user never sees a light flash. The choice is 'system' (default), 'light' or 'dark'. */ ?>
<script>
(function () {
  var pref = 'system';
  try { pref = localStorage.getItem('v2-theme') || 'system'; } catch (e) {}
  var dark = pref === 'dark' || (pref === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
})();
</script>
<link rel="stylesheet" href="<?= \App\Assets::versioned_url('/css/ui.css') ?>">
<?= $this->section('head') ?>
</head>
<body>
<?= $this->section('content') ?>
</body>
</html>
