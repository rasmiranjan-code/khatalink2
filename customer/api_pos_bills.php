<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once '../includes/db.php';

try {
    // --- Token-based Authentication for Flutter App ---
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $customer_id = (int)($parts[0] ?? 0);

    if (!$customer_id) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Invalid or missing token.']);
        exit();
    }
    // --- End Auth ---

    // Filters
    $from_date = $_GET['from_date'] ?? null;
    $to_date = $_GET['to_date'] ?? null;
    $shop_filter = (int)($_GET['shop_id'] ?? 0);

    // Build query for POS bills history
    $query = "
        SELECT pb.*, s.shop_name, s.shop_category, c.name as customer_name
        FROM pos_bills pb 
        JOIN shop_owners s ON pb.shop_id = s.id
        LEFT JOIN customers c ON pb.customer_id = c.id
        WHERE pb.customer_id = ?
        AND pb.is_deleted_customer = 0
        AND pb.is_deleted_shop = 0
        AND pb.payment_status NOT IN ('transferred_to_udhar')
    ";
    $params = [$customer_id];

    if ($from_date && $to_date) {
        $query .= " AND DATE(pb.created_at) BETWEEN ? AND ?";
        $params[] = $from_date;
        $params[] = $to_date;
    }

    if ($shop_filter > 0) { // Make shop_filter optional
        $query .= " AND pb.shop_id = ?";
        $params[] = $shop_filter;
    }

    $query .= " ORDER BY pb.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total outstanding due for the customer (and filtered shop if applicable)
    $total_due_query = "
        SELECT COALESCE(SUM(ue.total_remaining), 0)
        FROM udhar_entries ue
        WHERE ue.customer_id = ? AND ue.status = 'open'
    ";
    $total_due_params = [$customer_id];

    if ($shop_filter > 0) {
        $total_due_query .= " AND ue.shop_id = ?";
        $total_due_params[] = $shop_filter;
    }

    $stmt_total_due = $pdo->prepare($total_due_query);
    $stmt_total_due->execute($total_due_params);
    $total_due = (float)$stmt_total_due->fetchColumn();

    echo json_encode(['success' => true, 'bills' => $bills, 'total_due' => $total_due]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    error_log("API POS Bills Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}
?>