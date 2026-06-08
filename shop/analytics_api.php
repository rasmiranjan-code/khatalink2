<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

// Monthly Credit vs Payments (Last 6 months)
$monthly_query = "
    SELECT
        DATE_FORMAT(dates.date, '%b %Y') as month_label,
        COALESCE(SUM(ue.total_amount), 0) as total_credit,
        (SELECT COALESCE(SUM(ph.amount_paid), 0)
         FROM payment_history ph
         WHERE ph.shop_id = :sid1
         AND DATE_FORMAT(ph.payment_date, '%Y-%m') = DATE_FORMAT(dates.date, '%Y-%m')) as total_paid
    FROM (
        SELECT CURDATE() as date
        UNION ALL SELECT CURDATE() - INTERVAL 1 MONTH
        UNION ALL SELECT CURDATE() - INTERVAL 2 MONTH
        UNION ALL SELECT CURDATE() - INTERVAL 3 MONTH
        UNION ALL SELECT CURDATE() - INTERVAL 4 MONTH
        UNION ALL SELECT CURDATE() - INTERVAL 5 MONTH
    ) dates
    LEFT JOIN udhar_entries ue ON ue.shop_id = :sid2
    AND DATE_FORMAT(ue.created_at, '%Y-%m') = DATE_FORMAT(dates.date, '%Y-%m')
    GROUP BY month_label, dates.date
    ORDER BY dates.date ASC
";
$stmt_monthly = $pdo->prepare($monthly_query);
$stmt_monthly->execute(['sid1' => $shop_id, 'sid2' => $shop_id]);
$monthly_stats = $stmt_monthly->fetchAll();

$labels = []; $credit_data = []; $paid_data = [];
foreach($monthly_stats as $row) {
    $labels[] = $row['month_label'];
    $credit_data[] = (float)$row['total_credit'];
    $paid_data[] = (float)$row['total_paid'];
}

// All-time Summary
$stmt_sum = $pdo->prepare("
    SELECT
        (SELECT COALESCE(SUM(total_remaining),0) FROM udhar_entries WHERE shop_id =? AND status='open') as total_due,
        (SELECT COALESCE(SUM(amount_paid),0) FROM payment_history WHERE shop_id =?) as total_coll,
        (SELECT COALESCE(SUM(total_amount),0) FROM udhar_entries WHERE shop_id =?) as total_credit
");
$stmt_sum->execute([$shop_id, $shop_id, $shop_id]);
$summary = $stmt_sum->fetch();

// Top 5 Customers by Due
$stmt_top = $pdo->prepare("
    SELECT c.name, COALESCE(SUM(ue.total_remaining), 0) as total_due
    FROM customers c
    JOIN shop_customers sc ON c.id = sc.customer_id
    LEFT JOIN udhar_entries ue ON c.id = ue.customer_id AND ue.shop_id =? AND ue.status = 'open'
    WHERE sc.shop_id =?
    GROUP BY c.id
    ORDER BY total_due DESC
    LIMIT 5
");
$stmt_top->execute([$shop_id, $shop_id]);
$top_customers = $stmt_top->fetchAll();

$cust_names = []; $cust_dues = [];
foreach($top_customers as $tc) {
    $cust_names[] = $tc['name'];
    $cust_dues[] = (float)$tc['total_due'];
}

echo json_encode([
    'success' => true,
    'summary' => [
        'total_credit' => (float)$summary['total_credit'],
        'total_coll' => (float)$summary['total_coll'],
        'total_due' => (float)$summary['total_due']
    ],
    'monthly' => [
        'labels' => $labels,
        'credit_data' => $credit_data,
        'paid_data' => $paid_data
    ],
    'top_customers' => [
        'names' => $cust_names,
        'dues' => $cust_dues
    ]
]);
?>