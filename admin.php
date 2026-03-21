<?php

require_once __DIR__ . '/includes/auth.php';

$user = require_login(['admin']);
redirect_to('admin/index.php');
