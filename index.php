<?php
session_start();
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if the user is logged in
if (isLoggedIn()) {
    // Redirect based on user role
    if (isSuperannotator()) {
        header('Location: public/admin.php');
    } else {
        header('Location: public/annotate.php');
    }
    exit();
} else {
    // If not logged in, redirect to login page
    header('Location: public/login.php');
    exit();
}
