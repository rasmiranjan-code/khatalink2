<?php
/**
 * KhataLink Automation: Reminders & Warnings Processor
 * This script should be set up as a Daily Cron Job (Recommended: Twice a day).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_service.php';

try {
    // 1. BOND REMINDERS (3 days before until due date)
    $stmtBondRem = $pdo->prepare("
        SELECT b.id, b.customer_id, b.amount, b.paid_amount, b.due_date, s.shop_name 
        FROM bonds b
        JOIN shop_owners s ON b.shop_id = s.id
        WHERE b.status = 'active' 
          AND DATEDIFF(b.due_date, CURDATE()) BETWEEN 0 AND 3
    ");
    $stmtBondRem->execute();
    foreach ($stmtBondRem->fetchAll() as $bond) {
        $bal = $bond['amount'] - $bond['paid_amount'];
        $title = "Bond Payment Reminder 📜";
        $body = "Namaste! Aapka " . $bond['shop_name'] . " ke sath Bond #" . $bond['id'] . " ka installment due hai. Pending balance: ₹" . number_format($bal, 2) . ". Kripya samay par settle karein.";
        sendKhataPush($pdo, (int)$bond['customer_id'], 'customer', $title, $body, ['type' => 'bond', 'id' => (string)$bond['id']]);
    }

    // 2. BOND OVERDUE WARNINGS (After due date)
    $stmtBondWarn = $pdo->prepare("
        SELECT b.id, b.customer_id, b.amount, b.paid_amount, s.shop_name 
        FROM bonds b
        JOIN shop_owners s ON b.shop_id = s.id
        WHERE b.status IN ('active', 'overdue') 
          AND b.due_date < CURDATE()
    ");
    $stmtBondWarn->execute();
    foreach ($stmtBondWarn->fetchAll() as $bond) {
        // Automatically mark as overdue if not already
        $pdo->prepare("UPDATE bonds SET status = 'overdue' WHERE id = ? AND status = 'active'")->execute([$bond['id']]);
        
        $bal = $bond['amount'] - $bond['paid_amount'];
        $title = "Bond Overdue Alert ⚠️";
        $body = "Aapka " . $bond['shop_name'] . " ke sath Bond #" . $bond['id'] . " overdue ho gaya hai. Pending balance: ₹" . number_format($bal, 2) . ". Kripya jald se jald bhugtan karein.";
        sendKhataPush($pdo, (int)$bond['customer_id'], 'customer', $title, $body, ['type' => 'bond', 'id' => (string)$bond['id']]);
    }

    // 3. MONTHLY KHATA REMINDERS (Last 3 days of the 30-day cycle)
    $stmtMonthRem = $pdo->prepare("
        SELECT mk.id, mk.customer_id, mk.total_amount, s.shop_name 
        FROM monthly_khata mk
        JOIN shop_owners s ON mk.shop_id = s.id
        WHERE mk.status = 'open' 
          AND DATEDIFF(CURDATE(), mk.start_date) BETWEEN 27 AND 30
    ");
    $stmtMonthRem->execute();
    foreach ($stmtMonthRem->fetchAll() as $mk) {
        $title = "Monthly Bill Reminder 🗓️";
        $body = "Aapka " . $mk['shop_name'] . " ka is mahine ka khata cycle pura hone wala hai. Current Bill: ₹" . number_format($mk['total_amount'], 2) . ". Kripya payment ke liye taiyar rahein.";
        sendKhataPush($pdo, (int)$mk['customer_id'], 'customer', $title, $body, ['type' => 'monthly', 'id' => (string)$mk['id']]);
    }

    // 4. MONTHLY KHATA WARNINGS (Cycle older than 30 days)
    $stmtMonthWarn = $pdo->prepare("
        SELECT mk.id, mk.customer_id, mk.total_amount, s.shop_name 
        FROM monthly_khata mk
        JOIN shop_owners s ON mk.shop_id = s.id
        WHERE mk.status = 'open' 
          AND DATEDIFF(CURDATE(), mk.start_date) > 30
    ");
    $stmtMonthWarn->execute();
    foreach ($stmtMonthWarn->fetchAll() as $mk) {
        $title = "Monthly Bill Overdue ⚠️";
        $body = "Aapka " . $mk['shop_name'] . " ka pichle mahine ka bill ₹" . number_format($mk['total_amount'], 2) . " baki hai. Kripya dukan par sampark karke ise settle karein.";
        sendKhataPush($pdo, (int)$mk['customer_id'], 'customer', $title, $body, ['type' => 'monthly', 'id' => (string)$mk['id']]);
    }
} catch (Exception $e) {
    error_log("Reminders Automation Error: " . $e->getMessage());
}