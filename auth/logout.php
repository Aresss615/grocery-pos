<?php
/**
 * J&J Grocery POS - Logout Handler
 */

session_start();

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/helpers.php';

// Destroy session
session_destroy();

// Redirect to login with message
redirectWithMessage(BASE_URL . '/index.php', SUCCESS_LOGOUT, 'success');
