<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db.php';

// Token Auth
$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');
if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$decoded = base64_decode($token);
$parts = explode(':', $decoded);
$shop_id = (int)($parts[0]?? 0);

if($shop_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET') {
    // Get unread count only
    if(isset($_GET['count_only'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reports WHERE shop_id =? AND is_read = 0");
        $stmt->execute([$shop_id]);
        $count = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'unread_count' => (int)$count]);
        exit();
    }

    // Fetch all reports with customer + udhar entry details
    $stmt = $pdo->prepare("
        SELECT r.*, c.name as customer_name, c.unique_id,
               ue.total_amount, ue.created_at as entry_date
        FROM reports r
        JOIN customers c ON r.customer_id = c.id
        JOIN udhar_entries ue ON r.entry_id = ue.id
        WHERE r.shop_id =?
        ORDER BY r.is_read ASC, r.created_at DESC
    ");
    $stmt->execute([$shop_id]);
    $reports = $stmt->fetchAll();

    // Get unread count
    $unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE shop_id =? AND is_read = 0");
    $unread_stmt->execute([$shop_id]);
    $unread_count = $unread_stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'reports' => $reports,
        'unread_count' => (int)$unread_count
    ]);

} elseif($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action']?? '';

    if($action === 'reply') {
        $report_id = (int)$data['report_id'];
        $reply = trim($data['reply']);

        if(empty($reply)) {
            echo json_encode(['success' => false, 'message' => 'Reply cannot be empty']);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE reports SET reply =?, replied_at = NOW(), is_read = 1 WHERE id =? AND shop_id =?");
        $stmt->execute([$reply, $report_id, $shop_id]);
        echo json_encode(['success' => true, 'message' => 'Reply sent to customer successfully']);

    } elseif($action === 'mark_read') {
        $report_id = (int)$data['report_id'];
        $stmt = $pdo->prepare("UPDATE reports SET is_read = 1 WHERE id =? AND shop_id =?");
        $stmt->execute([$report_id, $shop_id]);
        echo json_encode(['success' => true, 'message' => 'Marked as read']);

    } elseif($action === 'delete') {
        $report_id = (int)$data['report_id'];
        $stmt = $pdo->prepare("DELETE FROM reports WHERE id =? AND shop_id =?");
        $stmt->execute([$report_id, $shop_id]);
        echo json_encode(['success' => true, 'message' => 'Report dismissed successfully']);

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
?>