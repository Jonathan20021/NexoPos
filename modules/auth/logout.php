<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
logout_user();
redirect('modules/auth/login.php');
