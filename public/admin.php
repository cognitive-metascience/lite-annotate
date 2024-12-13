<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Ensure only superannotators can access this page
if (!isLoggedIn() || !isSuperannotator()) {
    header('Location: login.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['import_json'])) {
        handleJsonImport();
    } elseif (isset($_POST['export_json'])) {
        handleJsonExport();
    } elseif (isset($_POST['create_project'])) {
        handleCreateProject();
    }
}

// Handle user creation
if (isset($_POST['create_user'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $role = $_POST['role'];
    if (createUser($username, $password, $role)) {
        echo "<div class='alert alert-success'>User created successfully.</div>";
    } else {
        echo "<div class='alert alert-danger'>Error creating user.</div>";
    }
}

// Handle user deletion
if (isset($_POST['delete_user'])) {
    $userId = $_POST['user_id'];
    if (deleteUser($userId)) {
        echo "<div class='alert alert-success'>User deleted successfully.</div>";
    } else {
        echo "<div class='alert alert-danger'>Error deleting user.</div>";
    }
}

// Handle project assignment
if (isset($_POST['assign_project'])) {
    $userId = $_POST['user_id'];
    $projectId = $_POST['project_id'];
    if (assignUserToProject($userId, $projectId)) {
        echo "<div class='alert alert-success'>User assigned to project successfully.</div>";
    } else {
        echo "<div class='alert alert-danger'>Error assigning user to project.</div>";
    }
}

// Handle project removal
if (isset($_POST['remove_project'])) {
    $userId = $_POST['user_id'];
    $projectId = $_POST['project_id'];
    if (removeUserFromProject($userId, $projectId)) {
        echo "<div class='alert alert-success'>User removed from project successfully.</div>";
    } else {
        echo "<div class='alert alert-danger'>Error removing user from project.</div>";
    }
}

$users = getUsers();

// Get list of projects
$projects = getProjects();

// Add these functions at the top of admin.php

function handleJsonImport() {
    if (!isset($_FILES['json_file']) || $_FILES['json_file']['error'] !== UPLOAD_ERR_OK) {
        echo "<div class='alert alert-danger'>Error uploading file.</div>";
        return;
    }

    $projectId = $_POST['project_id'];
    $tempFilePath = $_FILES['json_file']['tmp_name'];

    try {
        $jsonData = file_get_contents($tempFilePath);
        $data = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON file: " . json_last_error_msg());
        }

        global $pdo;
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO snippets (project_id, content, highlight) VALUES (?, ?, ?)");

        foreach ($data as $item) {
            if (!isset($item['content']) || !isset($item['kwic'])) {
                throw new Exception("Invalid data structure in JSON");
            }

            $stmt->execute([$projectId, $item['content'], $item['kwic']]);
        }

        $pdo->commit();
        echo "<div class='alert alert-success'>JSON data imported successfully.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='alert alert-danger'>Error importing JSON data: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

function handleJsonExport() {
    $projectId = $_POST['export_project_id'];

    try {
        global $pdo;
        $stmt = $pdo->prepare("
            WITH annotation_summary AS (
                SELECT 
                    snippet_id,
                    COUNT(DISTINCT decision) as unique_decisions,
                    MIN(decision) as min_decision,
                    MAX(decision) as max_decision
                FROM annotations
                GROUP BY snippet_id
            )
            SELECT 
                s.id, 
                s.content, 
                s.highlight,
                GROUP_CONCAT(DISTINCT a.decision) as annotations,
                fd.decision as final_decision,
                ans.unique_decisions,
                CASE 
                    WHEN ans.unique_decisions = 1 THEN ans.min_decision
                    ELSE NULL
                END as unanimous_decision
            FROM snippets s
            LEFT JOIN annotations a ON s.id = a.snippet_id
            LEFT JOIN final_decisions fd ON s.id = fd.snippet_id
            LEFT JOIN annotation_summary ans ON s.id = ans.snippet_id
            WHERE s.project_id = ?
            GROUP BY s.id
        ");
        $stmt->execute([$projectId]);

        $data = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Convert annotations string to array of integers
            $annotations = $row['annotations'] ? array_map('intval', explode(',', $row['annotations'])) : [];
            
            // Determine the final decision:
            // 1. If there's a final_decision from superannotator, use that
            // 2. If there's a unanimous_decision, use that
            // 3. Otherwise, it's null
            $finalDecision = null;
            if ($row['final_decision'] !== null) {
                $finalDecision = $row['final_decision'] == 1;
            } elseif ($row['unanimous_decision'] !== null) {
                $finalDecision = $row['unanimous_decision'] == 1;
            }

            $item = [
                'id' => $row['id'],
                'content' => $row['content'],
                'kwic' => $row['highlight'],
                'annotations' => $annotations,
                'final_decision' => $finalDecision
            ];
            $data[] = $item;
        }

        $jsonData = json_encode($data, JSON_PRETTY_PRINT);
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="export_project_' . $projectId . '.json"');
        echo $jsonData;
        exit;
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Error exporting JSON data: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

function handleCreateProject() {
    $projectName = $_POST['project_name'];
    $projectInstructions = $_POST['project_instructions'];

    try {
        global $pdo;
        $stmt = $pdo->prepare("INSERT INTO projects (name, instructions) VALUES (?, ?)");
        $stmt->execute([$projectName, $projectInstructions]);
        echo "<div class='alert alert-success'>Project created successfully.</div>";
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Error creating project: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

function getProjects() {
    global $pdo;
    $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="css/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Annotation App</a>
        <div class="navbar-nav ms-auto">
            <?php if (isLoggedIn()): ?>
                <span class="navbar-text me-3">
                    Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>
                </span>
                <a class="nav-link" href="change_password.php">Change Password</a>
                <a class="nav-link" href="logout.php">Logout</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

    <div class="container mt-5">
        <h1>Admin Dashboard</h1>
			
        
        <h2 class="mt-4">Import JSON</h2>
        <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="json_file" class="form-label">JSON File</label>
                <input type="file" class="form-control" id="json_file" name="json_file" required>
            </div>
            <div class="mb-3">
                <label for="project_id" class="form-label">Project</label>
                <select class="form-select" id="project_id" name="project_id" required>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="import_json" class="btn btn-primary">Import JSON</button>
        </form>
		
		<h2 class="mt-4">Calculate Inter-Annotator Agreement</h2>
			<form method="post">
				<div class="mb-3">
					<label for="kappa_project_id" class="form-label">Project</label>
					<select class="form-select" id="kappa_project_id" name="kappa_project_id" required>
						<?php foreach ($projects as $project): ?>
							<option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="submit" name="calculate_kappa" class="btn btn-primary">Calculate Cohen's Kappa</button>
			</form>

			<?php
			if (isset($_POST['calculate_kappa'])) {
				$projectId = $_POST['kappa_project_id'];
				
				// Get agreement data
				$stmt = $pdo->prepare("
					SELECT 
						a1.user_id as rater1,
						a2.user_id as rater2,
						a1.decision as decision1,
						a2.decision as decision2,
						COUNT(*) as count
					FROM annotations a1
					JOIN annotations a2 
						ON a1.snippet_id = a2.snippet_id
						AND a1.user_id < a2.user_id
					JOIN snippets s ON a1.snippet_id = s.id
					WHERE s.project_id = ?
					GROUP BY a1.user_id, a2.user_id, a1.decision, a2.decision
				");
				$stmt->execute([$projectId]);
				$agreements = $stmt->fetchAll(PDO::FETCH_ASSOC);
				
				echo "<div class='alert alert-info mt-3'>";
				
				// Display contingency tables for each pair
				$currentPair = null;
				$contingencyTable = array();
				
				foreach ($agreements as $row) {
					$pair = $row['rater1'] . '-' . $row['rater2'];
					
					if ($currentPair !== $pair) {
						if ($currentPair !== null) {
							displayContingencyTable($contingencyTable, $users);
						}
						$currentPair = $pair;
						$contingencyTable = array(
							array(0, 0),
							array(0, 0)
						);
					}
					
					$contingencyTable[$row['decision1']][$row['decision2']] = $row['count'];
				}
				
				// Display last pair
				if ($currentPair !== null) {
					displayContingencyTable($contingencyTable, $users);
				}
				
				$kappaResults = calculateCohenKappa($projectId);
				echo "<strong>Overall Cohen's Kappa: " . number_format($kappaResults, 4) . "</strong>";
				echo "</div>";
			}

			function displayContingencyTable($table, $users) {
				echo "<h4>Contingency Table:</h4>";
				echo "<table class='table table-bordered w-auto'>";
				echo "<tr><th></th><th>Rater 2 No</th><th>Rater 2 Yes</th></tr>";
				echo "<tr><td>Rater 1 No</td><td>{$table[0][0]}</td><td>{$table[0][1]}</td></tr>";
				echo "<tr><td>Rater 1 Yes</td><td>{$table[1][0]}</td><td>{$table[1][1]}</td></tr>";
				echo "</table>";
				
				// Calculate observed agreement
				$total = array_sum(array_map('array_sum', $table));
				$agreed = $table[0][0] + $table[1][1];
				$po = $agreed / $total;
				
				// Calculate expected agreement
				$rater1_yes = ($table[1][0] + $table[1][1]) / $total;
				$rater2_yes = ($table[0][1] + $table[1][1]) / $total;
				$rater1_no = ($table[0][0] + $table[0][1]) / $total;
				$rater2_no = ($table[0][0] + $table[1][0]) / $total;
				$pe = ($rater1_yes * $rater2_yes) + ($rater1_no * $rater2_no);
				
				// Calculate kappa
				$kappa = ($po - $pe) / (1 - $pe);
				
				echo "<p>Observed Agreement (Po): " . number_format($po, 4) . "</p>";
				echo "<p>Expected Agreement (Pe): " . number_format($pe, 4) . "</p>";
				echo "<p>Kappa: " . number_format($kappa, 4) . "</p>";
				echo "<hr>";
			}
			?>

        <h2 class="mt-4">Annotator Consistency Check</h2>
        <form method="post">
            <div class="mb-3">
                <label for="consistency_project_id" class="form-label">Project</label>
                <select class="form-select" id="consistency_project_id" name="consistency_project_id" required>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="check_consistency" class="btn btn-primary">Check Annotator Consistency</button>
        </form>

        <?php
			if (isset($_POST['check_consistency'])) {
				$consistencyResults = checkAnnotatorConsistency($_POST['consistency_project_id']);
				echo "<div class='mt-3'>";
				foreach ($consistencyResults as $annotatorId => $consistency) {
					// Get username for the annotator ID
					$annotatorUsername = '';
					foreach ($users as $user) {
						if ($user['id'] == $annotatorId) {
							$annotatorUsername = $user['username'];
							break;
						}
					}
					echo "<div class='alert alert-info'>Annotator " . htmlspecialchars($annotatorUsername) . 
						 " (ID: $annotatorId) Consistency: " . number_format($consistency * 100, 2) . "%</div>";
				}
				echo "</div>";
			}
        ?>

		 <h2 class="mt-4">Superannotator Tasks</h2>
			<form action="superannotate.php" method="get" class="mb-4">
				<div class="mb-3">
					<label for="super_project_id" class="form-label">Select Project for Superannotation</label>
					<select class="form-select" id="super_project_id" name="project_id" required>
						<?php foreach ($projects as $project): ?>
							<option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="submit" class="btn btn-primary">Go to Superannotation Task</button>
			</form>

        <h2 class="mt-4">Export JSON</h2>
        <form method="post">
            <div class="mb-3">
                <label for="export_project_id" class="form-label">Project</label>
                <select class="form-select" id="export_project_id" name="export_project_id" required>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="export_json" class="btn btn-primary">Export JSON</button>
        </form>

        <h2 class="mt-4">Create New Project</h2>
        <form method="post">
            <div class="mb-3">
                <label for="project_name" class="form-label">Project Name</label>
                <input type="text" class="form-control" id="project_name" name="project_name" required>
            </div>
            <div class="mb-3">
                <label for="project_instructions" class="form-label">Instructions</label>
                <textarea class="form-control" id="project_instructions" name="project_instructions" rows="3"></textarea>
            </div>
            <button type="submit" name="create_project" class="btn btn-primary">Create Project</button>
        </form>
		
		<h2 class="mt-4">User Management</h2>
        <form method="post" class="mb-3">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3">
                <label for="role" class="form-label">Role</label>
                <select class="form-select" id="role" name="role" required>
                    <option value="annotator">Annotator</option>
                    <option value="superannotator">Superannotator</option>
                </select>
            </div>
            <button type="submit" name="create_user" class="btn btn-primary">Create User</button>
        </form>

        <h3>Existing Users</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['role']); ?></td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <button type="submit" name="delete_user" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this user?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="mt-4">Project Assignment</h2>
        <form method="post">
            <div class="mb-3">
                <label for="assign_user" class="form-label">User</label>
                <select class="form-select" id="assign_user" name="user_id" required>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="assign_project" class="form-label">Project</label>
                <select class="form-select" id="assign_project" name="project_id" required>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="assign_project" class="btn btn-primary">Assign to Project</button>
        </form>

        <h3 class="mt-4">Current Assignments</h3>
        <?php foreach ($users as $user): ?>
            <h4><?php echo htmlspecialchars($user['username']); ?></h4>
            <?php $userProjects = getUserProjects($user['id']); ?>
            <?php if (empty($userProjects)): ?>
                <p>No projects assigned.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($userProjects as $project): ?>
                        <li>
                            <?php echo htmlspecialchars($project['name']); ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                <button type="submit" name="remove_project" class="btn btn-sm btn-warning">Remove</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endforeach; ?>
		
    </div>
	

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
