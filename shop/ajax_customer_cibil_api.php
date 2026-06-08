<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once '../includes/db.php';

$shop_id = 0;

// Method 1: Token Auth (Flutter)
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
if (!empty($token)) {
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0] ?? 0);
} elseif (isset($_SESSION['shop_id'])) {
    // Method 2: Session Auth (Website)
    $shop_id = (int)$_SESSION['shop_id'];
}

if ($shop_id <= 0) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized access']));
}

$customer_uid = trim($_GET['unique_id'] ?? '');

if (empty($customer_uid)) {
    exit(json_encode(['success' => false, 'message' => 'Customer ID is required']));
}

try {
    // 1. Get Customer ID from Unique ID
    $stmt_c = $pdo->prepare("SELECT id, name, unique_id, created_at FROM customers WHERE unique_id = ?");
    $stmt_c->execute([$customer_uid]);
    $customer = $stmt_c->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        exit(json_encode(['success' => false, 'message' => 'No customer found with this ID']));
    }

    $cid = $customer['id'];

    // 2. Fetch Aggregated Data from ALL SHOPS
    // Udhar Entries
    $udhar = $pdo->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total, SUM(total_paid) as paid FROM udhar_entries WHERE customer_id = ?");
    $udhar->execute([$cid]);
    $u_data = $udhar->fetch();

    // Bonds
    $bonds = $pdo->prepare("SELECT COUNT(*) as count, SUM(amount) as total, SUM(paid_amount) as paid FROM bonds WHERE customer_id = ?");
    $bonds->execute([$cid]);
    $b_data = $bonds->fetch();

    // Monthly Khata
    $monthly = $pdo->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total, SUM(paid_amount) as paid FROM monthly_khata WHERE customer_id = ?");
    $monthly->execute([$cid]);
    $m_data = $monthly->fetch();

    // 3. Calculate Totals
    $total_borrowed = (float)$u_data['total'] + (float)$b_data['total'] + (float)$m_data['total'];
    $total_paid = (float)$u_data['paid'] + (float)$b_data['paid'] + (float)$m_data['paid'];
    $total_due = $total_borrowed - $total_paid;

    // 4. Calculate Trust Score (0 to 100)
    $score = 100;
    if ($total_borrowed > 0) {
        $score = ($total_paid / $total_borrowed) * 100;
    }

    // 5. Check Defaulter Status (Overdue bonds)
    $overdue_stmt = $pdo->prepare("SELECT COUNT(*) FROM bonds WHERE customer_id = ? AND status = 'overdue'");
    $overdue_stmt->execute([$cid]);
    $is_defaulter = ($overdue_stmt->fetchColumn() > 0);

    echo json_encode([
        'success' => true,
        'customer' => [
            'name' => $customer['name'],
            'unique_id' => $customer['unique_id'],
            'member_since' => date('M Y', strtotime($customer['created_at']))
        ],
        'credit_summary' => [
            'score' => round($score),
            'total_borrowed' => $total_borrowed,
            'total_paid' => $total_paid,
            'total_due' => $total_due,
            'active_bonds' => (int)$b_data['count'],
            'active_monthly' => (int)$m_data['count'],
            'is_defaulter' => $is_defaulter,
           'total_shops' => $pdo->query("SELECT COUNT(DISTINCT shop_id) FROM shop_customers WHERE customer_id = $cid")->fetchColumn(),
            'repayment_trend' => [] // Placeholder for now, will be populated below
        ],
        'repayment_trend' => [] // Initialize outside the summary
    ]);
    
} catch (Exception $e) {
    exit(json_encode(['success' => false, 'message' => $e->getMessage()]));
}