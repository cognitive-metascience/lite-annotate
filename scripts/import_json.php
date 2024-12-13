<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

function importJson($filePath, $projectId) {
    global $pdo;

    if (!file_exists($filePath)) {
        throw new Exception("File not found: $filePath");
    }

    $jsonData = file_get_contents($filePath);
    $data = json_decode($jsonData, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON file: " . json_last_error_msg());
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("INSERT INTO snippets (project_id, content, highlight) VALUES (?, ?, ?)");

        foreach ($data as $item) {
            if (!isset($item['content']) || !isset($item['kwic'])) {
                throw new Exception("Invalid data structure in JSON");
            }

            $stmt->execute([$projectId, $item['content'], $item['kwic']]);
        }

        $pdo->commit();
        echo "JSON data imported successfully.\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Error importing JSON data: " . $e->getMessage() . "\n";
    }
}

// Usage example
if ($argc < 3) {
    echo "Usage: php import_json.php <json_file_path> <project_id>\n";
    exit(1);
}

$filePath = $argv[1];
$projectId = $argv[2];

try {
    importJson($filePath, $projectId);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
