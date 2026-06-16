<?php
require_once __DIR__ . '/annotation_core.php';

function getProjects() {
    global $pdo;
    $stmt = $pdo->query("SELECT id, name, instructions, choice_schema FROM projects ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProjectById($projectId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id, name, instructions, choice_schema FROM projects WHERE id = ? LIMIT 1");
    $stmt->execute([$projectId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getProjectDecisionChoices($projectId) {
    $project = getProjectById($projectId);

    if (!$project) {
        return getDefaultDecisionChoices();
    }

    return parseDecisionChoices($project['choice_schema'] ?? null);
}

function createProject($projectName, $projectInstructions, $projectChoicesText) {
    global $pdo;
    $choiceSchema = serializeDecisionChoices(buildDecisionChoicesFromText($projectChoicesText));
    $stmt = $pdo->prepare("INSERT INTO projects (name, instructions, choice_schema) VALUES (?, ?, ?)");
    return $stmt->execute([$projectName, $projectInstructions, $choiceSchema]);
}

function updateProjectSettings($projectId, $projectName, $projectInstructions, $projectChoicesText) {
    global $pdo;
    $choiceSchema = serializeDecisionChoices(buildDecisionChoicesFromText($projectChoicesText));
    $stmt = $pdo->prepare("UPDATE projects SET name = ?, instructions = ?, choice_schema = ? WHERE id = ?");
    return $stmt->execute([$projectName, $projectInstructions, $choiceSchema, $projectId]);
}

function getCurrentSnippet($userId, $projectId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT s.id, s.content, s.highlight,
               CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END AS is_annotated
        FROM snippets s
        LEFT JOIN annotations a ON s.id = a.snippet_id AND a.user_id = ?
        WHERE s.project_id = ? AND a.id IS NULL
        ORDER BY s.id
        LIMIT 1
    ");
    $stmt->execute([$userId, $projectId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getSpecificSnippet($userId, $projectId, $snippetId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT s.id, s.content, s.highlight,
               CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END AS is_annotated,
               a.decision
        FROM snippets s
        LEFT JOIN annotations a ON s.id = a.snippet_id AND a.user_id = ?
        WHERE s.project_id = ? AND s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $projectId, $snippetId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}


function getAnnotatedSnippetsCount($userId, $projectId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT snippet_id) FROM annotations WHERE user_id = ? AND snippet_id IN (SELECT id FROM snippets WHERE project_id = ?)");
    $stmt->execute([$userId, $projectId]);
    return $stmt->fetchColumn();
}

function saveAnnotation($userId, $snippetId, $decision) {
    global $pdo;
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("DELETE FROM annotations WHERE user_id = ? AND snippet_id = ?");
        $stmt->execute([$userId, $snippetId]);

        $stmt = $pdo->prepare("INSERT INTO annotations (user_id, snippet_id, decision) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $snippetId, $decision]);

        $pdo->commit();
    } catch (Exception $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function highlightSnippet($content, $highlight) {
    if (empty($highlight)) {
        return $content;
    }
    return str_replace($highlight, "<span class='highlight'>$highlight</span>", $content);
}

function getSnippetsWithDisagreements($projectId) {
    global $pdo;
    
    $query = "SELECT s.id, s.content, s.highlight, 
              GROUP_CONCAT(a.decision ORDER BY a.user_id) as decisions,
              GROUP_CONCAT(u.username ORDER BY a.user_id) as annotators
              FROM snippets s
              JOIN annotations a ON s.id = a.snippet_id
              JOIN users u ON a.user_id = u.id
              WHERE s.project_id = ?
              GROUP BY s.id
              HAVING COUNT(DISTINCT a.decision) > 1";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([$projectId]);
    
    $snippets = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $decisions = explode(',', $row['decisions']);
        $annotators = explode(',', $row['annotators']);
        $annotations = combineAnnotatorDecisions($annotators, $decisions);
        
        $snippets[] = [
            'id' => $row['id'],
            'content' => $row['content'],
            'highlight' => $row['highlight'],
            'annotations' => $annotations
        ];
    }
    
    return $snippets;
}


function saveFinalDecision($snippetId, $decision) {
    global $pdo;
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("DELETE FROM final_decisions WHERE snippet_id = ?");
        $stmt->execute([$snippetId]);

        $stmt = $pdo->prepare("INSERT INTO final_decisions (snippet_id, decision) VALUES (?, ?)");
        $stmt->execute([$snippetId, $decision]);

        $pdo->commit();
    } catch (Exception $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function calculateCohenKappa($projectId) {
    global $pdo;
    
    // Get all annotators for the project
    $stmt = $pdo->prepare("
        SELECT DISTINCT user_id
        FROM annotations a
        JOIN snippets s ON a.snippet_id = s.id
        WHERE s.project_id = ?
    ");
    $stmt->execute([$projectId]);
    $annotators = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($annotators) < 2) {
        return "Not enough annotators for Cohen's Kappa calculation.";
    }
    
    // Calculate pairwise Cohen's Kappa for all annotator pairs
    $kappas = [];
    for ($i = 0; $i < count($annotators) - 1; $i++) {
        for ($j = $i + 1; $j < count($annotators); $j++) {
            $kappa = calculatePairwiseKappa($projectId, $annotators[$i], $annotators[$j]);
            $kappas[] = $kappa;
        }
    }
    
    // Return the average Kappa score
    return array_sum($kappas) / count($kappas);
}

function calculateKrippendorffsAlpha($projectId) {
    global $pdo;

    $stmt = $pdo->prepare(" 
        SELECT a.snippet_id, a.decision
        FROM annotations a
        JOIN snippets s ON a.snippet_id = s.id
        WHERE s.project_id = ?
        ORDER BY a.snippet_id, a.user_id
    ");
    $stmt->execute([$projectId]);
    $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $units = [];
    foreach ($ratings as $rating) {
        $snippetId = $rating['snippet_id'];

        if (!isset($units[$snippetId])) {
            $units[$snippetId] = [];
        }

        $units[$snippetId][] = $rating['decision'];
    }

    return calculateNominalKrippendorffsAlpha(array_values($units));
}

function calculatePairwiseKappa($projectId, $annotator1, $annotator2) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT 
            a1.decision as decision1,
            a2.decision as decision2
        FROM snippets s
        JOIN annotations a1 ON s.id = a1.snippet_id AND a1.user_id = ?
        JOIN annotations a2 ON s.id = a2.snippet_id AND a2.user_id = ?
        WHERE s.project_id = ?
    ");
    $stmt->execute([$annotator1, $annotator2, $projectId]);
    $annotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return calculateNominalCohenKappa($annotations) ?? 0.0;
}

function checkAnnotatorConsistency($projectId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT user_id, snippet_id, decision, content
        FROM annotations
        JOIN snippets ON annotations.snippet_id = snippets.id
        WHERE snippets.project_id = ?
    ");
    $stmt->execute([$projectId]);
    $annotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $consistencyResults = [];
    
    foreach ($annotations as $annotation) {
        $userId = $annotation['user_id'];
        $content = $annotation['content'];
        
        if (!isset($consistencyResults[$userId])) {
            $consistencyResults[$userId] = ['total' => 0, 'consistent' => 0];
        }
        
        $duplicates = array_filter($annotations, function($a) use ($content, $userId) {
            return $a['content'] == $content && $a['user_id'] == $userId;
        });
        
        if (count($duplicates) > 1) {
            $consistencyResults[$userId]['total'] += count($duplicates);
            $decisions = array_column($duplicates, 'decision');
            $consistencyResults[$userId]['consistent'] += (count(array_unique($decisions)) === 1) ? count($duplicates) : 0;
        }
    }
    
    $finalResults = [];
    foreach ($consistencyResults as $userId => $result) {
        $finalResults[$userId] = $result['total'] > 0 ? $result['consistent'] / $result['total'] : 1;
    }
    
    return $finalResults;
}

function createDefaultAdminAccount() {
    global $pdo;
    
    // Check if any superannotator account exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superannotator'");
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        // No superannotator account exists, create a default one
        $username = 'admin';
        $password = bin2hex(random_bytes(8)); // Generate a random 16-character password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'superannotator')");
        $stmt->execute([$username, $hashedPassword]);
        
        // Return the generated password so it can be displayed to the setup user
        return $password;
    }
    
    return null;
}

function createUser($username, $password, $role) {
    global $pdo;
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    return $stmt->execute([$username, $hashedPassword, $role]);
}

function deleteUser($userId) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    return $stmt->execute([$userId]);
}

function getUsers() {
    global $pdo;
    $stmt = $pdo->query("SELECT id, username, role FROM users ORDER BY username");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function assignUserToProject($userId, $projectId) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO user_projects (user_id, project_id) VALUES (?, ?)");
    return $stmt->execute([$userId, $projectId]);
}

function removeUserFromProject($userId, $projectId) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM user_projects WHERE user_id = ? AND project_id = ?");
    return $stmt->execute([$userId, $projectId]);
}

function getUserProjects($userId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT p.id, p.name FROM projects p JOIN user_projects up ON p.id = up.project_id WHERE up.user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



