<?php
$newPassword = 'new_password';
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
echo $hashedPassword;
?>