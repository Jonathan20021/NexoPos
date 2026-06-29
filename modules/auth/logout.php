<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!isPost()) {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
verify_csrf();
logout_user();
redirect('modules/auth/login.php');
