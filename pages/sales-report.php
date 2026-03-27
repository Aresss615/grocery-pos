<?php
/**
 * Redirects to reports.php — replaced by the new tabbed reports page.
 */
session_start();
require_once __DIR__ . '/../config/constants.php';
header('Location: ' . BASE_URL . '/pages/reports.php', true, 301);
exit();
