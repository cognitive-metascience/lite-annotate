<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAnnotator() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'annotator';
}

function isSuperannotator() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'superannotator';
}

function login($username, $password) {
    global $pdo;

    if (loginRateLimited($username)) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        clearLoginAttempts($username);
        return true;
    }

    recordLoginAttempt($username);
    return false;
}

function logout() {
    session_unset();
    session_destroy();
}

// --- CSRF protection ---

function csrfGenerate() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . csrfGenerate() . '">';
}

function csrfCheck() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals(csrfGenerate(), $token);
}

// --- Brute-force rate limiting ---

function loginRateLimited($username) {
    $key = 'login_attempts_' . $username;
    $attempts = $_SESSION[$key] ?? [];

    // Purge attempts older than 15 minutes
    $window = time() - 900;
    $attempts = array_filter($attempts, function ($ts) use ($window) {
        return $ts > $window;
    });

    $_SESSION[$key] = $attempts;

    // Allow max 5 attempts per 15-min window
    return count($attempts) >= 5;
}

function recordLoginAttempt($username) {
    $key = 'login_attempts_' . $username;
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }
    $_SESSION[$key][] = time();
}

function clearLoginAttempts($username) {
    unset($_SESSION['login_attempts_' . $username]);
}
