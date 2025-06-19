<?php

function requireLogin()
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }
}

function requireRole($required_role)
{
    requireLogin();

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $required_role) {
        $_SESSION['access_denied'] = "Anda tidak memiliki akses untuk halaman tersebut.";
        header("Location: dashboard.php");
        exit();
    }
}

function requireSuperAdmin()
{
    requireRole('super_admin');
}

function isSuperAdmin()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

function isAdmin()
{
    return isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin');
}

function hasPermission($permission)
{
    switch ($permission) {
        case 'manage_users':
            return isSuperAdmin();
        case 'manage_data':
            return isAdmin();
        default:
            return false;
    }
}
?>