<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/notification_service.php';
require_once '../includes/cashfree_config.php'; // For fee constants
if(!isset($_SESSION['shop_id']) || !isset($_GET['id'])) { die("Access Denied"); }
$khata_id = (int)$_GET['id'];
$shop_id = $_SESSION['shop_id'];

// Security: Ensure khata belongs to this shop
$stmt = $pdo->prepare("SELECT mk.*, c.name FROM monthly_khata mk JOIN customers c ON mk.customer_id = c.id WHERE mk.id = ? AND mk.shop_id = ?");
$stmt->execute([$khata_id, $shop_id]);
$khata = $stmt->fetch();
if(!$khata) die("Record not found.");

// Handle Manual Settlement (Cash)
if(isset($_POST['settle_manual'])) {
    $stmt = $pdo->prepare("SELECT total_amount FROM monthly_khata WHERE id = ?");
    $stmt->execute([$khata_id]);
    $amt = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("UPDATE monthly_khata SET status = 'closed', paid_amount = ?, razorpay_payment_id = 'Manual' WHERE id = ?");
    $stmt->execute([$amt, $khata_id]);
    header("Location: monthly_khata.php?success=Bill Settled via Cash!");
    exit();
}

// Handle Add Item
if(isset($_POST['add_item'])) {
    $name = trim($_POST['item_name']);
    $qty = (float)$_POST['qty'];
    $rate = (float)$_POST['rate'];
    $date = $_POST['item_date'];
    $total = $qty * $rate;

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO monthly_khata_items (khata_id, item_name, quantity, rate, amount, item_date) VALUES (?,?,?,?,?,?)")
        ->execute([$khata_id, $name, $qty, $rate, $total, $date]);
    $pdo->prepare("UPDATE monthly_khata SET total_amount = total_amount + ? WHERE id = ?")->execute([$total, $khata_id]);
    
    // Notification logic
    $new_total = (float)$khata['total_amount'] + $total;
    $push_msg = "₹" . number_format($total, 2) . " add kiya gaya: $name ($qty qty).\nAapka naya bill total: ₹" . number_format($new_total, 2);
    sendKhataPush($pdo, (int)$khata['customer_id'], 'customer', "Monthly Update: " . $_SESSION['shop_name'], $push_msg, ['type' => 'monthly', 'id' => (string)$khata_id]);

    // Deduct from Inventory
    $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock - ? WHERE shop_id = ? AND name = ? AND current_stock >= ?") // Added stock check
        ->execute([$qty, $shop_id, $name, $qty]);

    $stmt_pid = $pdo->prepare("SELECT id FROM inventory_products WHERE shop_id = ? AND name = ?");
    $stmt_pid->execute([$shop_id, $name]);
    $product_id = (int)$stmt_pid->fetchColumn();
    if($product_id > 0) notifyStockDeduction($pdo, (int)$shop_id, $product_id);
    if($product_id > 0) checkInventoryAlert($pdo, (int)$shop_id, $product_id);

    $pdo->commit();
    header("Location: manage_monthly_khata.php?id=$khata_id&success=Item Added");
    exit();
}

// Handle Edit Item
if(isset($_POST['edit_item'])) {
    $item_id = (int)$_POST['edit_item_id'];
    $name = trim($_POST['edit_item_name']);
    $qty = (float)$_POST['edit_qty'];
    $rate = (float)$_POST['edit_rate'];
    $date = $_POST['edit_item_date'];
    $new_amount = $qty * $rate;

    // Fetch old amount to adjust khata total
    $stmt_old_amt = $pdo->prepare("SELECT amount FROM monthly_khata_items WHERE id = ? AND khata_id = ?");
    $stmt_old_amt->execute([$item_id, $khata_id]);
    $old_amount = $stmt_old_amt->fetchColumn();

    if ($old_amount !== false) {
        $stmt_old_item = $pdo->prepare("SELECT item_name, quantity FROM monthly_khata_items WHERE id = ?");
        $stmt_old_item->execute([$item_id]);
        $old_item = $stmt_old_item->fetch();

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE monthly_khata_items SET item_name = ?, quantity = ?, rate = ?, amount = ?, item_date = ? WHERE id = ?")
            ->execute([$name, $qty, $rate, $new_amount, $date, $item_id]);
        
        // Adjust total_amount in monthly_khata
        $amount_difference = $new_amount - (float)$old_amount;
        $pdo->prepare("UPDATE monthly_khata SET total_amount = total_amount + ? WHERE id = ?")->execute([$amount_difference, $khata_id]);
        
        // Adjust Stock (Add back old quantity, then deduct new quantity)
        $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock + ? WHERE shop_id = ? AND name = ?")->execute([$old_item['quantity'], $shop_id, $old_item['item_name']]); // Add back old
        $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock - ? WHERE shop_id = ? AND name = ? AND current_stock >= ?")->execute([$qty, $shop_id, $name, $qty]); // Deduct new with check

        // Check inventory alert for the product
        $stmt_pid = $pdo->prepare("SELECT id FROM inventory_products WHERE shop_id = ? AND name = ?");
        $stmt_pid->execute([$shop_id, $name]);
        $product_id = (int)$stmt_pid->fetchColumn();
        if($product_id > 0) notifyStockDeduction($pdo, (int)$shop_id, $product_id);
        if($product_id > 0) checkInventoryAlert($pdo, (int)$shop_id, $product_id);
        $pdo->commit();
        header("Location: manage_monthly_khata.php?id=$khata_id&success=Item Updated");
        exit();
    }
    // If item not found or other error, fall through to display current page with no success message
}

// Handle Delete Item
if(isset($_GET['delete_item'])) {
    $item_id = (int)$_GET['delete_item'];
    $stmt_i = $pdo->prepare("SELECT amount, item_name, quantity FROM monthly_khata_items WHERE id = ? AND khata_id = ?");
    $stmt_i->execute([$item_id, $khata_id]);
    $item_to_del = $stmt_i->fetch();
    $amt = $item_to_del['amount'];
    
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM monthly_khata_items WHERE id = ?")->execute([$item_id]);
    $pdo->prepare("UPDATE monthly_khata SET total_amount = total_amount - ? WHERE id = ?")->execute([$amt, $khata_id]);
    
    // Add back to Inventory
    $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock + ? WHERE shop_id = ? AND name = ?") // No stock check needed for adding back
        ->execute([$item_to_del['quantity'], $shop_id, $item_to_del['item_name']]);
    
    // Check inventory alert for the product (after adding back stock)
    $stmt_pid = $pdo->prepare("SELECT id FROM inventory_products WHERE shop_id = ? AND name = ?");
    $stmt_pid->execute([$shop_id, $item_to_del['item_name']]);
    checkInventoryAlert($pdo, $shop_id, (int)$stmt_pid->fetchColumn());

    $pdo->commit();
    header("Location: manage_monthly_khata.php?id=$khata_id&success=Item Removed");
    exit();
}

$items = $pdo->prepare("SELECT * FROM monthly_khata_items WHERE khata_id = ? ORDER BY item_date ASC");
$items->execute([$khata_id]);
$list = $items->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Monthly List — <?= $khata['name'] ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        .modal-open { display: flex !important; }
    </style>
</head>
<body class="bg-slate-50 font-[Inter]">
<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="monthly_khata.php" class="text-slate-400 hover:text-blue-600"><i class="fas fa-arrow-left"></i></a>
        <span class="text-xs font-black text-blue-700 uppercase tracking-widest">Managing List for <?= htmlspecialchars($khata['name']) ?></span>
    </div>
</nav>
<div class="flex">
    <?php include '../includes/shop_sidebar.php'; ?>
    <main class="flex-1 p-4 md:p-8">
        <div class="max-w-4xl mx-auto">
            <div class="bg-white border border-slate-200 rounded-[2rem] md:rounded-[2.5rem] p-6 md:p-8 shadow-sm mb-8">
                <?php if(isset($_GET['success'])): ?>
                    <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-bold mb-6 flex items-center gap-3 animate-bounce">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['success']) ?>
                    </div>
                <?php endif; ?>
                <h2 class="text-lg font-black mb-6 flex items-center gap-2"><i class="fas fa-cart-plus text-blue-600"></i> Add Daily Item</h2>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">Item Name</label>                        
                        <input type="text" name="item_name" id="monthlyItemName" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold" required placeholder="e.g. Milk 2L" oninput="searchMonthlyItems(this.value)">
                        <div id="monthlyItemSuggestions" class="suggestions-box hidden absolute z-10 bg-white border border-slate-200 rounded-xl shadow-lg mt-1 w-full max-h-40 overflow-y-auto"></div>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">Qty</label>
                        <input type="number" step="0.01" name="qty" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold text-center" required value="1">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">Rate (₹)</label>
                        <input type="number" step="0.01" name="rate" id="monthlyItemRate" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold text-center" required placeholder="0.00">
                    </div>
                    <input type="hidden" name="item_date" value="<?= date('Y-m-d') ?>">
                    <button type="submit" name="add_item" class="bg-blue-600 text-white font-black h-[44px] rounded-xl text-[10px] uppercase tracking-widest">Add Item</button>
                </form>
            </div>

            <script>
            async function searchMonthlyItems(query) {
                const suggestionsBox = document.getElementById('monthlyItemSuggestions');
                if (query.length < 2) { suggestionsBox.classList.add('hidden'); return; }
                const res = await fetch(`ajax_product_search.php?q=${encodeURIComponent(query)}`);
                const products = await res.json();
                if (products.length > 0) {
                    suggestionsBox.innerHTML = products.map(p => `<div class="px-4 py-2 hover:bg-slate-50 cursor-pointer" onclick="selectMonthlyItem('${p.name.replace(/'/g, "\\'")}', '${p.sale_price}')">${p.name} (₹${p.sale_price})</div>`).join('');
                    suggestionsBox.classList.remove('hidden');
                } else { suggestionsBox.classList.add('hidden'); }
            }
            function selectMonthlyItem(name, rate) {
                document.getElementById('monthlyItemName').value = name;
                document.getElementById('monthlyItemRate').value = rate;
                document.getElementById('monthlyItemSuggestions').classList.add('hidden');
            }
            </script>

            <div class="bg-white border border-slate-200 rounded-[2rem] md:rounded-[2.5rem] overflow-x-auto shadow-sm">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Item Description</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Qty × Rate</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Total</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($list as $i): ?>
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-6 py-4 text-xs font-bold text-slate-500"><?= date('d M', strtotime($i['item_date'])) ?></td>
                            <td class="px-6 py-4 text-sm font-black text-slate-900"><?= htmlspecialchars($i['item_name']) ?></td>
                            <td class="px-6 py-4 text-xs text-center text-slate-500 font-bold"><?= (float)$i['quantity'] ?> × ₹<?= number_format($i['rate'],2) ?></td>
                            <td class="px-6 py-4 text-sm font-black text-slate-900 text-right">₹<?= number_format($i['amount'],2) ?></td>
                            <td class="px-6 py-4 text-center">
                                <button type="button" onclick="openEditModal(<?= $i['id'] ?>, '<?= htmlspecialchars($i['item_name'], ENT_QUOTES) ?>', <?= (float)$i['quantity'] ?>, <?= (float)$i['rate'] ?>, '<?= $i['item_date'] ?>')" class="text-slate-300 hover:text-blue-500 me-2" title="Edit Item">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="?id=<?= $khata_id ?>&delete_item=<?= $i['id'] ?>" class="text-slate-300 hover:text-red-500" onclick="return confirm('Remove this item?')"><i class="fas fa-trash-alt"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($list)): ?>
                            <tr><td colspan="5" class="py-20 text-center text-slate-400 italic text-sm">No items added yet this month.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-900 text-white">
                            <td colspan="3" class="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Total Monthly Amount</td>
                            <td class="px-6 py-4 text-lg font-black text-right">₹<?= number_format($khata['total_amount'], 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <div class="mt-8 flex gap-4">
                <a href="../customer/generate_monthly_statement.php?id=<?= $khata_id ?>" target="_blank" class="bg-indigo-50 text-indigo-600 font-black px-8 py-3 rounded-2xl text-[10px] uppercase tracking-widest border border-indigo-100 flex items-center gap-2">
                    <i class="fas fa-file-pdf"></i> Preview Statement
                </a>
                <?php if($khata['status'] == 'open'): ?>
                <button onclick="openSettleModal()" class="bg-emerald-600 text-white font-black px-8 py-3 rounded-2xl text-[10px] uppercase tracking-widest shadow-lg shadow-emerald-100">Settle via Cash</button>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Edit Item Modal -->
<div id="editModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" onclick="if(event.target === this) closeEditModal()">
    <div class="bg-white w-full max-w-md rounded-[2.5rem] p-8 shadow-2xl animate-[slideUp_0.3s_ease]">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-black text-slate-900 uppercase tracking-tight">Edit Entry</h2>
            <button onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="edit_item_id" id="edit_item_id">
            
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2">Item Description</label>
                <input type="text" name="edit_item_name" id="edit_item_name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-blue-500" required>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2">Quantity</label>
                    <input type="number" step="0.01" name="edit_qty" id="edit_qty" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-blue-500 text-center" required>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2">Rate (₹)</label>
                    <input type="number" step="0.01" name="edit_rate" id="edit_rate" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-blue-500 text-center" required>
                </div>
            </div>

            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2">Transaction Date</label>
                <input type="date" name="edit_item_date" id="edit_item_date" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-blue-500" required>
            </div>

            <div class="pt-4 flex gap-3">
                <button type="button" onclick="closeEditModal()" class="flex-1 bg-slate-100 text-slate-500 font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest">Cancel</button>
                <button type="submit" name="edit_item" class="flex-1 bg-blue-600 text-white font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest shadow-lg shadow-blue-200">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Settle Cash Modal -->
<div id="settleModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm" onclick="if(event.target === this) closeSettleModal()">
    <div class="bg-white w-full max-w-sm rounded-[2.5rem] p-8 shadow-2xl text-center animate-[slideUp_0.3s_ease]">
        <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center text-2xl mx-auto mb-4">
            <i class="fas fa-cash-register"></i>
        </div>
        <h2 class="text-xl font-black text-slate-900 mb-2">Record Cash Payment</h2>
        <p class="text-slate-500 text-sm mb-6">Receive <span class="font-black text-slate-900">₹<?= number_format((float)$khata['total_amount'], 2) ?></span> in cash? <br><small>No platform tax will be applied.</small></p>
        
        <form method="POST">
            <div class="flex gap-3">
                <button type="button" onclick="closeSettleModal()" class="flex-1 bg-slate-100 text-slate-500 font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest">Cancel</button>
                <button type="submit" name="settle_manual" class="flex-1 bg-emerald-600 text-white font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest shadow-lg">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSettleModal() { document.getElementById('settleModal').classList.remove('hidden'); document.getElementById('settleModal').classList.add('flex'); }
function closeSettleModal() { document.getElementById('settleModal').classList.add('hidden'); document.getElementById('settleModal').classList.remove('flex'); }

function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.remove('hidden');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.add('hidden');
}

function openEditModal(id, name, qty, rate, date) {
    document.getElementById('edit_item_id').value = id;
    document.getElementById('edit_item_name').value = name;
    document.getElementById('edit_qty').value = qty;
    document.getElementById('edit_rate').value = rate;
    document.getElementById('edit_item_date').value = date;
    document.getElementById('editModal').classList.add('modal-open');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('modal-open');
}
</script>

</body>
</html>
