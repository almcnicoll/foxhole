<?php

require_once __DIR__ . '/Store.php';

function startSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function isLoggedIn(): bool
{
    startSession();
    return !empty($_SESSION['authed']);
}

/** Redirects to login.php and exits if there's no active session. Call at the top of any protected page. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function login(): void
{
    startSession();
    session_regenerate_id(true);
    $_SESSION['authed'] = true;
}

function logout(): void
{
    startSession();
    $_SESSION = [];
    session_destroy();
}
