<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php'; // Fees constants ke liye
require_once '../includes/notification_service.php'; // Added for notifications

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$error = ''; $success = '';

// Fetch customers (Removed current due subquery for full separation)
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.unique_id
    FROM customers c 
    JOIN shop_customers sc ON c.id = sc.customer_id 
    WHERE sc.shop_id = ?
");
$stmt->execute([$shop_id]);
$customers = $stmt->fetchAll();

// Automated Due Date Logic: 2nd of next month (or 2nd of month after next if created after 24th)
$d = new DateTime();
$today_day = (int)$d->format('d');
if ($today_day >= 25) {
    $d->modify('first day of +2 month');
} else {
    $d->modify('first day of +1 month');
}
$default_due_date = $d->format('Y-m-02');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_id = $_POST['customer_id'] ?? null;
    $amount = $_POST['amount'] ?? null;
    $paid_amount = (float)($_POST['initial_paid'] ?? 0);
    $due_date = $_POST['due_date'] ?? null;
    $nominee_name = trim($_POST['nominee_name'] ?? '');
    $nominee_phone = trim($_POST['nominee_phone'] ?? '');
    $terms = trim($_POST['terms'] ?? '');
    $installment_count = (int)($_POST['installment_count'] ?? 0);
    $repayment_type = $_POST['repayment_type'] ?? 'one-time';
    $bond_items_input = $_POST['bond_items'] ?? []; // Array from the dynamic table

    // Handle Signature Uploads FIRST
    $cust_sig = null; $nom_sig = null;
    $upload_dir = '../assets/img/bonds/';
    if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    if(isset($_FILES['cust_signature']) && $_FILES['cust_signature']['error'] == 0) {
        $cust_sig = uniqid('cs_') . '.' . pathinfo($_FILES['cust_signature']['name'], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['cust_signature']['tmp_name'], $upload_dir . $cust_sig);
    }
    if(isset($_FILES['nom_signature']) && $_FILES['nom_signature']['error'] == 0) {
        $nom_sig = uniqid('ns_') . '.' . pathinfo($_FILES['nom_signature']['name'], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['nom_signature']['tmp_name'], $upload_dir . $nom_sig);
    }

    if($customer_id && $amount && $due_date && isset($_POST['accept_terms'])) {
        try {
            $pdo->beginTransaction();
            
            $status = ($paid_amount >= $amount) ? 'closed' : 'active';
            $stmt = $pdo->prepare("INSERT INTO bonds (shop_id, customer_id, amount, initial_paid, paid_amount, due_date, nominee_name, nominee_phone, customer_signature, nominee_signature, terms, installment_count, repayment_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$shop_id, $customer_id, $amount, $paid_amount, $paid_amount, $due_date, $nominee_name, $nominee_phone, $cust_sig, $nom_sig, $terms, $installment_count, $repayment_type, $status]);
            $bond_id = $pdo->lastInsertId();

            // Save Bond Items & Deduct Stock
            $stmt_item = $pdo->prepare("INSERT INTO bond_items (bond_id, product_id, item_name, quantity, rate, item_discount, gst_percent, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_deduct = $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock - ? WHERE id = ? AND shop_id = ?");
            
            foreach ($bond_items_input as $item) {
                if (!empty($item['name']) && $item['qty'] > 0) {
                    $p_id = !empty($item['product_id']) ? (int)$item['product_id'] : null;
                    $item_net = ( ( (float)$item['qty'] * (float)$item['rate'] ) - (float)$item['discount'] ) * ( 1 + (float)$item['gst_percent']/100 );
                    
                    $stmt_item->execute([$bond_id, $p_id, $item['name'], $item['qty'], $item['rate'], $item['discount'], $item['gst_percent'], $item_net]);
                    
                    if($p_id) {
                        $stmt_deduct->execute([$item['qty'], $p_id, $shop_id]);
                    }
                }
            }

            $pdo->commit();
            
            // Notify Customer about the New Bond
            $stmt_c = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
            $stmt_c->execute([$customer_id]);
            $c_name = $stmt_c->fetchColumn() ?: 'Customer';
            sendKhataPush($pdo, (int)$customer_id, 'customer', "Naya Bond Banaya Gaya! 📜", "Namaste $c_name! " . $_SESSION['shop_name'] . " ne aapke liye ₹" . number_format($amount, 2) . " ka naya security bond banaya hai. Details app mein check karein.");

            $success = "Legal bond created and inventory updated!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Legal Bond — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>@keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }</style>
</head>
<body class="bg-slate-50 font-[Inter]">
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
    <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8"></a>
    <span class="text-xs font-black text-blue-700 uppercase">Bond System</span>
</nav>
<div class="flex">
    <?php include '../includes/shop_sidebar.php'; ?>
    <main class="flex-1 p-8 max-w-4xl mx-auto">
        <div class="bg-white border border-slate-200 rounded-[2rem] p-8 shadow-xl">
            <h1 class="text-2xl font-black mb-6">Create Security Bond</h1>
            <?php if($error): ?><div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 font-bold"><?= $error ?></div><?php endif; ?>
            <?php if($success): ?><div class="bg-emerald-50 text-emerald-600 p-4 rounded-xl mb-6 font-bold"><?= $success ?></div><?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" class="space-y-6" id="bondForm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Select Customer</label>
                        <select name="customer_id" id="customerSelect" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" required>
                            <option value="">Select Customer</option>
                            <?php foreach($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= $c['unique_id'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Repayment Due Date (Auto-calculated)</label>
                        <input type="date" name="due_date" value="<?= $default_due_date ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" required>
                        <p class="mt-1 text-[9px] text-slate-400 font-bold uppercase italic">* Based on 2nd of the month policy</p>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Repayment Type</label>
                        <select name="repayment_type" id="repaymentType" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" required onchange="calculateKist()">
                            <option value="one-time">One-time Full Payment</option>
                            <option value="installments">Installment (Kist) System</option>
                        </select>
                    </div>
                </div>

                <!-- Itemized List for Bond -->
                <div class="p-6 bg-slate-50 rounded-3xl border border-slate-200">
                    <div class="text-[10px] font-black text-indigo-600 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <i class="fas fa-boxes-stacked"></i> Itemized Bond Details
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="border-b border-slate-100">
                                    <th class="px-2 py-2 text-[9px] font-black text-slate-400 uppercase tracking-widest">Item</th>
                                    <th class="px-2 py-2 text-[9px] font-black text-slate-400 uppercase tracking-widest w-12 text-center">Qty</th>
                                    <th class="px-2 py-2 text-[9px] font-black text-slate-400 uppercase tracking-widest w-16 text-right">Rate</th>
                                    <th class="px-2 py-2 text-[9px] font-black text-slate-400 uppercase tracking-widest w-12 text-right">Disc</th>
                                    <th class="px-2 py-2 text-[9px] font-black text-slate-400 uppercase tracking-widest w-12 text-right">GST</th>
                                    <th class="px-2 py-2 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right w-20">Net</th>
                                    <th class="px-2 py-2 w-8"></th>
                                </tr>
                            </thead>
                            <tbody id="bondItemsContainer" class="divide-y divide-slate-100">
                                <!-- Item rows will be added here -->
                            </tbody>
                            <tfoot>
                                <tr class="bg-slate-100">
                                    <td colspan="5" class="px-4 py-2 text-[10px] font-black uppercase tracking-[.2em] text-slate-400 text-right">Total Items Value</td>
                                    <td class="px-4 py-2 text-sm font-black text-right" id="bondItemsGrandTotal">₹0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="flex gap-2 mt-4">
                        <input type="text" id="bondItemSearch" class="flex-1 bg-white border border-slate-200 rounded-xl px-4 py-2 text-sm" placeholder="Search item to add...">
                        <button type="button" onclick="addBondItemRow()" class="bg-blue-600 text-white px-4 py-2 rounded-xl text-sm font-bold"><i class="fas fa-plus"></i> Add</button>
                    </div>
                    <div id="bondItemSuggestions" class="suggestions-box hidden absolute z-10 bg-white border border-slate-200 rounded-xl shadow-lg mt-1 w-full max-h-40 overflow-y-auto"></div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="flex items-center">
                        <div class="bg-blue-50 p-6 rounded-[2rem] w-full border border-blue-100">
                            <div class="text-[9px] font-black text-blue-400 uppercase tracking-widest mb-1">Total Remaining Balance</div>
                            <div class="text-3xl font-black text-blue-700" id="remainingDisplay">₹0.00</div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Bond Amount (₹)</label>
                        <input type="number" name="amount" id="bondAmount" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" required oninput="calculateKist()" readonly>
                        <p class="mt-1 text-[9px] text-slate-400 font-bold uppercase italic">* Auto-calculated from items above</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Initial Paid Amount (₹)</label>
                        <input type="number" name="initial_paid" id="initialPaid" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" value="0" oninput="calculateKist()">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Repayment Type</label>
                        <select name="repayment_type" id="repaymentType" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" required onchange="calculateKist()">
                            <option value="one-time">One-time Full Payment</option>
                            <option value="installments">Installment (Kist) System</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Repayment Due Date (Auto-calculated)</label>
                        <input type="date" name="due_date" value="<?= $default_due_date ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" required>
                        <p class="mt-1 text-[9px] text-slate-400 font-bold uppercase italic">* Based on 2nd of the month policy</p>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">No. of Installments (Kist)</label>
                        <input type="number" name="installment_count" id="installmentCount" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" placeholder="e.g. 5" value="0" oninput="calculateKist()">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Nominee Name (Family/Friend)</label>
                        <input type="text" name="nominee_name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" placeholder="Full Name" required>
                    </div>
                </div>

                <div id="kistCalcResult" class="hidden"></div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Nominee Phone Number</label>
                        <input type="text" name="nominee_phone" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" placeholder="WhatsApp Number" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Legal Terms & Notes</label>
                        <textarea name="terms" rows="3" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500" placeholder="e.g. Interest after due date, Item details etc."></textarea>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-6 border-t border-slate-100">
                    <div class="p-4 bg-slate-50 border-2 border-dashed border-slate-200 rounded-2xl">
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Customer Signature</label>
                        <input type="file" name="cust_signature" accept="image/*" class="text-xs" required>
                    </div>
                    <div class="p-4 bg-slate-50 border-2 border-dashed border-slate-200 rounded-2xl">
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Nominee Signature</label>
                        <input type="file" name="nom_signature" accept="image/*" class="text-xs" required>
                    </div>
                </div>

                <div class="flex items-start gap-4 p-5 bg-blue-50/50 border border-blue-100 rounded-[1.5rem]">
                    <input type="checkbox" name="accept_terms" id="acceptTerms" class="mt-1 w-5 h-5 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer" required>
                    <label for="acceptTerms" class="text-xs font-bold text-slate-600 leading-relaxed cursor-pointer">
                        I hereby confirm that I have explained the <span onclick="event.preventDefault(); openTermsModal()" class="text-blue-700 underline font-black hover:text-blue-800">binding rules and legal terms</span> of KhataLink to the debtor. I verify that the information and signatures provided are authentic and I authorize the activation of this security bond.
                    </label>
                </div>

                <button type="submit" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl hover:bg-blue-600 transition-all uppercase tracking-widest text-xs shadow-xl shadow-slate-200">
                    Generate & Activate Bond
                </button>
            </form>
        </div>
    </main>
</div>

<!-- Legal Terms Modal -->
<div id="termsModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" onclick="if(event.target === this) closeTermsModal()">
    <div class="bg-white w-full max-w-2xl rounded-[2.5rem] flex flex-col max-h-[90vh] shadow-2xl overflow-hidden animate-[slideUp_0.3s_ease]">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="text-lg font-black text-slate-900 uppercase tracking-tight">Binding Rules & Legal Terms</h3>
            <button type="button" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-full text-slate-500" onclick="closeTermsModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-8 overflow-y-auto">
             <div class="text-[10px] font-black text-blue-600 uppercase tracking-[0.2em] mb-6">Standard Operating Protocol</div>
             <ol class="space-y-5 text-sm text-slate-600 font-medium">
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">01</span> <div><strong>Late Payment Fine:</strong> Delayed payments attract a penalty interest of 2% per month on the outstanding balance.</div></li>
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">02</span> <div><strong>Installment Default:</strong> If two consecutive installments are missed, the entire remaining amount becomes due immediately.</div></li>
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">03</span> <div><strong>Digital Evidence:</strong> This digitally generated bond is considered primary evidence in legal proceedings.</div></li>
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">04</span> <div><strong>Nominee Liability:</strong> Nominees are legally obligated to coordinate repayment if the debtor is unreachable for 15 days.</div></li>
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">05</span> <div><strong>Recovery Costs:</strong> All court fees and lawyer expenses for recovery will be borne by the Debtor.</div></li>
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">06</span> <div><strong>Address Accuracy:</strong> Debtor verifies that all identity and address proofs provided are authentic.</div></li>
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">07</span> <div><strong>No Verbal Waivers:</strong> Only updates in the digital system are valid; verbal agreements hold no legal value.</div></li>
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">08</span> <div><strong>Good Condition:</strong> Debtor acknowledges receiving goods/services in satisfactory condition.</div></li>
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">09</span> <div><strong>Platform Fee:</strong> A 1% platform convenience fee will be applied to all online payments made towards this bond, payable by the Debtor.</div></li>
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">09</span> <div><strong>Asset Charge:</strong> Creditor reserves the right to report defaults to credit bureaus or local authorities.</div></li>
                <li class="flex gap-4"><span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-[10px] font-black shrink-0">10</span> <div><strong>Jurisdiction:</strong> Legal disputes are subject to the exclusive jurisdiction of the Creditor's city courts.</div></li>
            </ol>
        </div>
        <div class="p-6 border-t border-slate-100 bg-slate-50/50">
            <button type="button" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl uppercase tracking-widest text-xs shadow-lg" onclick="closeTermsModal()">I Have Explained These Terms</button>
        </div>
    </div>
</div>

<script>
let bondItemCounter = 0;
const bondItemSearchInput = document.getElementById('bondItemSearch');
const bondItemSuggestionsBox = document.getElementById('bondItemSuggestions');

bondItemSearchInput.addEventListener('input', async function() {
    const q = this.value.trim();
    if (q.length < 2) { bondItemSuggestionsBox.classList.add('hidden'); return; }
    try {
        const res = await fetch(`ajax_product_search.php?q=${encodeURIComponent(q)}`);
        const products = await res.json();
        if (products.length > 0) {
            bondItemSuggestionsBox.innerHTML = products.map(p => `
                <div class="px-4 py-2 hover:bg-slate-50 cursor-pointer flex justify-between items-center" 
                     onclick="selectBondItemSuggestion('${p.id}', '${p.name.replace(/'/g, "\\'")}', '${p.sale_price}', '${p.gst_percent}')">
                    <span class="font-bold text-sm">${p.name}</span>
                    <span class="text-xs text-emerald-600">₹${p.sale_price}</span>
                </div>
            `).join('');
            bondItemSuggestionsBox.classList.remove('hidden');
        } else { bondItemSuggestionsBox.classList.add('hidden'); }
    } catch (e) { console.error("Bond item search error:", e); }
});

function selectBondItemSuggestion(productId, name, rate, gst_percent) {
    bondItemSearchInput.value = name;
    bondItemSearchInput.dataset.productId = productId;
    bondItemSearchInput.dataset.rate = rate;
    bondItemSearchInput.dataset.gstPercent = gst_percent;
    bondItemSuggestionsBox.classList.add('hidden');
}

function addBondItemRow(product = null) {
    const container = document.getElementById('bondItemsContainer');
    const newRow = document.createElement('tr');
    newRow.className = 'bond-item-row hover:bg-slate-50/50';
    newRow.innerHTML = `
        <td class="px-2 py-2">
            <input type="hidden" name="bond_items[${bondItemCounter}][product_id]" value="${product ? product.id : ''}">
            <input type="text" name="bond_items[${bondItemCounter}][name]" value="${product ? product.name : bondItemSearchInput.value}" 
                   placeholder="Item Name" class="w-full bg-transparent border-none outline-none text-sm font-bold" oninput="updateBondItemTotal()">
        </td>
        <td class="px-2 py-2">
            <input type="number" name="bond_items[${bondItemCounter}][qty]" value="1" step="0.01" min="0" 
                   class="w-full bg-transparent border-none outline-none text-center text-xs" oninput="updateBondItemTotal()">
        </td>
        <td class="px-2 py-2">
            <input type="number" name="bond_items[${bondItemCounter}][rate]" value="${product ? product.sale_price : (bondItemSearchInput.dataset.rate || '0.00')}" step="0.01" min="0" 
                   class="w-full bg-transparent border-none outline-none text-right text-xs" oninput="updateBondItemTotal()">
        </td>
        <td class="px-2 py-2">
            <input type="number" name="bond_items[${bondItemCounter}][discount]" value="0.00" step="0.01" min="0" 
                   class="w-full bg-transparent border-none outline-none text-right text-xs" oninput="updateBondItemTotal()">
        </td>
        <td class="px-2 py-2">
            <input type="number" name="bond_items[${bondItemCounter}][gst_percent]" value="${product ? product.gst_percent : (bondItemSearchInput.dataset.gstPercent || '0.00')}" step="0.01" min="0" 
                   class="w-full bg-transparent border-none outline-none text-right text-xs" oninput="updateBondItemTotal()">
        </td>
        <td class="px-2 py-2 text-right text-sm font-bold" data-net-total="0.00">₹0.00</td>
        <td class="px-2 py-2 text-center">
            <button type="button" onclick="this.closest('tr').remove(); updateBondItemsGrandTotal();" class="text-red-400 hover:text-red-600 text-xs"><i class="fas fa-times-circle"></i></button>
        </td>
    `;
    container.appendChild(newRow);
    bondItemCounter++;
    updateBondItemTotal(); // Calculate for the new row

    // Clear search and dataset
    bondItemSearchInput.value = '';
    delete bondItemSearchInput.dataset.productId;
    delete bondItemSearchInput.dataset.rate;
    delete bondItemSearchInput.dataset.gstPercent;
}

function updateBondItemTotal() {
    document.querySelectorAll('.bond-item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('input[name*="[qty]"]').value) || 0;
        const rate = parseFloat(row.querySelector('input[name*="[rate]"]').value) || 0;
        const discount = parseFloat(row.querySelector('input[name*="[discount]"]').value) || 0;
        const gst_percent = parseFloat(row.querySelector('input[name*="[gst_percent]"]').value) || 0;

        const subtotal = (qty * rate) - discount;
        const netTotal = subtotal * (1 + (gst_percent / 100));
        
        const netTotalCell = row.querySelector('td[data-net-total]');
        netTotalCell.dataset.netTotal = netTotal.toFixed(2);
        netTotalCell.innerText = `₹${netTotal.toFixed(2)}`;
    });
    updateBondItemsGrandTotal();
}

function updateBondItemsGrandTotal() {
    let grandTotal = 0;
    document.querySelectorAll('.bond-item-row').forEach(row => {
        grandTotal += parseFloat(row.querySelector('td[data-net-total]').dataset.netTotal) || 0;
    });
    document.getElementById('bondItemsGrandTotal').innerText = `₹${grandTotal.toFixed(2)}`;
    document.getElementById('bondAmount').value = grandTotal.toFixed(2); // Auto-fill main bond amount
    calculateKist(); // Recalculate kist if bond amount changes
}

// Initial call to add an empty row if no items are pre-filled
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('bondItemsContainer').children.length === 0) {
        // addBondItemRow(); // Add an empty row by default
    }
    calculateKist(); // Initial calculation

    // Close suggestions on outside click
    document.addEventListener('click', (event) => {
        if (!bondItemSearchInput.contains(event.target) && !bondItemSuggestionsBox.contains(event.target)) {
            bondItemSuggestionsBox.classList.add('hidden');
        }
    });
});

// Helper to escape HTML for security
function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Function to select a suggestion and add it as a row
function selectBondItemSuggestion(productId, name, rate, gst_percent) {
    const product = {
        id: productId,
        name: name,
        sale_price: rate,
        gst_percent: gst_percent
    };
    addBondItemRow(product);
    bondItemSearchInput.value = ''; // Clear search input after adding
    bondItemSuggestionsBox.classList.add('hidden');
}

function openTermsModal() {
    document.getElementById('termsModal').classList.remove('hidden');
    document.getElementById('termsModal').classList.add('flex');
}
function closeTermsModal() {
    document.getElementById('termsModal').classList.add('hidden');
    document.getElementById('termsModal').classList.remove('flex');
}

// PHP constants ko JS mein pass kar rahe hain
const CUST_FEE_PCT = <?= BOND_PLATFORM_COMMISSION_PERCENT ?>;
const SHOP_FEE_PCT = <?= SHOP_SERVICE_FEE_PERCENT ?>;
const PG_FEE_PCT   = <?= PG_FEE_PERCENT ?>;

function calculateKist() {
    const amount = parseFloat(document.getElementById('bondAmount').value) || 0;
    const paid = parseFloat(document.getElementById('initialPaid').value) || 0;
    const count = parseInt(document.getElementById('installmentCount').value) || 0;
    const type = document.getElementById('repaymentType').value;
    const resultDiv = document.getElementById('kistCalcResult');
    const remainDiv = document.getElementById('remainingDisplay');

    const remaining = Math.max(0, amount - paid); // Use the auto-filled amount
    remainDiv.innerText = `₹${remaining.toFixed(2)}`;

    if (type === 'installments' && count > 0 && amount > 0) {
        const baseKistPerMonth = remaining / count;
        
        // Customer Side
        const custExtraFee = baseKistPerMonth * (CUST_FEE_PCT / 100);
        const totalCustPays = baseKistPerMonth + custExtraFee;
        
        // Shop Side (Full Payout)
        const finalShopPayout = baseKistPerMonth;

        resultDiv.innerHTML = `
            <div class="p-8 bg-slate-900 text-white rounded-[2.5rem] mt-6 shadow-2xl border-4 border-blue-500/20 animate-[slideUp_0.3s_ease]">
                <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.3em] mb-6 flex items-center gap-2">
                    <i class="fas fa-file-invoice-dollar"></i> Monthly Payment Structure
                </p>
                <div class="space-y-4">
                    <!-- Customer Perspective -->
                    <div class="pb-4 border-b border-white/10">
                        <div class="flex justify-between items-center text-xs mb-1"><span class="text-slate-400">Base Installment (Principal):</span> <span class="font-bold text-slate-200">₹${baseKistPerMonth.toFixed(2)}</span></div>
                        <div class="flex justify-between items-center text-xs mb-2"><span class="text-slate-400">Online Convenience Fee (${CUST_FEE_PCT}%):</span> <span class="font-bold text-blue-400">+ ₹${custExtraFee.toFixed(2)}</span></div>
                        <div class="flex justify-between items-center">
                            <span class="text-[10px] font-black text-blue-400 uppercase tracking-widest">Customer Pays Monthly (Online)</span>
                            <span class="text-xl font-black text-blue-400">₹${totalCustPays.toFixed(2)}</span>
                        </div>
                    </div>
                    
                    <!-- Shop Perspective -->
                    <div>
                        <div class="flex justify-between items-center text-xs mb-2"><span class="text-emerald-400 font-bold">KhataLink Direct Payout:</span> <span class="font-bold text-emerald-400">100% Guaranteed</span></div>
                        <div class="flex justify-between items-center bg-emerald-500/10 p-3 rounded-xl border border-emerald-500/20">
                            <span class="text-[10px] font-black text-emerald-400 uppercase tracking-widest">Your Net Monthly Payout</span>
                            <span class="text-xl font-black text-emerald-400">₹${finalShopPayout.toFixed(2)}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
        resultDiv.classList.remove('hidden');
    } else {
        resultDiv.classList.add('hidden');
    }
}
</script>
</body>
</html>