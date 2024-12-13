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
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        return true;
    }
    return false;
}

function logout() {
    session_unset();
    session_destroy();
}
