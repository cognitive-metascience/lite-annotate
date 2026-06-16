<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

function columnExists($pdo, $database, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$database, $table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function indexExists($pdo, $database, $table, $index) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$database, $table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

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
        choice_schema TEXT,
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
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE KEY unique_annotation (snippet_id, user_id)
    )";
    $pdo->exec($sql);
    echo "Annotations table created successfully<br>";

    // Create final_decisions table
    $sql = "CREATE TABLE IF NOT EXISTS final_decisions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snippet_id INT,
        decision BOOLEAN,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (snippet_id) REFERENCES snippets(id),
        UNIQUE KEY unique_final_decision (snippet_id)
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

    if (!columnExists($pdo, $database, 'projects', 'choice_schema')) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN choice_schema TEXT AFTER instructions");
        echo "Projects choice schema column added successfully<br>";
    }

    $pdo->exec("ALTER TABLE annotations MODIFY COLUMN decision VARCHAR(100)");
    $pdo->exec("ALTER TABLE final_decisions MODIFY COLUMN decision VARCHAR(100)");
    echo "Decision columns updated successfully<br>";

    $defaultChoiceSchema = serializeDecisionChoices(getDefaultDecisionChoices());
    $stmt = $pdo->prepare("UPDATE projects SET choice_schema = ? WHERE choice_schema IS NULL OR TRIM(choice_schema) = ''");
    $stmt->execute([$defaultChoiceSchema]);
    echo "Projects choice schemas initialized successfully<br>";

    $pdo->exec("UPDATE annotations SET decision = CASE WHEN decision = '1' THEN 'yes' WHEN decision = '0' THEN 'no' ELSE decision END");
    $pdo->exec("UPDATE final_decisions SET decision = CASE WHEN decision = '1' THEN 'yes' WHEN decision = '0' THEN 'no' ELSE decision END");
    echo "Existing decisions normalized successfully<br>";

    $pdo->exec("DELETE a1 FROM annotations a1 JOIN annotations a2 ON a1.snippet_id = a2.snippet_id AND a1.user_id = a2.user_id AND a1.id < a2.id");
    $pdo->exec("DELETE f1 FROM final_decisions f1 JOIN final_decisions f2 ON f1.snippet_id = f2.snippet_id AND f1.id < f2.id");
    echo "Existing duplicate decisions cleaned successfully<br>";

    if (!indexExists($pdo, $database, 'annotations', 'unique_annotation')) {
        $pdo->exec("ALTER TABLE annotations ADD UNIQUE KEY unique_annotation (snippet_id, user_id)");
        echo "Unique annotation index added successfully<br>";
    }

    if (!indexExists($pdo, $database, 'final_decisions', 'unique_final_decision')) {
        $pdo->exec("ALTER TABLE final_decisions ADD UNIQUE KEY unique_final_decision (snippet_id)");
        echo "Unique final decision index added successfully<br>";
    }


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
