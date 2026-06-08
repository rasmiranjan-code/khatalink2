<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET');

require_once '../includes/db.php';

$headers = getallheaders();
$authHeader = $headers['Authorization']?? $headers['authorization']?? $_SERVER['HTTP_AUTHORIZATION']?? '';
$token = str_replace('Bearer ', '', $authHeader);

if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'No token provided', 'bills' => [], 'total_due' => 0]);
    exit();
}

$decoded = base64_decode($token);
$parts = explode(':', $decoded);
$customer_id = (int)($parts[0]?? 0);

if (!$customer_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid token', 'bills' => [], 'total_due' => 0]);
    exit();
}

$from_date = $_GET['from_date']?? date('Y-m-d', strtotime('-30 days'));
$to_date = $_GET['to_date']?? date('Y-m-d');
$shop_id_filter = (int)($_GET['shop_id']?? 0);

try {
    $query = "
        SELECT
            pb.id,
            pb.bill_number,
            c.name as customer_name,
            pb.final_net_amount,
            pb.payment_status,
            pb.created_at,
            pb.shop_id,
            s.shop_name,
            c.unique_id
        FROM pos_bills pb
        LEFT JOIN customers c ON pb.customer_id = c.id
        JOIN shop_owners s ON pb.shop_id = s.id
        WHERE pb.customer_id =?
        AND DATE(pb.created_at) BETWEEN? AND?
        AND pb.is_deleted_customer = 0
        AND pb.is_deleted_shop = 0
        AND pb.payment_status NOT IN ('transferred_to_udhar')
    ";

    $params = [$customer_id, $from_date, $to_date];

    if ($shop_id_filter > 0) {
        $query.= " AND pb.shop_id =?";
        $params[] = $shop_id_filter;
    }

    $query.= " ORDER BY pb.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total outstanding from udhar_entries table for better accuracy
    $total_due_sql = "SELECT COALESCE(SUM(total_remaining), 0) as total_due
                      FROM udhar_entries
                      WHERE customer_id =? AND status = 'open'";
    $due_params = [$customer_id];
    
    if ($shop_id_filter > 0) {
        $total_due_sql .= " AND shop_id = ?";
        $due_params[] = $shop_id_filter;
    }

    $stmt2 = $pdo->prepare($total_due_sql);
    $stmt2->execute($due_params);
    $total_due = $stmt2->fetchColumn();

    echo json_encode([
        'success' => true,
        'bills' => $bills,
        'total_due' => floatval($total_due)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'DB Error: '. $e->getMessage(),
        'bills' => [],
        'total_due' => 0
    ]);
}
?>