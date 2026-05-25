<?php
// ============================================================
// index.php — Entry point / redirect handler
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

if (isAuthenticated()) {
    header('Location: /dashboard.php');
} else {
    header('Location: /login.php');
}
exit;
