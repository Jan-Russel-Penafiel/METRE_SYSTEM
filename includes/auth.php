<?php

require_once __DIR__ . '/functions.php';

function current_user()
{
    return $_SESSION['auth_user'] ?? null;
}

function login_user($user)
{
    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'username' => $user['username'],
        'user_type' => $user['user_type'],
        'vehicle_type' => $user['vehicle_type'] ?? 'Standard Taxi',
    ];
}

function attempt_login($username, $password)
{
    $user = find_user_by_username($username, true);

    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    login_user($user);
    return true;
}

function require_login($roles = [])
{
    $user = current_user();

    if (!$user) {
        set_flash('Please log in to continue.', 'error');
        redirect_to('login.php');
    }

    if (!$roles) {
        return $user;
    }

    $roles = (array) $roles;

    if (!in_array($user['user_type'], $roles, true)) {
        set_flash('You do not have permission to access that page.', 'error');
        redirect_to($user['user_type'] === 'admin' ? 'admin/index.php' : 'meter.php');
    }

    return $user;
}

function logout_user()
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}
