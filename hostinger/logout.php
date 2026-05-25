<?php
// ============================================================
// logout.php — Destroy session and redirect to login
// ============================================================

define('CRM_APP', true);
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

crmLog('info', 'auth', 'User logged out', ['ip' => getClientIp()]);
destroySession();

header('Location: /login.php?logged_out=1');
exit;
