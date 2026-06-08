<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db.php';
ob_start();

try {
    // ── TOKEN AUTH ──────────────────────────────────────────────────
    $headers = getallheaders();
    $token = $headers['Authorization']?? $headers['authorization']?? '';
    $token = str_replace('Bearer ', '', $token);

    if (empty($token)) {
        ob_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Auth token missing']);
        exit();
    }

    $decoded = base64_decode($token);
    if ($decoded === false) {
        ob_clean();
        error_log("Monthly Khata API: Invalid token format for token: " . $token);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid token format']);
        exit();
    }

    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0]?? 0);
    $role = $parts[2]?? '';

    if (!$shop_id || $role!== 'shop') {
        error_log("Monthly Khata API: Unauthorized access for shop_id: $shop_id, role: $role");
        ob_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit();
    }

    $action = $_GET['action']?? '';

    // 1. Fetch all active monthly khatas for shop
    if ($action === 'list') {
        $stmt = $pdo->prepare("
            SELECT mk.id,
                   mk.shop_id,
                   mk.customer_id,
                   mk.start_date,
                   mk.total_amount,
                   mk.status,
                   mk.paid_amount,
                   mk.razorpay_payment_id,
                   mk.created_at,
                   c.name,
                   c.unique_id,
                   DATEDIFF(CURDATE(), mk.start_date) as days_passed
            FROM monthly_khata mk
            JOIN customers c ON mk.customer_id = c.id
            WHERE mk.shop_id =? AND mk.status = 'open'
            ORDER BY mk.created_at DESC
        ");
        $stmt->execute([$shop_id]);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted_list = array_map(function($row) {
            $days = (int)$row['days_passed'];
            return [
                'id' => (int)$row['id'],
                'shop_id' => (int)$row['shop_id'],
                'customer_id' => (int)$row['customer_id'],
                'name' => $row['name'],
                'unique_id' => $row['unique_id'],
                'start_date' => $row['start_date'],
                'total_amount' => (float)$row['total_amount'],
                'paid_amount' => (float)($row['paid_amount']?? 0),
                'status' => $row['status'],
                'razorpay_payment_id' => $row['razorpay_payment_id'],
                'days_passed' => $days,
                'is_overdue' => $days >= 25,
                'created_at' => $row['created_at']
            ];
        }, $list);

        ob_clean();
        echo json_encode(['success' => true, 'data' => $formatted_list]);
        exit();
    }

    // 2. Start new cycle
    if ($action === 'start') {
        $data = json_decode(file_get_contents('php://input'), true);
        $customer_id = (int)($data['customer_id']?? 0);

        if (!$customer_id) {
            throw new Exception('Customer ID required');
        }

        // Check if active cycle already exists
        $stmt_check = $pdo->prepare("SELECT id FROM monthly_khata WHERE shop_id =? AND customer_id =? AND status = 'open'");
        $stmt_check->execute([$shop_id, $customer_id]);
        if ($stmt_check->fetch()) {
            throw new Exception('Active monthly cycle already exists for this customer');
        }

        $stmt = $pdo->prepare("INSERT INTO monthly_khata (shop_id, customer_id, start_date, status, total_amount) VALUES (?,?, CURDATE(), 'open', 0)");
        $success = $stmt->execute([$shop_id, $customer_id]);

        ob_clean();
        echo json_encode(['success' => $success, 'message' => $success? 'Cycle started successfully' : 'Failed to start cycle']);
        exit();
    }

    // 3. Manage Items - Fetch
    if ($action === 'items') {
        $khata_id = (int)($_GET['khata_id']?? 0);
        if (!$khata_id) throw new Exception('Khata ID required');

        // Verify ownership
        $stmt_check = $pdo->prepare("SELECT id FROM monthly_khata WHERE id =? AND shop_id =?");
        $stmt_check->execute([$khata_id, $shop_id]);
        if (!$stmt_check->fetch()) {
            throw new Exception('Access denied: Khata not found');
        }

        $stmt = $pdo->prepare("
            SELECT id, khata_id, item_name, quantity, rate, amount, item_date
            FROM monthly_khata_items
            WHERE khata_id =?
            ORDER BY item_date DESC, id DESC
        ");
        $stmt->execute([$khata_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted_items = array_map(function($item) {
            return [
                'id' => (int)$item['id'],
                'khata_id' => (int)$item['khata_id'],
                'item_name' => $item['item_name'],
                'quantity' => (float)$item['quantity'],
                'rate' => (float)$item['rate'],
                'amount' => (float)$item['amount'],
                'item_date' => $item['item_date']
            ];
        }, $items);

        ob_clean();
        echo json_encode(['success' => true, 'data' => $formatted_items]);
        exit();
    }

    // 4. Add Item
    if ($action === 'add_item') {
        $data = json_decode(file_get_contents('php://input'), true);
        $khata_id = (int)($data['khata_id']?? 0);
        $item_name = trim($data['item_name']?? '');
        $qty = (float)($data['qty']?? 0);
        $rate = (float)($data['rate']?? 0);
        $item_date = $data['item_date']?? date('Y-m-d');

        if (!$khata_id || empty($item_name) || $qty <= 0 || $rate <= 0) {
            throw new Exception('Invalid item data: All fields required');
        }

        // Verify ownership and status
        $stmt_check = $pdo->prepare("SELECT id FROM monthly_khata WHERE id =? AND shop_id =? AND status = 'open'");
        $stmt_check->execute([$khata_id, $shop_id]);
        if (!$stmt_check->fetch()) {
            throw new Exception('Khata not found or already closed');
        }

        $amount = $qty * $rate;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO monthly_khata_items (khata_id, item_name, quantity, rate, amount, item_date) VALUES (?,?,?,?,?,?)")
                ->execute([$khata_id, $item_name, $qty, $rate, $amount, $item_date]);

            $pdo->prepare("UPDATE monthly_khata SET total_amount = total_amount +? WHERE id =?")
                ->execute([$amount, $khata_id]);

            // Fetch customer and shop name for notification
            $stmt_info = $pdo->prepare("SELECT mk.customer_id, c.name as customer_name, s.shop_name FROM monthly_khata mk JOIN customers c ON mk.customer_id = c.id JOIN shop_owners s ON mk.shop_id = s.id WHERE mk.id = ?");
            $stmt_info->execute([$khata_id]); $info = $stmt_info->fetch();
            sendKhataPush($pdo, (int)$info['customer_id'], 'customer', "Monthly Update: " . $info['shop_name'], "₹" . number_format($amount, 2) . " add kiya gaya: $item_name ($qty qty).", ['type' => 'monthly_item_added', 'khata_id' => (string)$khata_id]);

            // Deduct from Inventory and check for low stock
            $stmt_pid = $pdo->prepare("SELECT id FROM inventory_products WHERE shop_id = ? AND name = ?");
            $stmt_pid->execute([$shop_id, $item_name]);
            $product_id = (int)$stmt_pid->fetchColumn();
            if ($product_id > 0) {
                $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock - ? WHERE id = ? AND shop_id = ? AND current_stock >= ?")->execute([$qty, $product_id, $shop_id, $qty]);
                notifyStockDeduction($pdo, $shop_id, $product_id);
                checkInventoryAlert($pdo, $shop_id, $product_id);
            }

            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Item added successfully']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        exit();
    }

    // 5. Edit Item
    if ($action === 'edit_item') {
        $data = json_decode(file_get_contents('php://input'), true);
        $item_id = (int)($data['item_id']?? 0);
        $khata_id = (int)($data['khata_id']?? 0);
        $item_name = trim($data['item_name']?? '');
        $qty = (float)($data['qty']?? 0);
        $rate = (float)($data['rate']?? 0);
        $item_date = $data['item_date']?? date('Y-m-d');

        if (!$item_id ||!$khata_id || empty($item_name) || $qty <= 0 || $rate <= 0) {
            throw new Exception('Invalid item data');
        }

        // Verify ownership
        $stmt_check = $pdo->prepare("SELECT id FROM monthly_khata WHERE id =? AND shop_id =? AND status = 'open'");
        $stmt_check->execute([$khata_id, $shop_id]);
        if (!$stmt_check->fetch()) {
            throw new Exception('Khata not found or already closed');
        }

        // Get old amount
        $stmt_old = $pdo->prepare("SELECT amount FROM monthly_khata_items WHERE id =? AND khata_id =?");
        $stmt_old->execute([$item_id, $khata_id]);
        $old_amount = $stmt_old->fetchColumn();

        if ($old_amount === false) {
            throw new Exception('Item not found');
        }

        $new_amount = $qty * $rate;
        $amount_diff = $new_amount - (float)$old_amount;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE monthly_khata_items SET item_name =?, quantity =?, rate =?, amount =?, item_date =? WHERE id =?")
                ->execute([$item_name, $qty, $rate, $new_amount, $item_date, $item_id]);

            $pdo->prepare("UPDATE monthly_khata SET total_amount = total_amount +? WHERE id =?")
                ->execute([$amount_diff, $khata_id]);

            // Fetch customer and shop name for notification
            $stmt_info = $pdo->prepare("SELECT mk.customer_id, c.name as customer_name, s.shop_name FROM monthly_khata mk JOIN customers c ON mk.customer_id = c.id JOIN shop_owners s ON mk.shop_id = s.id WHERE mk.id = ?");
            $stmt_info->execute([$khata_id]); $info = $stmt_info->fetch();
            sendKhataPush($pdo, (int)$info['customer_id'], 'customer', "Monthly Update: " . $info['shop_name'], "₹" . number_format($new_amount, 2) . " ka bill item update kiya gaya hai.", ['type' => 'monthly_item_updated', 'khata_id' => (string)$khata_id]);

            // Adjust Inventory and check for low stock
            $stmt_pid = $pdo->prepare("SELECT id FROM inventory_products WHERE shop_id = ? AND name = ?");
            $stmt_pid->execute([$shop_id, $item_name]);
            $product_id = (int)$stmt_pid->fetchColumn();
            if ($product_id > 0) {
                notifyStockDeduction($pdo, $shop_id, $product_id);
                checkInventoryAlert($pdo, $shop_id, $product_id);
            }

            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Item updated successfully']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        exit();
    }

    // 6. Delete Item
    if ($action === 'delete_item') {
        $data = json_decode(file_get_contents('php://input'), true);
        $item_id = (int)($data['item_id']?? 0);
        $khata_id = (int)($data['khata_id']?? 0);

        if (!$item_id ||!$khata_id) {
            throw new Exception('Item ID and Khata ID required');
        }

        // Verify ownership
        $stmt_check = $pdo->prepare("SELECT id FROM monthly_khata WHERE id =? AND shop_id =? AND status = 'open'");
        $stmt_check->execute([$khata_id, $shop_id]);
        if (!$stmt_check->fetch()) {
            throw new Exception('Khata not found or already closed');
        }

        $stmt = $pdo->prepare("SELECT amount, item_name, quantity FROM monthly_khata_items WHERE id =? AND khata_id =?");
        $stmt->execute([$item_id, $khata_id]);
        $item_data = $stmt->fetch();

        if (!$item_data) {
            throw new Exception('Item not found');
        }
        $amt = (float)$item_data['amount'];
        $item_name = $item_data['item_name'];
        $qty = (float)$item_data['quantity'];

        $pdo->beginTransaction();
        try {
            // Add back to Inventory
            $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock + ? WHERE shop_id = ? AND name = ?")
                ->execute([$qty, $shop_id, $item_name]);

            $pdo->prepare("DELETE FROM monthly_khata_items WHERE id =?")->execute([$item_id]);
            $pdo->prepare("UPDATE monthly_khata SET total_amount = total_amount -? WHERE id =?")->execute([$amt, $khata_id]);

            // Fetch customer and shop name for notification
            $stmt_info = $pdo->prepare("SELECT mk.customer_id, c.name as customer_name, s.shop_name FROM monthly_khata mk JOIN customers c ON mk.customer_id = c.id JOIN shop_owners s ON mk.shop_id = s.id WHERE mk.id = ?");
            $stmt_info->execute([$khata_id]); $info = $stmt_info->fetch();
            sendKhataPush($pdo, (int)$info['customer_id'], 'customer', "Monthly Update: " . $info['shop_name'], "₹" . number_format($amt, 2) . " ka bill item remove kiya gaya hai.", ['type' => 'monthly_item_removed', 'khata_id' => (string)$khata_id]);

            // Add back to Inventory and check for low stock
            $stmt_pid = $pdo->prepare("SELECT id FROM inventory_products WHERE shop_id = ? AND name = ?");
            $stmt_pid->execute([$shop_id, $item_name]);
            $product_id = (int)$stmt_pid->fetchColumn();
            if ($product_id > 0) checkInventoryAlert($pdo, $shop_id, $product_id);

            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Item deleted successfully']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        exit();
    }

    // 7. Settle/Close Cycle
    if ($action === 'settle') {
        $data = json_decode(file_get_contents('php://input'), true);
        $khata_id = (int)($data['khata_id']?? 0);
        $amount = (float)($data['amount']?? 0);
        $mode = $data['mode']?? 'Cash';

        if (!$khata_id || $amount <= 0) {
            throw new Exception('Invalid settlement data');
        }

        // Verify ownership - check for 'open' status
        $stmt_check = $pdo->prepare("SELECT id, total_amount, status FROM monthly_khata WHERE id =? AND shop_id =? AND status = 'open'");
        $stmt_check->execute([$khata_id, $shop_id]);
        $khata = $stmt_check->fetch();

        if (!$khata) {
            throw new Exception('Khata not found or already closed');
        }

        $razorpay_id = ($mode === 'Cash')? 'Manual' : 'Online_'. time();

        // Update status to 'closed'
        $stmt = $pdo->prepare("UPDATE monthly_khata SET status = 'closed', paid_amount =?, razorpay_payment_id =?, paid_at = NOW() WHERE id =?");
        $success = $stmt->execute([$amount, $razorpay_id, $khata_id]);

        // Fetch customer and shop name for notification
        $stmt_info = $pdo->prepare("SELECT mk.customer_id, c.name as customer_name, s.shop_name FROM monthly_khata mk JOIN customers c ON mk.customer_id = c.id JOIN shop_owners s ON mk.shop_id = s.id WHERE mk.id = ?");
        $stmt_info->execute([$khata_id]); $info = $stmt_info->fetch();
        sendKhataPush($pdo, (int)$info['customer_id'], 'customer', "Monthly Khata Settled! ✅", "Aapka ₹" . number_format($amount, 2) . " ka monthly bill {$info['shop_name']} ne record kar liya hai. Dhanyawad!", ['type' => 'monthly_settled', 'khata_id' => (string)$khata_id]);

        ob_clean();
        echo json_encode(['success' => $success, 'message' => $success? 'Cycle settled successfully' : 'Settlement failed']);
        exit();
    }

    // 8. Get available customers - Linked but no active cycle
    if ($action === 'available_customers') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.name, c.unique_id
            FROM shop_customers sc
            JOIN customers c ON sc.customer_id = c.id
            WHERE sc.shop_id =?
            AND c.id NOT IN (
                SELECT customer_id FROM monthly_khata
                WHERE shop_id =? AND status = 'open'
            )
            ORDER BY c.name ASC
        ");
        $stmt->execute([$shop_id, $shop_id]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function($c) {
            return [
                'id' => (int)$c['id'],
                'name' => $c['name'],
                'unique_id' => $c['unique_id']
            ];
        }, $customers);

        ob_clean();
        echo json_encode(['success' => true, 'data' => $formatted]);
        exit();
    }

    // ── NEW: 9. HISTORY CASE - YE ADD KIYA ────────────────────────────
    if ($action === 'history') {
        $from_date = $_GET['from_date']?? date('Y-m-01', strtotime('-1 year'));
        $to_date = $_GET['to_date']?? date('Y-m-d');
        $customer_filter = (int)($_GET['customer_id']?? 0);

        $query = "
            SELECT
                mk.id,
                mk.shop_id,
                mk.customer_id,
                mk.start_date,
                mk.total_amount,
                mk.status,
                mk.paid_amount,
                mk.razorpay_payment_id,
                mk.created_at,
                c.name as customer_name,
                c.unique_id,
                DATEDIFF(CURDATE(), mk.start_date) as days_passed
            FROM monthly_khata mk
            JOIN customers c ON mk.customer_id = c.id
            WHERE mk.shop_id =?
            AND mk.start_date BETWEEN? AND?
        ";
        $params = [$shop_id, $from_date, $to_date];

        if ($customer_filter > 0) {
            $query.= " AND mk.customer_id =?";
            $params[] = $customer_filter;
        }

        $query.= " ORDER BY mk.start_date DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_bill = 0;
        $formatted_cycles = array_map(function($row) use (&$total_bill) {
            $days = (int)$row['days_passed'];
            $is_paid = ($row['status'] == 'closed');
            $total_bill += (float)$row['total_amount'];

            return [
                'id' => (int)$row['id'],
                'shop_id' => (int)$row['shop_id'],
                'customer_id' => (int)$row['customer_id'],
                'customer_name' => $row['customer_name'],
                'unique_id' => $row['unique_id'],
                'start_date' => $row['start_date'],
                'total_amount' => (float)$row['total_amount'],
                'paid_amount' => (float)($row['paid_amount']?? 0),
                'status' => $row['status'],
                'razorpay_payment_id' => $row['razorpay_payment_id'],
                'days_passed' => $days,
                'is_overdue' => $days >= 25 &&!$is_paid,
                'is_paid' => $is_paid,
                'payment_mode' => ($row['razorpay_payment_id'] === 'Manual')? 'Cash' : (empty($row['razorpay_payment_id'])? 'PENDING' : 'ONLINE'),
                'created_at' => $row['created_at']
            ];
        }, $cycles);

        ob_clean();
        echo json_encode([
            'success' => true,
            'cycles' => $formatted_cycles,
            'total_bill_amount' => $total_bill
        ]);
        exit();
    }

    // Invalid action
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action: '. $action]);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit();
?>