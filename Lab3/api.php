<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

class TaskManager {
    private $pdo;

    // Database configuration - UPDATE THESE VALUES
    private $dbConfig = [
        'host' => 'localhost',
        'dbname' => 'task_manager',
        'username' => 'your_username',
        'password' => 'your_password',
        'port' => '5432'
    ];

    public function __construct() {
        $this->initDatabase();
    }

    private function initDatabase() {
        try {
            $dsn = "pgsql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname={$this->dbConfig['dbname']}";
            $this->pdo = new PDO($dsn, $this->dbConfig['username'], $this->dbConfig['password']);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create table if it doesn't exist (PostgreSQL syntax)
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS tasks (
                    id SERIAL PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    priority VARCHAR(10) DEFAULT 'medium',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Create index for better performance
            $this->pdo->exec("
                CREATE INDEX IF NOT EXISTS idx_tasks_created_at ON tasks(created_at DESC)
            ");

        } catch (PDOException $e) {
            $this->sendError("Database connection failed: " . $e->getMessage());
        }
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];

        try {
            switch ($method) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'POST':
                    $this->handlePost();
                    break;
                case 'PUT':
                    $this->handlePut();
                    break;
                case 'DELETE':
                    $this->handleDelete();
                    break;
                default:
                    $this->sendError("Method not allowed", 405);
            }
        } catch (Exception $e) {
            $this->sendError("Server error: " . $e->getMessage(), 500);
        }
    }

    private function handleGet() {
        if (isset($_GET['id'])) {
            // Get single task
            $stmt = $this->pdo->prepare("SELECT * FROM tasks WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($task) {
                $this->sendResponse($task);
            } else {
                $this->sendError("Task not found", 404);
            }
        } else {
            // Get all tasks
            $stmt = $this->pdo->query("SELECT * FROM tasks ORDER BY created_at DESC");
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->sendResponse($tasks);
        }
    }

    private function handlePost() {
        $input = $this->getJsonInput();

        if (!isset($input['title']) || empty(trim($input['title']))) {
            $this->sendError("Title is required", 400);
            return;
        }

        $title = trim($input['title']);
        $description = isset($input['description']) ? trim($input['description']) : '';
        $priority = isset($input['priority']) ? $input['priority'] : 'medium';

        // Validate priority
        if (!in_array($priority, ['low', 'medium', 'high'])) {
            $priority = 'medium';
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO tasks (title, description, priority, created_at, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ");

        if ($stmt->execute([$title, $description, $priority])) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $id = $result['id'];
            $this->sendResponse([
                'id' => $id,
                'message' => 'Task created successfully'
            ], 201);
        } else {
            $this->sendError("Failed to create task", 500);
        }
    }

    private function handlePut() {
        $input = $this->getJsonInput();

        if (!isset($input['id'])) {
            $this->sendError("Task ID is required", 400);
            return;
        }

        if (!isset($input['title']) || empty(trim($input['title']))) {
            $this->sendError("Title is required", 400);
            return;
        }

        $id = $input['id'];
        $title = trim($input['title']);
        $description = isset($input['description']) ? trim($input['description']) : '';
        $priority = isset($input['priority']) ? $input['priority'] : 'medium';

        // Validate priority
        if (!in_array($priority, ['low', 'medium', 'high'])) {
            $priority = 'medium';
        }

        // Check if task exists
        $stmt = $this->pdo->prepare("SELECT id FROM tasks WHERE id = ?");
        $stmt->execute([$id]);

        if (!$stmt->fetch()) {
            $this->sendError("Task not found", 404);
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE tasks
            SET title = ?, description = ?, priority = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        if ($stmt->execute([$title, $description, $priority, $id])) {
            $this->sendResponse(['message' => 'Task updated successfully']);
        } else {
            $this->sendError("Failed to update task", 500);
        }
    }

    private function handleDelete() {
        $input = $this->getJsonInput();

        if (!isset($input['id'])) {
            $this->sendError("Task ID is required", 400);
            return;
        }

        $id = $input['id'];

        // Check if task exists
        $stmt = $this->pdo->prepare("SELECT id FROM tasks WHERE id = ?");
        $stmt->execute([$id]);

        if (!$stmt->fetch()) {
            $this->sendError("Task not found", 404);
            return;
        }

        $stmt = $this->pdo->prepare("DELETE FROM tasks WHERE id = ?");

        if ($stmt->execute([$id])) {
            $this->sendResponse(['message' => 'Task deleted successfully']);
        } else {
            $this->sendError("Failed to delete task", 500);
        }
    }

    private function getJsonInput() {
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError("Invalid JSON input", 400);
            exit;
        }

        return $input ?: [];
    }

    private function sendResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }

    private function sendError($message, $statusCode = 400) {
        http_response_code($statusCode);
        echo json_encode(['error' => $message]);
        exit;
    }
}

// Initialize and handle the request
$taskManager = new TaskManager();
$taskManager->handleRequest();
?>