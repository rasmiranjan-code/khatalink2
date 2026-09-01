<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/notification_service.php';

header('Content-Type: application/json');

if (!isset($_SESSION['shop_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

$shop_id = $_SESSION['shop_id'];
$stmt_shop = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
$stmt_shop->execute([$shop_id]);
$shop = $stmt_shop->fetch();
$shop_name = $shop['shop_name'] ?? 'Your Local Shop';


// --- Form Data ---
$name = trim($_POST['name'] ?? '');
$offer_type = $_POST['offer_type'] ?? 'percentage';
$offer_value = (float)($_POST['offer_value'] ?? 0);
$target_segment = $_POST['target_segment'] ?? 'all';
$message = trim($_POST['message'] ?? '');
$start_date = $_POST['start_date'] ?? date('Y-m-d');
$end_date = $_POST['end_date'] ?? date('Y-m-d');

// --- Validation ---
if (empty($name) || $offer_value <= 0 || empty($message) || empty($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Please fill all required fields.']);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Save the promotion
    $stmt = $pdo->prepare("INSERT INTO promotions (shop_id, name, offer_type, offer_value, target_segment, message, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$shop_id, $name, $offer_type, $offer_value, $target_segment, $message, $start_date, $end_date]);
    $promotion_id = $pdo->lastInsertId();

    // 2. Get target customer IDs based on segment
    $customer_ids = [];
    $base_customer_query = "SELECT customer_id FROM shop_customers WHERE shop_id = ?";

    switch ($target_segment) {
        case 'champions':
            // Top 20% spenders in the last 90 days
            $customer_ids = $pdo->query("
                SELECT customer_id FROM (
                    SELECT o.customer_id, SUM(o.total_amount) as total_spent
                    FROM orders o
                    WHERE o.shop_id = $shop_id AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND o.order_status = 'delivered'
                    GROUP BY o.customer_id
                    ORDER BY total_spent DESC
                ) as spenders
                LIMIT (SELECT CEIL(COUNT(DISTINCT customer_id) * 0.2) FROM orders WHERE shop_id = $shop_id)
            ")->fetchAll(PDO::FETCH_COLUMN);
            break;

        case 'at_risk':
            // Haven't ordered in the last 45 days but ordered before that
            $customer_ids = $pdo->query("
                SELECT DISTINCT customer_id FROM orders
                WHERE shop_id = $shop_id AND customer_id NOT IN (
                    SELECT DISTINCT customer_id FROM orders WHERE shop_id = $shop_id AND created_at >= DATE_SUB(NOW(), INTERVAL 45 DAY)
                )
            ")->fetchAll(PDO::FETCH_COLUMN);
            break;

        case 'new':
            // Joined in the last 30 days
            $stmt_new = $pdo->prepare("SELECT customer_id FROM shop_customers WHERE shop_id = ? AND added_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $stmt_new->execute([$shop_id]);
            $customer_ids = $stmt_new->fetchAll(PDO::FETCH_COLUMN);
            break;

        case 'all':
        default:
            $stmt_all = $pdo->prepare($base_customer_query);
            $stmt_all->execute([$shop_id]);
            $customer_ids = $stmt_all->fetchAll(PDO::FETCH_COLUMN);
            break;
    }

    if (empty($customer_ids)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'No customers found for the selected segment.']);
        exit();
    }

    // 3. Create entries in customer_promotions and send notifications
    $stmt_insert_cust_promo = $pdo->prepare("INSERT INTO customer_promotions (promotion_id, customer_id, shop_id) VALUES (?, ?, ?)");
    $notifications_sent = 0;

    foreach ($customer_ids as $customer_id) {
        $stmt_insert_cust_promo->execute([$promotion_id, $customer_id, $shop_id]);
        
        // Send Push Notification
        sendKhataPush(
            $pdo,
            (int)$customer_id,
            'customer',
            "Special Offer from " . $shop_name,
            $message,
            null,
            ['type' => 'promotion', 'promotion_id' => (string)$promotion_id]
        );
        $notifications_sent++;
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => "Promotion created and sent to $notifications_sent customers!"]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Promotion Creation Failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
?><?php
// This script should be run as a cron job daily.
// e.g., 0 2 * * * /usr/bin/php /path/to/your/project/includes/cron_calculate_customer_analytics.php

require_once __DIR__ . '/db.php';

echo "Starting Customer Analytics Calculation...\n";

try {
    $pdo->beginTransaction();

    // Step 1: Aggregate order data for all customers of all shops
    $pdo->exec("
        CREATE TEMPORARY TABLE temp_customer_stats AS
        SELECT
            o.shop_id,
            o.customer_id,
            COUNT(o.id) as total_orders,
            SUM(o.total_amount) as total_spent,
            MAX(o.created_at) as last_order_timestamp
        FROM orders o
        WHERE o.order_status = 'delivered'
        GROUP BY o.shop_id, o.customer_id;
    ");

    // Step 2: Clear the existing analytics table to repopulate
    $pdo->exec("TRUNCATE TABLE shop_customer_analytics;");

    // Step 3: Insert new aggregated data into the analytics table
    $pdo->exec("
        INSERT INTO shop_customer_analytics (shop_id, customer_id, total_orders, total_spent, last_order_date, days_since_last_order)
        SELECT
            t.shop_id,
            t.customer_id,
            t.total_orders,
            t.total_spent,
            DATE(t.last_order_timestamp),
            DATEDIFF(NOW(), t.last_order_timestamp)
        FROM temp_customer_stats t;
    ");

    // Step 4: Update customer segments based on the new data
    // These values can be tuned based on business logic.
    
    // LOST: Haven't ordered in > 90 days
    $pdo->exec("
        UPDATE shop_customer_analytics
        SET customer_segment = 'lost'
        WHERE days_since_last_order > 90;
    ");

    // AT-RISK: Haven't ordered in 31-90 days
    $pdo->exec("
        UPDATE shop_customer_analytics
        SET customer_segment = 'at_risk'
        WHERE days_since_last_order BETWEEN 31 AND 90;
    ");

    // LOYAL: Ordered in the last 30 days and has > 3 orders
    $pdo->exec("
        UPDATE shop_customer_analytics
        SET customer_segment = 'loyal'
        WHERE days_since_last_order <= 30 AND total_orders > 3;
    ");

    // CHAMPIONS: Top 15% of spenders who are also loyal
    $pdo->exec("
        UPDATE shop_customer_analytics a
        JOIN (
            SELECT customer_id, shop_id FROM shop_customer_analytics ORDER BY total_spent DESC LIMIT (SELECT CEIL(COUNT(*) * 0.15) FROM shop_customer_analytics)
        ) as top_spenders ON a.customer_id = top_spenders.customer_id AND a.shop_id = top_spenders.shop_id
        SET a.customer_segment = 'champion'
        WHERE a.customer_segment = 'loyal';
    ");

    $pdo->commit();
    echo "Customer analytics calculation completed successfully.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("CRON FAILED: cron_calculate_customer_analytics.php - " . $e->getMessage());
    echo "An error occurred: " . $e->getMessage() . "\n";
}
?>