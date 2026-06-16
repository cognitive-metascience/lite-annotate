<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

function exportJson($projectId, $outputPath) {
    global $pdo;

    $stmt = $pdo->prepare("
        WITH annotation_summary AS (
            SELECT 
                snippet_id,
                COUNT(DISTINCT decision) as unique_decisions,
                MIN(decision) as min_decision
            FROM annotations
            GROUP BY snippet_id
        )
        SELECT s.id, s.content, s.highlight, 
             GROUP_CONCAT(a.decision ORDER BY a.user_id) as annotations,
               fd.decision as final_decision,
            CASE
                   WHEN annotation_summary.unique_decisions = 1 THEN annotation_summary.min_decision
                   ELSE NULL
               END as unanimous_decision
        FROM snippets s
        LEFT JOIN annotations a ON s.id = a.snippet_id
        LEFT JOIN final_decisions fd ON s.id = fd.snippet_id
        LEFT JOIN annotation_summary ON s.id = annotation_summary.snippet_id
        WHERE s.project_id = ?
        GROUP BY s.id
    ");
    $stmt->execute([$projectId]);

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $item = [
            'id' => $row['id'],
            'content' => $row['content'],
            'kwic' => $row['highlight'],
            'annotations' => $row['annotations'] ? explode(',', $row['annotations']) : [],
            'final_decision' => $row['final_decision'] ?? $row['unanimous_decision']
        ];
        $data[] = $item;
    }

    $jsonData = json_encode($data, JSON_PRETTY_PRINT);
    if (file_put_contents($outputPath, $jsonData) === false) {
        throw new Exception("Failed to write JSON data to file: $outputPath");
    }

    echo "JSON data exported successfully to $outputPath\n";
}

// Usage example
if ($argc < 3) {
    echo "Usage: php export_json.php <project_id> <output_file_path>\n";
    exit(1);
}

$projectId = $argv[1];
$outputPath = $argv[2];

try {
    exportJson($projectId, $outputPath);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

