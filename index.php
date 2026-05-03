<?php
// /parent/index.php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
redirect(BASE_URL . '/login.php');
