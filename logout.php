<?php

require_once __DIR__ . '/includes/auth.php';

unset($_SESSION['auth_user']);
set_flash('You have been logged out.', 'success');
redirect_to('login.php');
