<?php require_once __DIR__ . '/../includes/preferences.php'; ?>
<!DOCTYPE html>
<html lang="en"<?= themeAttribute() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= escapeHtml($pageTitle ?? 'Manage Services') ?> | Spa &amp; Beauty Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/management.css">
    <?php if (!empty($isAuthPage)): ?>
        <link rel="stylesheet" href="css/auth.css">
    <?php endif; ?>
</head>
<body>
    <?php require __DIR__ . '/header.php'; ?>
    <main id="main-content" class="container py-5 management-page" tabindex="-1">
