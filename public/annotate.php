<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAnnotator()) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$projectId = $_GET['project_id'] ?? null;

// Get user's assigned projects
$userProjects = getUserProjects($userId);

if ($projectId) {
    // Check if user is assigned to this project
    $isAssigned = false;
    foreach ($userProjects as $project) {
        if ($project['id'] == $projectId) {
            $isAssigned = true;
            break;
        }
    }
    if (!$isAssigned) {
        die('You are not assigned to this project');
    }

	// Get project name and instructions
    $stmtProject = $pdo->prepare("SELECT name, instructions FROM projects WHERE id = ?");
    $stmtProject->execute([$projectId]);
    $projectDetails = $stmtProject->fetch(PDO::FETCH_ASSOC);
    $projectName = $projectDetails['name'];
    $projectInstructions = $projectDetails['instructions'];

    // Get total number of snippets for this project
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM snippets WHERE project_id = ?");
    $stmtTotal->execute([$projectId]);
    $totalSnippets = $stmtTotal->fetchColumn();

    // Get number of annotated snippets for this user and project
    $stmtAnnotated = $pdo->prepare("SELECT COUNT(*) FROM annotations WHERE user_id = ? AND snippet_id IN (SELECT id FROM snippets WHERE project_id = ?)");
    $stmtAnnotated->execute([$userId, $projectId]);
    $annotatedSnippets = $stmtAnnotated->fetchColumn();

    // Start the session if not already started
    if (session_status() == PHP_SESSION_NONE) {
        session_start();        
    }

    // Initialize the current ID if not set
    if (!isset($_SESSION['currentId'])) {
        $snippet = getCurrentSnippet($userId, $projectId);
        $_SESSION['currentId'] = $snippet['id'] ?? 0;
    }

    $currentId = $_SESSION['currentId'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $decision = $_POST['decision'] ?? null;
        if ($decision !== null) {
            saveAnnotation($userId, $currentId, $decision);
            $currentId++;
        }
    } elseif (isset($_GET['action'])) {
        if ($_GET['action'] === 'prev') {
            $currentId--;
        } elseif ($_GET['action'] === 'next') {
            $currentId++;
        }
    } elseif (isset($_GET['go_to'])) {
        $currentId = intval($_GET['go_to']);
    }

    $snippet = getSpecificSnippet($userId, $projectId, $currentId);

    // If no snippet is found (e.g., reached the end or beginning), adjust the currentId
    if (!$snippet) {
        $stmt = $pdo->prepare("SELECT MIN(id) as min_id, MAX(id) as max_id FROM snippets WHERE project_id = ?");
        $stmt->execute([$projectId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currentId < $result['min_id']) {
            $currentId = $result['min_id'];
        } elseif ($currentId > $result['max_id']) {
            $currentId = $result['max_id'];
        }
        
        $snippet = getSpecificSnippet($userId, $projectId, $currentId);
    }

    // Save the current ID in the session
    $_SESSION['currentId'] = $currentId;

    // Update annotated snippets count
    $annotatedSnippets = getAnnotatedSnippetsCount($userId, $projectId);
        
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annotation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light">
    <div class="container-fluid">
            <a class="navbar-brand" href="#">Annotation App</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="annotate.php">Projects</a>
                    </li>
                    <?php if ($_SESSION['role'] === 'superannotator'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="admin.php">Admin</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <div class="navbar-nav ms-auto">
                    <span class="navbar-text me-3">
                        Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>
                    </span>
                    <a class="nav-link" href="change_password.php">Change Password</a>
                    <a class="nav-link" href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </nav>
	
    <div class="container mt-5">
    <?php if (!$projectId): ?>
        <h2>Select a Project</h2>
        <ul class="list-group">
            <?php foreach ($userProjects as $project): ?>
                <li class="list-group-item">
                    <a href="?project_id=<?php echo $project['id']; ?>">
                        <?php echo htmlspecialchars($project['name']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif ($snippet): ?>
        <h2><?php echo htmlspecialchars($projectName); ?></h2>
        <div class="project-instructions mb-3">
            <h4>Instructions:</h4>
            <p><?php echo nl2br(htmlspecialchars($projectInstructions)); ?></p>
        </div>
        <div class="progress-counter mb-3">
            <p>Progress: <?php echo $annotatedSnippets; ?> / <?php echo $totalSnippets; ?> snippets annotated</p>
            <div class="progress">
                <div class="progress-bar" role="progressbar" style="width: <?php echo ($annotatedSnippets / $totalSnippets) * 100; ?>%" aria-valuenow="<?php echo $annotatedSnippets; ?>" aria-valuemin="0" aria-valuemax="<?php echo $totalSnippets; ?>"></div>
            </div>
        </div>
        <p>
            Current Snippet ID: <?php echo $snippet['id']; ?>
            <?php if ($snippet['is_annotated']): ?>
                <span class="text-success">✓ Annotated</span>
            <?php else: ?>
                <span class="text-danger">✗ Not annotated</span>
            <?php endif; ?>
        </p>
        <div class="snippet">
            <?php echo highlightSnippet($snippet['content'], $snippet['highlight']); ?>
        </div>
        <form method="post" class="mt-3">
            <button type="submit" name="decision" value="1" id="yesButton" class="btn btn-success">Yes</button>
            <button type="submit" name="decision" value="0" id="noButton" class="btn btn-danger">No</button>
        </form>
        <div class="navigation mt-3">
            <a href="?project_id=<?php echo $projectId; ?>&action=prev" class="btn btn-secondary">Previous</a>
            <a href="?project_id=<?php echo $projectId; ?>&action=next" class="btn btn-secondary">Next</a>
            <form method="get" class="d-inline-block">
                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                <input type="number" name="go_to" placeholder="Go to snippet..." class="form-control d-inline-block" style="width: auto;">
                <button type="submit" class="btn btn-primary">Go</button>
            </form>
        </div>
    <?php else: ?>
        <p>No more snippets to annotate in this project.</p>
        <a href="annotate.php" class="btn btn-primary">Back to Project Selection</a>
    <?php endif; ?>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/annotation.js"></script>

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
