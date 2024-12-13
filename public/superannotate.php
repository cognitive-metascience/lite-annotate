<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isSuperannotator()) {
    header('Location: login.php');
    exit();
}

$projectId = $_GET['project_id'] ?? null;

if (!$projectId) {
    die('No project specified');
}

// Get project details
$stmtProject = $pdo->prepare("SELECT name, instructions FROM projects WHERE id = ?");
$stmtProject->execute([$projectId]);
$projectDetails = $stmtProject->fetch(PDO::FETCH_ASSOC);

// Initialize session for current snippet tracking
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Get disagreement snippets if not in session
if (!isset($_SESSION['disagreement_snippets'])) {
    $_SESSION['disagreement_snippets'] = getSnippetsWithDisagreements($projectId);
    $_SESSION['current_disagreement_index'] = 0;
}

// Handle navigation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $snippetId = $_POST['snippet_id'] ?? null;
    $decision = $_POST['decision'] ?? null;
    if ($snippetId !== null && $decision !== null) {
        saveFinalDecision($snippetId, $decision);
        $_SESSION['current_disagreement_index']++;
    }
} elseif (isset($_GET['action'])) {
    if ($_GET['action'] === 'prev') {
        $_SESSION['current_disagreement_index']--;
    } elseif ($_GET['action'] === 'next') {
        $_SESSION['current_disagreement_index']++;
    }
}

// Ensure index stays within bounds
if ($_SESSION['current_disagreement_index'] < 0) {
    $_SESSION['current_disagreement_index'] = 0;
}
if ($_SESSION['current_disagreement_index'] >= count($_SESSION['disagreement_snippets'])) {
    $_SESSION['current_disagreement_index'] = count($_SESSION['disagreement_snippets']) - 1;
}

$currentSnippet = $_SESSION['disagreement_snippets'][$_SESSION['current_disagreement_index']] ?? null;

// Get the final decision
if ($currentSnippet) {
    $stmtFinalDecision = $pdo->prepare("SELECT decision FROM final_decisions WHERE snippet_id = ?");
    $stmtFinalDecision->execute([$currentSnippet['id']]);
    $finalDecision = $stmtFinalDecision->fetchColumn();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superannotator Interface</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light">
        <div class="container-fluid">
            <a class="navbar-brand" href="admin.php">← Back to Admin</a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>
                </span>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <?php if ($currentSnippet): ?>
            <h2><?php echo htmlspecialchars($projectDetails['name']); ?></h2>
            <div class="progress-counter mb-3">
                <p>Progress: <?php echo $_SESSION['current_disagreement_index'] + 1; ?> / <?php echo count($_SESSION['disagreement_snippets']); ?> disagreements reviewed</p>
            </div>
            
            <div class="snippet">
                <?php echo highlightSnippet($currentSnippet['content'], $currentSnippet['highlight']); ?>
            </div>

            <div class="decision-summary mt-3">
                <h6>Annotator Decisions:</h6>
                <?php foreach ($currentSnippet['annotations'] as $annotator => $decision): ?>
                    <div>
                        <?php echo htmlspecialchars($annotator); ?>: 
                        <span class="<?php echo $decision == 1 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo $decision == 1 ? '✓ Yes' : '✗ No'; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
			<?php if (isset($finalDecision)): ?>
				<div class="decision-summary mt-3">
					<h6>Final Decision:</h6>
					<div class="alert <?php echo $finalDecision == 1 ? 'alert-success' : 'alert-danger'; ?>">
						<?php echo $finalDecision == 1 ? '✓ Valid' : '✗ Invalid'; ?>
					</div>
				</div>
			<?php endif; ?>

            <form method="post" class="mt-3">
                <input type="hidden" name="snippet_id" value="<?php echo $currentSnippet['id']; ?>">
                <button type="submit" name="decision" value="1" id="yesButton" class="btn btn-success">Valid (Space)</button>
                <button type="submit" name="decision" value="0" id="noButton" class="btn btn-danger">Invalid (N)</button>
            </form>

            <div class="navigation mt-3">
                <a href="?project_id=<?php echo $projectId; ?>&action=prev" class="btn btn-secondary">Previous</a>
                <a href="?project_id=<?php echo $projectId; ?>&action=next" class="btn btn-secondary">Next</a>
            </div>
        <?php else: ?>
            <div class="alert alert-info">No more snippets with disagreements to review.</div>
            <a href="admin.php" class="btn btn-primary">Back to Admin</a>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('keydown', function(event) {
        if (event.code === 'Space') {
            event.preventDefault(); // Prevent scrolling
            document.getElementById('yesButton').click();
        } else if (event.key === 'n' || event.key === 'N') {
            document.getElementById('noButton').click();
        }
    });
    </script>
</body>
</html>