<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
require_once '../includes/notification_service.php';
track_visitor($pdo);

if(!isset($_SESSION['shop_id'])) {
    // ===== FLUTTER API =====
    if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
        header('Content-Type: application/json');
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        $decoded = base64_decode($token);
        $parts = explode(':', $decoded);
        $shop_id_api = $parts[0] ?? 0;

        if (!$shop_id_api) { echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit(); }

        // Fetch Shop Name for API (Used in notifications)
        $stmt_shop_info = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
        $stmt_shop_info->execute([$shop_id_api]);
        $shop_name = $stmt_shop_info->fetchColumn() ?: 'Shop';

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $customer_id = (int)($_GET['customer_id'] ?? 0);
            $stmt_fields = $pdo->prepare("SELECT id, field_name FROM shop_fields WHERE shop_id = ? AND (customer_id = ? OR customer_id IS NULL) AND is_active = 1 ORDER BY field_order ASC");
            $stmt_fields->execute([$shop_id_api, $customer_id]);
            $fields = $stmt_fields->fetchAll();

            $stmt_disc = $pdo->prepare("SELECT ct.discount_percentage FROM shop_customers sc JOIN customer_tiers ct ON sc.tier_id = ct.id WHERE sc.shop_id = ? AND sc.customer_id = ?");
            $stmt_disc->execute([$shop_id_api, $customer_id]);
            $discount = (float)($stmt_disc->fetchColumn() ?: 0);
            echo json_encode(['success' => true, 'fields' => $fields, 'customer_discount' => $discount]);
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            $customer_id = (int)($data['customer_id'] ?? 0);
            $items = $data['items'] ?? [];
            $discount_pct = (float)($data['discount_percentage'] ?? 0);
            if ($customer_id <= 0 || empty($items)) {
                echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit();
            }
            try {
                $pdo->beginTransaction();
                $total_raw = 0;
                foreach($items as $item) { $total_raw += ($item['qty'] * $item['rate']); }
                $discount_amt = $total_raw * ($discount_pct / 100);
                $final_rem = $total_raw - $discount_amt;
                $stmt = $pdo->prepare("INSERT INTO udhar_entries (shop_id, customer_id, total_amount, total_remaining, discount_percentage, status) VALUES (?, ?, ?, ?, ?, 'open')");
                $stmt->execute([$shop_id_api, $customer_id, $total_raw, $final_rem, $discount_pct]);
                $entry_id = $pdo->lastInsertId();
                $stmt_item = $pdo->prepare("INSERT INTO udhar_items (entry_id, field_id, field_name, quantity, rate, amount, product_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_deduct = $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock - ? WHERE id = ? AND shop_id = ? AND current_stock >= ?");
                
                $item_summaries = [];
                foreach ($items as $it) {
                    $amt = $it['qty'] * $it['rate'];
                    $field_name = $it['field_name'] ?? 'Custom Item';
                    $product_id = (int)($it['product_id'] ?? 0);
                    $stmt_item->execute([$entry_id, $it['field_id'] ?? null, $field_name, $it['qty'], $it['rate'], $amt, $product_id > 0 ? $product_id : null]);
                    
                    $item_summaries[] = "$field_name ({$it['qty']}x{$it['rate']})";

                    if ($product_id > 0) { 
                        $stmt_deduct->execute([$it['qty'], $product_id, $shop_id_api, $it['qty']]);
                        notifyStockDeduction($pdo, $shop_id_api, $product_id); // Notify shop about deduction
                        checkInventoryAlert($pdo, $shop_id_api, $product_id); // Alert if stock low
                    }
                }

                // Calculate New Total Balance for Customer
                $stmt_bal = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ? AND shop_id = ? AND status = 'open'");
                $stmt_bal->execute([$customer_id, $shop_id_api]);
                $total_shop_due = $stmt_bal->fetchColumn();

                // Send Customer Notification
                $msg = "₹" . number_format($final_rem, 2) . " add kiye gaye hain.\nSaaman: " . implode(', ', $item_summaries) . ".\nKul baki udhar: ₹" . number_format($total_shop_due, 2);
                sendKhataPush($pdo, $customer_id, 'customer', "Naya Udhar: $shop_name", $msg, ['type' => 'ledger', 'shop_id' => (string)$shop_id_api]);

                $pdo->commit();
                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
                    echo json_encode(['success' => true, 'message' => 'Udhar recorded successfully!']);
                    exit();
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit();
            }
        }
    } else {
        header("Location: ../auth/login.php?type=shop");
        exit();
    }
}

$shop_id = $_SESSION['shop_id'];
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : (isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0);
$error = $_GET['error'] ?? '';
$customer_discount = 0.0;
$success = $_GET['success'] ?? '';

$stmt_cust = $pdo->prepare("
    SELECT c.id, c.name, c.unique_id 
    FROM customers c 
    JOIN shop_customers sc ON c.id = sc.customer_id 
    WHERE sc.shop_id = ? 
    ORDER BY c.name ASC
");
$stmt_cust->execute([$shop_id]);
$customers = $stmt_cust->fetchAll();

// ✅ FIX 1: products_for_autofill aur products_json sahi jagah initialize
$products_for_autofill = [];
$products_json = '[]';

$fields = [];
if ($customer_id > 0) {
    $stmt_fields = $pdo->prepare("SELECT id, field_name FROM shop_fields WHERE shop_id = ? AND (customer_id = ? OR customer_id IS NULL) AND is_active = 1 ORDER BY field_order ASC");
    $stmt_fields->execute([$shop_id, $customer_id]);
    $fields = $stmt_fields->fetchAll();

    $stmt_cust_discount = $pdo->prepare("
        SELECT ct.discount_percentage 
        FROM shop_customers sc 
        JOIN customer_tiers ct ON sc.tier_id = ct.id 
        WHERE sc.shop_id = ? AND sc.customer_id = ?
    ");
    $stmt_cust_discount->execute([$shop_id, $customer_id]);
    $customer_discount = (float)($stmt_cust_discount->fetchColumn() ?: 0);

    // ✅ FIX 2: Products fetch karo aur products_json UPDATE karo
    $stmt_products = $pdo->prepare("
        SELECT id, name, sale_price, primary_unit, gst_percent, current_stock
        FROM inventory_products
        WHERE shop_id = ?
        ORDER BY name ASC
    ");
    $stmt_products->execute([$shop_id]);
    $products_for_autofill = $stmt_products->fetchAll(PDO::FETCH_ASSOC);
    $products_json = json_encode($products_for_autofill); // ✅ Yahan update hoga
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $qtys = $_POST['qty'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $p_ids = $_POST['product_id'] ?? [];

    if (empty($customer_id)) {
        $error = "Please select a customer.";
    } else {
        $total_amount = 0;
        $valid_items = [];
        $item_summaries = [];

        foreach ($qtys as $f_id => $q) {
            $qty = (float)$q;
            $rate = (float)($rates[$f_id] ?? 0);
            if ($qty > 0 && $rate > 0) {
                $amt = $qty * $rate;
                $total_amount += $amt;
                
                $f_name = 'Item';
                foreach($fields as $f) { if($f['id'] == $f_id) $f_name = $f['field_name']; }
                
                $valid_items[$f_id] = ['qty' => $qty, 'rate' => $rate, 'amt' => $amt, 'product_id' => $p_ids[$f_id] ?? null, 'name' => $f_name];
                $item_summaries[] = "$f_name ({$qty}x{$rate})";
            }
        }

        $discount_amount = $total_amount * ($customer_discount / 100);
        $final_amount = $total_amount - $discount_amount;

        if ($total_amount <= 0) {
            $error = "Please enter an amount for at least one field.";
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO udhar_entries (shop_id, customer_id, total_amount, total_remaining, discount_percentage, status) VALUES (?, ?, ?, ?, ?, 'open')");
                $stmt->execute([$shop_id, $customer_id, $total_amount, $final_amount, $customer_discount]);
                $entry_id = $pdo->lastInsertId();

                $stmt_item = $pdo->prepare("INSERT INTO udhar_items (entry_id, field_id, field_name, quantity, rate, amount, product_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_deduct = $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock - ? WHERE id = ? AND shop_id = ? AND current_stock >= ?");

                foreach ($valid_items as $f_id => $item) {
                    $product_id = (int)($item['product_id'] ?? 0);
                    $stmt_item->execute([$entry_id, $f_id, $item['name'], $item['qty'], $item['rate'], $item['amt'], $product_id > 0 ? $product_id : null]);
                    if($product_id > 0) {
                        $stmt_deduct->execute([$item['qty'], $product_id, $shop_id, $item['qty']]);
                        notifyStockDeduction($pdo, (int)$shop_id, $product_id); // Notify shop about deduction
                        checkInventoryAlert($pdo, $shop_id, $product_id); // Alert if stock low
                    }
                }

                // Calculate New Total Balance for Customer
                $stmt_bal = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ? AND shop_id = ? AND status = 'open'");
                $stmt_bal->execute([$customer_id, $shop_id]);
                $total_shop_due = $stmt_bal->fetchColumn();

                // Send Customer Notification
                $shop_name = $_SESSION['shop_name'];
                $msg = "₹" . number_format($final_amount, 2) . " add kiye gaye hain.\nSaaman: " . implode(', ', $item_summaries) . ".\nKul baki udhar: ₹" . number_format($total_shop_due, 2);
                sendKhataPush($pdo, $customer_id, 'customer', "Naya Udhar: $shop_name", $msg, ['type' => 'ledger', 'shop_id' => (string)$shop_id]);

                $pdo->commit();
                header("Location: udhar.php?customer_id=$customer_id&success=Udhar recorded successfully!");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "System Error: " . $e->getMessage();
            }
        }
    }
}

$selected_customer = null;
if($customer_id > 0) {
    foreach($customers as $c) {
        if($c['id'] == $customer_id) { $selected_customer = $c; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Udhar — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .ocr-loading { display: none; }
        .suggestions-box {
            position: absolute;
            width: calc(100% - 160px);
            z-index: 1000;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            max-height: 200px;
            overflow-y: auto;
            display: none;
        }
        .ocr-loading.flex { display: flex !important; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<!-- OCR Loading Overlay -->
<div class="fixed inset-0 z-[2000] bg-white/90 backdrop-blur-sm flex-col items-center justify-center gap-4 ocr-loading" id="ocrLoading">
    <div class="w-12 h-12 border-4 border-slate-200 border-t-blue-600 rounded-full animate-spin"></div>
    <div class="font-black text-slate-900 uppercase tracking-widest text-xs">AI is reading your bill...</div>
    <div class="text-[10px] text-slate-400 font-bold uppercase tracking-[0.2em]" id="ocrProgress">Starting engine...</div>
</div>

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="hidden sm:flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-wider">
        <i class="fas fa-store"></i>
        <?= htmlspecialchars($_SESSION['shop_name']) ?>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">

    <?php include '../includes/shop_sidebar.php'; ?>

    <div class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full">

        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">Add New Udhar</h1>
            <p class="text-slate-500 text-sm">Record a new credit entry for a customer.</p>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm shadow-slate-200/50">

            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between gap-4 bg-slate-50/50">
                <div class="text-sm font-black text-slate-900 flex items-center gap-2 uppercase tracking-tight">
                    <i class="fas fa-file-invoice text-blue-600"></i>
                    Entry Manifest
                </div>
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                    <?= date('d M Y') ?>
                </div>
            </div>

            <div class="p-6 md:p-10">

                <?php if($error): ?>
                <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-sm font-medium mb-6 flex items-center gap-3">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <?php if($success): ?>
                <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-medium mb-6 flex items-center gap-3">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="udharMainForm">

                    <!-- Customer Select -->
                    <div style="margin-bottom:20px;">
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Select Customer</label>
                        <select name="customer_id" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all cursor-pointer appearance-none" required
                            onchange="location.href='udhar.php?customer_id='+this.value">
                            <option value="">— Choose Customer —</option>
                            <?php foreach($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $customer_id == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (<?= $c['unique_id'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Selected Customer Badge -->
                    <?php if($selected_customer): ?>
                    <div class="flex items-center gap-4 bg-emerald-50 border border-emerald-100 rounded-2xl p-4 mb-8">
                        <div class="w-10 h-10 bg-emerald-600 text-white rounded-xl flex items-center justify-center text-sm"><i class="fas fa-user"></i></div>
                        <div>
                            <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($selected_customer['name']) ?></div>
                            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?= htmlspecialchars($selected_customer['unique_id']) ?></div>
                        </div>
                        <span class="ml-auto text-[10px] font-black text-emerald-600 bg-white border border-emerald-100 px-3 py-1 rounded-lg uppercase tracking-widest"><i class="fas fa-check me-1"></i> Active</span>
                    </div>
                    <?php endif; ?>

                    <!-- Scan + Clear Buttons -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
                        <label class="bg-indigo-50 border-2 border-dashed border-indigo-200 text-indigo-600 rounded-2xl p-4 flex items-center justify-center gap-3 cursor-pointer hover:bg-indigo-100 transition-all font-black uppercase tracking-widest text-[10px]">
                            <i class="fas fa-wand-magic-sparkles text-sm"></i> Scan Bill with AI (Beta)
                            <input type="file" id="billScanner" accept="image/*" capture="environment" class="hidden">
                        </label>
                        <button type="button" class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 flex items-center justify-center gap-3 hover:bg-red-600 hover:text-white transition-all font-black uppercase tracking-widest text-[10px]" onclick="resetUdharForm()">
                            <i class="fas fa-undo"></i> Clear
                        </button>
                    </div>

                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-4 pb-2 border-b border-slate-50">Line Items / Services</div>

                    <!-- Quick Add Item -->
                    <?php if($selected_customer): ?>
                    <div class="relative flex flex-col gap-1 mb-6">
                        <div class="flex gap-2">
                            <input type="text" id="quickAddItemName"
                                class="flex-1 bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all shadow-sm"
                                placeholder="Quick add item (e.g. Milk, Eggs)..."
                                oninput="searchInventoryForUdhar(this.value)"
                                onkeydown="if(event.key==='Enter'){event.preventDefault();addQuickItemToUdhar();}">
                            <button type="button"
                                class="bg-blue-600 text-white font-black px-6 py-4 rounded-2xl hover:bg-blue-700 transition-all flex items-center gap-2 uppercase tracking-widest text-[10px] shadow-lg shadow-blue-200"
                                onclick="addQuickItemToUdhar()">
                                <i class="fas fa-plus"></i> Add
                            </button>
                            <button type="button"
                                class="bg-slate-100 text-slate-500 font-black px-4 py-4 rounded-2xl hover:bg-red-50 hover:text-red-600 transition-all flex items-center gap-2 uppercase tracking-widest text-[9px]"
                                onclick="clearAllCustomerFields(<?= $customer_id ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        <!-- ✅ FIX 3: suggestions-box relative to parent div -->
                        <div id="quickAddItemSuggestions" class="suggestions-box"></div>
                    </div>
                    <?php endif; ?>

                    <div id="udharFieldsContainer" class="<?= (count($fields) > 0) ? '' : 'hidden' ?>">

                        <!-- Fields Table -->
                        <div class="bg-slate-50 border border-slate-200 rounded-3xl overflow-hidden mb-8" id="fieldsTable">
                            <div class="grid grid-cols-12 gap-2 px-6 py-4 bg-slate-100 border-b border-slate-200 text-[9px] font-black text-slate-400 uppercase tracking-widest items-center">
                                <span class="col-span-4">Description</span>
                                <span class="col-span-2 text-center">Qty</span>
                                <span class="col-span-3 text-center">Rate (₹)</span>
                                <span class="col-span-2 text-right">Subtotal</span>
                                <span class="col-span-1"></span>
                            </div>
                            <?php foreach($fields as $f): ?>
                            <div class="grid grid-cols-12 gap-2 px-4 md:px-6 py-4 items-center border-b border-slate-100 last:border-0 hover:bg-white transition-colors field-row" id="field-row-<?= $f['id'] ?>">
                                <div class="col-span-4 text-sm font-black text-slate-900 truncate"><?= htmlspecialchars($f['field_name']) ?></div>
                                <div class="col-span-2">
                                    <input type="hidden" name="product_id[<?= $f['id'] ?>]" value="" id="pid-<?= $f['id'] ?>">
                                    <input type="number" step="0.01" min="0" name="qty[<?= $f['id'] ?>]"
                                        class="w-full bg-white border-2 border-slate-100 rounded-xl p-2 text-center text-xs font-bold focus:border-blue-500 outline-none transition-all"
                                        placeholder="0"
                                        oninput="calcItem(<?= $f['id'] ?>)"
                                        data-field-name="<?= htmlspecialchars($f['field_name']) ?>">
                                </div>
                                <div class="col-span-3">
                                    <input type="number" step="0.01" min="0" name="rate[<?= $f['id'] ?>]"
                                        class="w-full bg-white border-2 border-slate-100 rounded-xl p-2 text-center text-xs font-bold focus:border-blue-500 outline-none transition-all"
                                        placeholder="0.00"
                                        oninput="calcItem(<?= $f['id'] ?>)">
                                </div>
                                <div class="col-span-2 text-right text-sm font-black text-slate-900" id="total-<?= $f['id'] ?>">₹0.00</div>
                                <div class="col-span-1 text-center">
                                    <button type="button" class="text-slate-300 hover:text-red-500 transition-colors" onclick="removeField(<?= $f['id'] ?>)">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Summary -->
                        <div class="space-y-3 mb-8">
                            <div class="flex items-center justify-between bg-slate-900 text-white rounded-2xl px-6 py-4 shadow-lg shadow-slate-200">
                                <div class="flex items-center gap-3">
                                    <i class="fas fa-calculator text-blue-400"></i>
                                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Aggregated Total</span>
                                </div>
                                <div class="text-xl font-black tracking-tight" id="grandTotal">₹0.00</div>
                            </div>

                            <?php if($customer_discount > 0): ?>
                            <div class="flex items-center justify-between bg-amber-50 border border-amber-100 text-amber-700 rounded-2xl px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <i class="fas fa-tag"></i>
                                    <span class="text-[10px] font-black uppercase tracking-widest">Tier Discount (<?= (int)$customer_discount ?>%)</span>
                                </div>
                                <div class="text-lg font-black tracking-tight" id="discountAmountDisplay">₹0.00</div>
                            </div>
                            <div class="flex items-center justify-between bg-emerald-600 text-white rounded-2xl px-6 py-5 shadow-lg shadow-emerald-100">
                                <div class="flex items-center gap-3">
                                    <i class="fas fa-receipt text-emerald-300"></i>
                                    <span class="text-[10px] font-black uppercase tracking-widest text-emerald-100">Final Ledger Amount</span>
                                </div>
                                <div class="text-2xl font-black tracking-tight" id="finalTotalDisplay">₹0.00</div>
                            </div>
                            <?php endif; ?>

                            <input type="hidden" name="final_total_amount" id="finalTotalInput">
                            <input type="hidden" name="customer_discount" id="customerDiscount" value="<?= $customer_discount ?>">
                        </div>

                        <button type="submit" class="w-full bg-slate-900 text-white font-black py-5 rounded-2xl hover:bg-blue-600 transition-all shadow-xl shadow-slate-200 flex items-center justify-center gap-3 uppercase tracking-widest text-xs">
                            <i class="fas fa-save"></i> Commit Ledger Entry
                        </button>

                    </div><!-- End udharFieldsContainer -->

                    <!-- No Fields Warning -->
                    <div class="text-center py-12 bg-white border border-slate-200 rounded-[2rem] shadow-sm <?= (count($fields) > 0) ? 'hidden' : '' ?>" id="noFieldsWarning">
                        <div class="w-16 h-16 bg-amber-50 text-amber-500 rounded-full flex items-center justify-center text-2xl mx-auto mb-6">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <h3 class="font-black text-slate-900 uppercase tracking-widest text-xs mb-2">No Items Cataloged</h3>
                        <p class="text-slate-400 text-xs font-medium max-w-sm mx-auto px-4">
                            <?php if($customer_id > 0): ?>
                            No custom fields found for this customer.
                            <a href="fields.php?customer_id=<?= $customer_id ?>" class="text-blue-600 font-black hover:underline">Add Fields Manually</a>
                            or use the AI Scanner to build them instantly.
                            <?php else: ?>
                            Please authorize a customer first to view items or start adding new ones.
                            <?php endif; ?>
                        </p>
                    </div>

                    <button type="submit" id="disabledSubmitBtn" class="w-full bg-slate-100 text-slate-400 font-black py-4 rounded-2xl mt-6 uppercase tracking-widest text-[10px] cursor-not-allowed <?= (count($fields) > 0) ? 'hidden' : '' ?>" disabled>
                        <i class="fas fa-lock me-1"></i> Save Entry
                    </button>

                </form>
            </div>
        </div>
    </div>
</div>

<div class="text-center p-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] bg-white border-t border-slate-100">
    © <?= date('Y') ?> KhataLink — India's Premium Digital Ledger
</div>

<script>
// ✅ FIX 4: inventoryProducts sirf EKBAR declare (const → let)
// PHP se populate hoga. Agar customer nahi chuna to empty array.
const inventoryProducts = <?= $products_json ?>; // [{ id, name, sale_price, primary_unit, current_stock }]
const customerDiscount  = <?= $customer_discount ?>;

// ── OCR ────────────────────────────────────────────────────────────────
const scanner      = document.getElementById('billScanner');
const ocrLoading   = document.getElementById('ocrLoading');
const ocrProgress  = document.getElementById('ocrProgress');

if (scanner) {
    scanner.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        ocrLoading.classList.add('flex');
        Tesseract.recognize(file, 'eng', {
            logger: m => {
                if (m.status === 'recognizing text')
                    ocrProgress.innerText = `Reading: ${Math.round(m.progress * 100)}%`;
            }
        }).then(({ data: { text } }) => {
            ocrLoading.classList.remove('flex');
            processOcrData(text);
        }).catch(err => {
            console.error('OCR failed:', err);
            ocrLoading.classList.remove('flex');
        });
    });
}

async function processOcrData(extractedText) {
    if (!extractedText) return;
    const customerId = document.querySelector('select[name="customer_id"]').value;
    if (!customerId) { alert('Please select a customer first.'); return; }

    const junk = ['total','date','gst','bill','subtotal','discount','amount','tax','cash','balance','mobile','address','invoice','summary','thanks'];
    const lines = extractedText.split('\n').map(l => l.trim()).filter(l => l.length > 3);

    for (let line of lines) {
        let clean = line.replace(/[₹|:\-Rs]/gi,' ').replace(/\s+/g,' ').trim();
        const nums    = clean.match(/\d+(\.\d+)?/g);
        const nameM   = clean.match(/[a-z\s]{3,}/i);
        if (!nameM || !nums || nums.length === 0) continue;

        let itemName = nameM[0].trim();
        if (junk.some(k => itemName.toLowerCase().includes(k))) continue;

        let rate = parseFloat(nums[nums.length - 1]);
        let qty  = nums.length >= 2 ? parseFloat(nums[0]) : 1;
        if (rate <= 0) continue;

        // Check inventory first
        const inv = inventoryProducts.find(p => p.name.toLowerCase() === itemName.toLowerCase());
        if (inv) {
            await addItemByName(itemName, qty, parseFloat(inv.sale_price), inv.id, customerId);
        } else {
            await addItemByName(itemName, qty, rate, null, customerId);
        }
    }
}

// ── Inventory Search ────────────────────────────────────────────────────
function searchInventoryForUdhar(query) {
    const box = document.getElementById('quickAddItemSuggestions');
    if (query.length < 2) { box.style.display = 'none'; return; }

    const filtered = inventoryProducts.filter(p =>
        p.name.toLowerCase().includes(query.toLowerCase())
    );

    if (filtered.length > 0) {
        box.innerHTML = filtered.map(p => `
            <div class="px-4 py-3 hover:bg-blue-50 cursor-pointer flex justify-between items-center border-b border-slate-100 last:border-0 transition-colors"
                 onclick="selectUdharSuggestion('${p.id}','${p.name.replace(/'/g,"\\'")}','${p.sale_price}','${p.primary_unit ?? ''}')">
                <div class="flex flex-col">
                    <span class="font-bold text-sm text-slate-900">${p.name}</span>
                    <span class="text-[10px] text-slate-400">Stock: ${parseFloat(p.current_stock ?? 0)} ${p.primary_unit ?? ''}</span>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <span class="text-xs font-black text-emerald-600">₹${parseFloat(p.sale_price).toFixed(2)}</span>
                    <span class="text-[9px] bg-blue-100 text-blue-600 font-black px-2 py-0.5 rounded-full uppercase tracking-wide">Tap to Add</span>
                </div>
            </div>
        `).join('');
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}

async function selectUdharSuggestion(id, name, rate, unit) {
    const input = document.getElementById('quickAddItemName');
    const cid   = document.querySelector('select[name="customer_id"]').value;

    // ✅ Set karo fields mein
    input.value = name;
    input.dataset.productId = id;
    input.dataset.rate = rate;
    input.dataset.unit = unit || '';
    document.getElementById('quickAddItemSuggestions').style.display = 'none';

    // ✅ DIRECT ADD — Add button dabane ki zaroorat nahi
    await addItemByName(name, 1, parseFloat(rate), id, cid);

    // Clear input after add
    input.value = '';
    delete input.dataset.productId;
    delete input.dataset.rate;
    delete input.dataset.unit;
    input.focus();
}

// ── Quick Add ────────────────────────────────────────────────────────────
async function addQuickItemToUdhar() {
    const input = document.getElementById('quickAddItemName');
    const name  = input.value.trim();
    const cid   = document.querySelector('select[name="customer_id"]').value;

    if (!name) { input.focus(); return; }
    if (!cid)  { alert('Please select a customer first.'); return; }

    const pid  = input.dataset.productId || null;
    const rate = parseFloat(input.dataset.rate) || 0;

    await addItemByName(name, 1, rate, pid, cid);

    // Clear input
    input.value = '';
    delete input.dataset.productId;
    delete input.dataset.rate;
    delete input.dataset.unit;
    document.getElementById('quickAddItemSuggestions').style.display = 'none';
    input.focus();
}

// ✅ FIX 5: Central function – ek hi jagah se field create + row add
async function addItemByName(name, qty, rate, productId, customerId) {
    // Agar row already exist karta hai to sirf update karo
    const existing = [...document.querySelectorAll('.field-row')].find(r => {
        const label = r.querySelector('.field-label');
        return label && label.innerText.trim().toLowerCase() === name.toLowerCase();
    });

    if (existing) {
        const rowId = existing.id.replace('field-row-','');
        const qtyInput  = document.querySelector(`input[name="qty[${rowId}]"]`);
        const rateInput = document.querySelector(`input[name="rate[${rowId}]"]`);
        qtyInput.value  = parseFloat(qtyInput.value || 0) + parseFloat(qty);
        if (rate > 0) rateInput.value = rate.toFixed(2);
        calcItem(rowId);
        return;
    }

    // Nayi field create karo
    try {
        const res  = await fetch('ajax_add_field.php', {
            method: 'POST',
            body: new URLSearchParams({ customer_id: customerId, field_name: name })
        });
        const data = await res.json();
        if (!data.success) { console.error('Field create failed:', data.message); return; }

        ensureFieldRowExists(data.id, name, productId);

        const qtyInput  = document.querySelector(`input[name="qty[${data.id}]"]`);
        const rateInput = document.querySelector(`input[name="rate[${data.id}]"]`);
        const pidInput  = document.querySelector(`input[name="product_id[${data.id}]"]`);

        if (qtyInput)  qtyInput.value  = parseFloat(qty);
        if (rateInput && rate > 0) rateInput.value = rate.toFixed(2);
        if (pidInput && productId)  pidInput.value  = productId;

        calcItem(data.id);
    } catch(e) {
        console.error('addItemByName error:', e);
    }
}

// ── Ensure Field Row ─────────────────────────────────────────────────────
function ensureFieldRowExists(id, name, pid = null) {
    if (document.getElementById(`field-row-${id}`)) return;

    const wrap = document.getElementById('fieldsTable');
    const row  = document.createElement('div');
    row.id        = `field-row-${id}`;
    row.className = 'grid grid-cols-12 gap-2 px-4 md:px-6 py-4 items-center border-b border-slate-100 hover:bg-white transition-colors field-row';
    row.innerHTML = `
        <div class="col-span-4 text-sm font-black text-slate-900 truncate field-label">${name}</div>
        <div class="col-span-2">
            <input type="hidden" name="product_id[${id}]" value="${pid || ''}">
            <input type="number" step="0.01" min="0" name="qty[${id}]"
                   class="w-full bg-white border-2 border-slate-100 rounded-xl p-2 text-center text-xs font-bold focus:border-blue-500 outline-none"
                   value="1" oninput="calcItem(${id})" data-field-name="${name}">
        </div>
        <div class="col-span-3">
            <input type="number" step="0.01" min="0" name="rate[${id}]"
                   class="w-full bg-white border-2 border-slate-100 rounded-xl p-2 text-center text-xs font-bold focus:border-blue-500 outline-none"
                   value="0.00" oninput="calcItem(${id})">
        </div>
        <div class="col-span-2 text-right text-sm font-black text-slate-900" id="total-${id}">₹0.00</div>
        <div class="col-span-1 text-center">
            <button type="button" class="text-slate-300 hover:text-red-500 transition-colors" onclick="removeField(${id})">
                <i class="fas fa-times-circle"></i>
            </button>
        </div>`;
    wrap.appendChild(row);

    document.getElementById('udharFieldsContainer').classList.remove('hidden');
    document.getElementById('noFieldsWarning').classList.add('hidden');
    document.getElementById('disabledSubmitBtn').classList.add('hidden');
}

// ── Calc ─────────────────────────────────────────────────────────────────
function calcItem(id) {
    const qtyInput  = document.querySelector(`input[name="qty[${id}]"]`);
    const rateInput = document.querySelector(`input[name="rate[${id}]"]`);
    const pidInput  = document.querySelector(`input[name="product_id[${id}]"]`);

    let qty  = parseFloat(qtyInput?.value)  || 0;
    let rate = parseFloat(rateInput?.value) || 0;

    // ✅ FIX 6: Auto rate suggestion from inventory
    if (rate === 0 && qty > 0 && qtyInput?.dataset?.fieldName) {
        const match = inventoryProducts.find(
            p => p.name.toLowerCase() === qtyInput.dataset.fieldName.toLowerCase()
        );
        if (match) {
            rate = parseFloat(match.sale_price);
            rateInput.value = rate.toFixed(2);
            if (pidInput) pidInput.value = match.id;
        }
    }

    const totalEl = document.getElementById(`total-${id}`);
    if (totalEl) totalEl.innerText = '₹' + (qty * rate).toFixed(2);

    calcGrandTotal();
}

function calcGrandTotal() {
    let grand = 0;
    document.querySelectorAll('[id^="total-"]').forEach(el => {
        grand += parseFloat(el.innerText.replace('₹','')) || 0;
    });
    document.getElementById('grandTotal').innerText = '₹' + grand.toFixed(2);

    // Discount
    const pct = parseFloat(document.getElementById('customerDiscount')?.value) || 0;
    const amt = grand * (pct / 100);
    const final = grand - amt;

    const discEl  = document.getElementById('discountAmountDisplay');
    const finalEl = document.getElementById('finalTotalDisplay');
    const inputEl = document.getElementById('finalTotalInput');

    if (discEl)  discEl.innerText  = '₹' + amt.toFixed(2);
    if (finalEl) finalEl.innerText = '₹' + final.toFixed(2);
    if (inputEl) inputEl.value     = final.toFixed(2);

    return grand;
}

// ── Remove Field ─────────────────────────────────────────────────────────
async function removeField(id) {
    if (!confirm('Remove this item?')) return;
    try {
        const res  = await fetch(`fields.php?customer_id=<?= (int)$customer_id ?>&delete=${id}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById(`field-row-${id}`)?.remove();
            calcGrandTotal();
        }
    } catch(e) { console.error('removeField error:', e); }
}

// ── Clear All ─────────────────────────────────────────────────────────────
async function clearAllCustomerFields(cid) {
    if (!confirm('Clear all items for this customer?')) return;
    try {
        const res  = await fetch(`fields.php?customer_id=${cid}&clear_all=1`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (data.success) location.reload();
    } catch(e) { console.error('clearAll error:', e); }
}

function resetUdharForm() {
    if (confirm('Clear all fields?')) location.reload();
}

// ── Sidebar ───────────────────────────────────────────────────────────────
function openSidebar()  {
    document.getElementById('sidebar')?.classList.add('open');
    document.getElementById('overlay')?.classList.remove('hidden');
}
function closeSidebar() {
    document.getElementById('sidebar')?.classList.remove('open');
    document.getElementById('overlay')?.classList.add('hidden');
}

// ── Close suggestions when clicked outside ─────────────────────────────
document.addEventListener('click', function(e) {
    const box   = document.getElementById('quickAddItemSuggestions');
    const input = document.getElementById('quickAddItemName');
    if (box && input && !input.contains(e.target) && !box.contains(e.target)) {
        box.style.display = 'none';
    }
});

// ── On load: recalculate existing rows ────────────────────────────────
window.addEventListener('load', () => {
    <?php foreach($fields as $f): ?>
    calcItem(<?= $f['id'] ?>);
    <?php endforeach; ?>
});
</script>

<!-- Firebase SDKs for Real-time alerts on this page -->
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js"></script>

<script>
    const firebaseConfig = {
        apiKey: "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
        authDomain: "khatalink-63041.firebaseapp.com",
        projectId: "khatalink-63041",
        messagingSenderId: "905429197043",
        appId: "1:905429197043:web:2a0cbefa0fa176fd2c5786"
    };

    if (!firebase.apps.length) {
        firebase.initializeApp(firebaseConfig);
    }
    const messaging = firebase.messaging();

    // Token register logic (to keep it fresh)
    async function syncToken() {
        try {
            const registration = await navigator.serviceWorker.register('../firebase-messaging-sw.js');
            await navigator.serviceWorker.ready;
            await messaging.getToken({ 
                vapidKey: 'BGixP4kke2vi5l1mpqb_P-GI5xh2OM4KcPQ_8lzQmJqvdJXHG4xFpkYvexfpD_lX7LvBQ1ORR3asE1LQkFeWFHo',
                serviceWorkerRegistration: registration
            });
        } catch (e) { console.error("FCM Sync Error:", e); }
    }
    syncToken();

    // Foreground Listener: Jab aap isi page par honge tab alert aayega
    messaging.onMessage((payload) => {
        console.log('Notification on Udhar Page: ', payload);
        const title = payload.notification?.title || 'KhataLink Alert';
        const body = payload.notification?.body || 'Stock update received';
        const image = payload.notification?.image;
        
        // Professional Native Browser Notification Trigger
        if (Notification.permission === "granted") {
            const options = {
                body: body,
                icon: '../assets/favicon.png'
            };
            if (image) {
                options.image = image;
            }
            const notification = new Notification(title, options);

            notification.onclick = function() {
                window.focus();
                this.close();
            };
        }
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>