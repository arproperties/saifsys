<?php
// operation/ajax_client_notes.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : (isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0);
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($client_id <= 0 && $action !== 'create' && $action !== 'create_task') {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

try {
    switch ($action) {
        case 'list':
            $result = listNotes($conn, $client_id);
            break;
            
        case 'create':
            $result = createNote($conn, $_POST);
            break;
            
        case 'create_task':
            $result = createTask($conn, $_POST);
            break;
            
        case 'update':
            $result = updateNote($conn, $_POST);
            break;
            
        case 'delete':
            $result = deleteNote($conn, (int)($_POST['note_id'] ?? 0));
            break;
            
        case 'tasks':
            $result = listTasks($conn, $client_id);
            break;
            
        case 'create_task':
            $result = createTask($conn, $_POST);
            break;
            
        case 'complete_task':
            $result = completeTask($conn, (int)($_POST['task_id'] ?? 0));
            break;
            
        case 'get':
            $result = getNote($conn, (int)($_GET['note_id'] ?? 0));
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            exit;
    }
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Action failed: ' . $e->getMessage()]);
}

function listNotes(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            cn.*,
            u.fullname as created_by_name,
            u2.fullname as updated_by_name
        FROM client_notes cn
        LEFT JOIN user u ON u.id = cn.created_by
        LEFT JOIN user u2 ON u2.id = cn.updated_by
        WHERE cn.client_id = ?
        ORDER BY cn.created_at DESC
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $notes = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Format dates and add relative time
    foreach ($notes as &$note) {
        $note['formatted_date'] = date('M j, Y g:i A', strtotime($note['created_at']));
        $note['relative_time'] = getRelativeTime($note['created_at']);
        $note['type_class'] = getNoteTypeClass($note['note_type']);
    }
    
    return [
        'success' => true,
        'notes' => $notes
    ];
}

function createNote(PDO $conn, array $post): array {
    $client_id = (int)($post['client_id'] ?? 0);
    $note_type = trim($post['note_type'] ?? 'general');
    $content = trim($post['content'] ?? '');
    $user_id = current_user_id();
    
    if ($client_id <= 0) {
        return ['success' => false, 'error' => 'Invalid client ID'];
    }
    
    if (empty($content)) {
        return ['success' => false, 'error' => 'Note content is required'];
    }
    
    $sql = "
        INSERT INTO client_notes 
        (client_id, note_type, content, created_by)
        VALUES (?, ?, ?, ?)
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([$client_id, $note_type, $content, $user_id]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to create note'];
    }
    
    $note_id = (int)$conn->lastInsertId();
    
    // Get the created note with user info
    $st = $conn->prepare("
        SELECT cn.*, u.fullname as created_by_name
        FROM client_notes cn
        LEFT JOIN user u ON u.id = cn.created_by
        WHERE cn.id = ?
    ");
    $st->execute([$note_id]);
    $note = $st->fetch(PDO::FETCH_ASSOC);
    
    // Format the note
    $note['formatted_date'] = date('M j, Y g:i A', strtotime($note['created_at']));
    $note['relative_time'] = getRelativeTime($note['created_at']);
    $note['type_class'] = getNoteTypeClass($note['note_type']);
    
    return [
        'success' => true,
        'message' => 'Note created successfully',
        'note' => $note
    ];
}

function updateNote(PDO $conn, array $post): array {
    $note_id = (int)($post['note_id'] ?? 0);
    $content = trim($post['content'] ?? '');
    $note_type = trim($post['note_type'] ?? 'general');
    $user_id = current_user_id();
    
    if ($note_id <= 0) {
        return ['success' => false, 'error' => 'Invalid note ID'];
    }
    
    if (empty($content)) {
        return ['success' => false, 'error' => 'Note content is required'];
    }
    
    $sql = "
        UPDATE client_notes 
        SET content = ?, note_type = ?, updated_by = ?, updated_at = NOW()
        WHERE id = ?
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([$content, $note_type, $user_id, $note_id]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to update note'];
    }
    
    return [
        'success' => true,
        'message' => 'Note updated successfully'
    ];
}

function deleteNote(PDO $conn, int $note_id): array {
    if ($note_id <= 0) {
        return ['success' => false, 'error' => 'Invalid note ID'];
    }
    
    $st = $conn->prepare("DELETE FROM client_notes WHERE id = ?");
    $ok = $st->execute([$note_id]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to delete note'];
    }
    
    return [
        'success' => true,
        'message' => 'Note deleted successfully'
    ];
}

function listTasks(PDO $conn, int $client_id): array {
    // For now, we'll use the notes table with note_type = 'task'
    // In a full implementation, you'd have a separate tasks table
    $sql = "
        SELECT 
            cn.*,
            u.fullname as created_by_name,
            u2.fullname as assigned_to_name
        FROM client_notes cn
        LEFT JOIN user u ON u.id = cn.created_by
        LEFT JOIN user u2 ON u2.id = cn.assigned_to
        WHERE cn.client_id = ? AND cn.note_type = 'task'
        ORDER BY 
            CASE 
                WHEN cn.due_date IS NULL THEN 1
                WHEN cn.due_date < CURDATE() THEN 0
                ELSE 2
            END,
            cn.due_date ASC,
            cn.created_at DESC
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Format tasks and add status
    foreach ($tasks as &$task) {
        $task['formatted_date'] = date('M j, Y g:i A', strtotime($task['created_at']));
        $task['relative_time'] = getRelativeTime($task['created_at']);
        
        if ($task['due_date']) {
            $task['formatted_due_date'] = date('M j, Y', strtotime($task['due_date']));
            $task['due_relative'] = getRelativeTime($task['due_date']);
            
            $today = new DateTime();
            $due_date = new DateTime($task['due_date']);
            $task['is_overdue'] = $due_date < $today && !$task['completed_at'];
            $task['due_soon'] = $due_date->diff($today)->days <= 3 && !$task['completed_at'];
        } else {
            $task['is_overdue'] = false;
            $task['due_soon'] = false;
        }
        
        $task['is_completed'] = !empty($task['completed_at']);
        $task['status_class'] = getTaskStatusClass($task);
    }
    
    return [
        'success' => true,
        'tasks' => $tasks
    ];
}

function createTask(PDO $conn, array $post): array {
    $client_id = (int)($post['client_id'] ?? 0);
    $content = trim($post['content'] ?? '');
    $due_date = $post['due_date'] ?: null;
    $priority = trim($post['priority'] ?? 'medium');
    $user_id = current_user_id();
    
    if ($client_id <= 0) {
        return ['success' => false, 'error' => 'Invalid client ID'];
    }
    
    if (empty($content)) {
        return ['success' => false, 'error' => 'Task content is required'];
    }
    
    $sql = "
        INSERT INTO client_notes 
        (client_id, note_type, content, due_date, priority, created_by)
        VALUES (?, 'task', ?, ?, ?, ?)
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([$client_id, $content, $due_date, $priority, $user_id]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to create task'];
    }
    
    $task_id = (int)$conn->lastInsertId();
    
    return [
        'success' => true,
        'message' => 'Task created successfully',
        'task_id' => $task_id
    ];
}

function completeTask(PDO $conn, int $task_id): array {
    if ($task_id <= 0) {
        return ['success' => false, 'error' => 'Invalid task ID'];
    }
    
    $user_id = current_user_id();
    
    $sql = "
        UPDATE client_notes 
        SET completed_at = NOW(), updated_by = ?, updated_at = NOW()
        WHERE id = ? AND note_type = 'task'
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([$user_id, $task_id]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to complete task'];
    }
    
    return [
        'success' => true,
        'message' => 'Task marked as completed'
    ];
}

function getRelativeTime(string $datetime): string {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    
    return floor($time/31536000) . ' years ago';
}

function getNoteTypeClass(string $type): string {
    return match($type) {
        'important' => 'border-warning',
        'task' => 'border-info',
        'general' => 'border-light',
        default => 'border-light'
    };
}

function getTaskStatusClass(array $task): string {
    if ($task['is_completed']) {
        return 'text-success';
    }
    
    if ($task['is_overdue']) {
        return 'text-danger';
    }
    
    if ($task['due_soon']) {
        return 'text-warning';
    }
    
    return 'text-muted';
}

function getNote(PDO $conn, int $note_id): array {
    if ($note_id <= 0) {
        return ['success' => false, 'error' => 'Invalid note ID'];
    }
    
    $sql = "
        SELECT * FROM client_notes 
        WHERE id = ?
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$note_id]);
    $note = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$note) {
        return ['success' => false, 'error' => 'Note not found'];
    }
    
    return [
        'success' => true,
        'note' => $note
    ];
}
