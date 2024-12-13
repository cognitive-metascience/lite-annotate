<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

try {
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create database
    $sql = "CREATE DATABASE IF NOT EXISTS $database";
    $pdo->exec($sql);
    echo "Database created successfully<br>";

    // Use the new database
    $pdo->exec("USE $database");

    // Create users table
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE,
        password VARCHAR(255),
        role ENUM('annotator', 'superannotator')
    )";
    $pdo->exec($sql);
    echo "Users table created successfully<br>";

    // Create projects table
    $sql = "CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        instructions TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "Projects table created successfully<br>";

    // Create snippets table
    $sql = "CREATE TABLE IF NOT EXISTS snippets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT,
        content TEXT,
        highlight VARCHAR(255),
        FOREIGN KEY (project_id) REFERENCES projects(id)
    )";
    $pdo->exec($sql);
    echo "Snippets table created successfully<br>";

    // Create annotations table
    $sql = "CREATE TABLE IF NOT EXISTS annotations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snippet_id INT,
        user_id INT,
        decision BOOLEAN,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (snippet_id) REFERENCES snippets(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )";
    $pdo->exec($sql);
    echo "Annotations table created successfully<br>";

    // Create final_decisions table
    $sql = "CREATE TABLE IF NOT EXISTS final_decisions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snippet_id INT,
        decision BOOLEAN,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (snippet_id) REFERENCES snippets(id)
    )";
    $pdo->exec($sql);
    echo "Final decisions table created successfully<br>";
	
	 // Create user_projects table
    $sql = "CREATE TABLE IF NOT EXISTS user_projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        project_id INT,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (project_id) REFERENCES projects(id),
        UNIQUE KEY (user_id, project_id)
    )";
    $pdo->exec($sql);
    echo "User projects table created successfully<br>";


    $adminPassword = createDefaultAdminAccount();
    
    if ($adminPassword) {
        echo "Default admin account created. Username: admin, Password: $adminPassword<br>";
        echo "Please change this password immediately after first login.<br>";
    } else {
        echo "Admin account already exists. No default account created.<br>";
    }

} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}
