<?php
require_once __DIR__ . '/app/bootstrap.php';
redirect(is_logged_in() ? 'modules/dashboard/index.php' : 'modules/auth/login.php');
