<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
require_once '../includes/notification_service.php';
track_visitor($pdo);

if(!isset($_SESSION['customer_id'])) {
    header("Location: ../auth/login.php?type=customer");
    exit();
}

$customer_id = $_SESSION['customer_id'];
$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

// Handle Report Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_report'])) {
    $shop_id = (int)$_POST['shop_id'];
    $entry_id = (int)$_POST['entry_id'];
    $message = trim($_POST['message']);

    if (empty($shop_id) || empty($entry_id) || empty($message)) {
        $error = "All fields are required. Please select a shop and the related transaction.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO reports (shop_id, customer_id, entry_id, message) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$shop_id, $customer_id, $entry_id, $message])) {
            // Notify Shop Owner about the new dispute
            $stmt_c = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
            $stmt_c->execute([$customer_id]);
            $c_name = $stmt_c->fetchColumn() ?: 'Customer';

            $title = "New Dispute Raised: " . $c_name;
            $body = "$c_name ne ek transaction par dispute raise kiya hai. Kripya review karein.";
            sendKhataPush($pdo, $shop_id, 'shop', $title, $body, ['type' => 'dispute']);

            header("Location: reports.php?success=Your report has been sent to the shop owner.");
            exit();
        } else {
            $error = "Failed to submit report. Please try again.";
        }
    }
}

// Fetch Linked Shops for dropdown
$shops_stmt = $pdo->prepare("
    SELECT s.id, s.shop_name 
    FROM shop_owners s
    JOIN shop_customers sc ON s.id = sc.shop_id
    WHERE sc.customer_id = ?
");
$shops_stmt->execute([$customer_id]);
$shops = $shops_stmt->fetchAll();

// Fetch Udhar Entries to link with reports
$entries_stmt = $pdo->prepare("
    SELECT ue.id, ue.total_amount, ue.created_at, s.shop_name, ue.shop_id
    FROM udhar_entries ue
    JOIN shop_owners s ON ue.shop_id = s.id
    WHERE ue.customer_id = ?
    ORDER BY ue.created_at DESC
");
$entries_stmt->execute([$customer_id]);
$entries = $entries_stmt->fetchAll();

// Fetch My Past Reports
$reports_stmt = $pdo->prepare("
    SELECT r.*, s.shop_name, ue.total_amount, ue.created_at as entry_date
    FROM reports r
    JOIN shop_owners s ON r.shop_id = s.id
    JOIN udhar_entries ue ON r.entry_id = ue.id
    WHERE r.customer_id = ?
    ORDER BY r.created_at DESC
");
$reports_stmt->execute([$customer_id]);
$my_reports = $reports_stmt->fetchAll();

// Get current page for dynamic active state
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Disputes Center — KhataLink</title>
     <link rel="icon" type="image/png" href="../assets/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass-banner { background: linear-gradient(135deg, #450a0a 0%, #7f1d1d 100%); }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="text-[10px] font-black text-red-600 uppercase tracking-widest bg-red-50 border border-red-100 px-3 py-1.5 rounded-full">
        <i class="fas fa-flag me-1"></i> Dispute Resolution
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/customer_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
        <div class="glass-banner rounded-3xl p-8 mb-8 text-white shadow-xl relative overflow-hidden">
            <div class="relative z-10">
                <h1 class="text-2xl md:text-3xl font-black tracking-tight mb-1">Reports & Disputes</h1>
                <p class="text-red-200 text-sm">Found a mistake? Report it directly to the merchant.</p>
            </div>
            <i class="fas fa-flag absolute -right-4 -bottom-4 text-8xl text-white/5 rotate-12"></i>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <div class="lg:col-span-5">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm">
                    <h5 class="text-xs font-black text-slate-900 uppercase tracking-widest mb-6">Create New Dispute</h5>

                    <?php if($error): ?>
                        <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-xs font-bold mb-4 flex items-center gap-2">
                            <i class="fas fa-times-circle"></i> <?= $error ?>
                        </div>
                    <?php endif; ?>
                    <?php if($success): ?>
                        <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-xs font-bold mb-4 flex items-center gap-2">
                            <i class="fas fa-check-circle"></i> <?= $success ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-4">
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Select Shop</label>
                            <select name="shop_id" id="shopSelect" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all cursor-pointer" required onchange="filterEntries()">
                                <option value="">Choose Shop...</option>
                                <?php foreach($shops as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['shop_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Related Transaction</label>
                            <select name="entry_id" id="entrySelect" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-2.5 text-sm font-bold outline-none focus:bg-white focus:border-blue-500 transition-all cursor-pointer" required>
                                <option value="">Choose Entry...</option>
                                <?php foreach($entries as $e): ?>
                                    <option value="<?= $e['id'] ?>" data-shop="<?= $e['shop_id'] ?>" style="display:none;">
                                        ₹<?= number_format($e['total_amount'], 0) ?> — <?= date('d M Y', strtotime($e['created_at'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-6">
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Message to Owner</label>
                            <textarea name="message" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" rows="4" placeholder="Describe the issue clearly..." required></textarea>
                        </div>

                        <button type="submit" name="submit_report" class="w-full bg-red-600 text-white font-black py-4 rounded-2xl hover:bg-red-700 transition-all shadow-lg shadow-red-200 uppercase tracking-widest text-[10px]">
                            <i class="fas fa-paper-plane me-1"></i> Submit Dispute
                        </button>
                    </form>
                </div>

                <div class="bg-blue-50 border border-blue-100 text-blue-700 rounded-3xl p-6 mt-6">
                    <div class="flex gap-4">
                        <i class="fas fa-info-circle text-lg"></i>
                        <div class="text-xs font-medium leading-relaxed">
                            <strong>How it works:</strong> Once submitted, the shop owner will review your claim. They can adjust the ledger or reply to clear up any confusion.
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-7">
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm h-full">
                    <h5 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-8">Dispute History</h5>
                    
                    <div class="space-y-6">
                        <?php if($my_reports): ?>
                            <?php foreach($my_reports as $report): ?>
                                <div class="p-6 bg-slate-50 rounded-[2rem] border border-slate-100 hover:border-blue-500 transition-all group <?= $report['is_read'] ? '' : 'border-blue-200 ring-2 ring-blue-50' ?>">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h6 class="text-sm font-black text-slate-900"><?= htmlspecialchars($report['shop_name']) ?></h6>
                                            <div class="text-[10px] font-bold text-slate-400 mt-1 uppercase tracking-tight">
                                                <i class="fas fa-file-invoice-dollar me-1"></i> ₹<?= number_format($report['total_amount'], 0) ?> Entry on <?= date('d M Y', strtotime($report['entry_date'])) ?>
                                            </div>
                                        </div>
                                        <span class="text-[9px] font-black px-3 py-1.5 rounded-full uppercase tracking-widest <?= $report['is_read'] ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-blue-50 text-blue-600 border border-blue-100' ?>">
                                            <?= $report['is_read'] ? 'Reviewed' : 'Sent' ?>
                                        </span>
                                    </div>

                                    <div class="p-4 bg-white rounded-2xl text-xs font-semibold text-slate-600 italic border border-slate-100 mb-4">
                                        "<?= htmlspecialchars($report['message']) ?>"
                                    </div>
                                    
                                    <?php if(!empty($report['reply'])): ?>
                                        <div class="p-4 rounded-2xl bg-blue-600 text-white text-xs mb-4 shadow-lg shadow-blue-100 relative">
                                            <div class="font-black text-[9px] uppercase tracking-widest mb-2 flex items-center gap-1 opacity-80">
                                                <i class="fas fa-store"></i> Shop Response
                                            </div>
                                            <div class="font-medium leading-relaxed"><?= nl2br(htmlspecialchars($report['reply'])) ?></div>
                                            <div class="text-right mt-2 text-[9px] font-bold opacity-60">
                                                <?= date('d M Y, h:i A', strtotime($report['replied_at'])) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                        Filed on: <?= date('d M Y, h:i A', strtotime($report['created_at'])) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-20">
                                <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center text-3xl mx-auto mb-4"><i class="fas fa-check-double"></i></div>
                                <h3 class="font-black text-slate-900 uppercase tracking-widest text-xs">No active disputes</h3>
                                <p class="text-xs text-slate-400 font-medium">Your account records are currently accurate.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<footer class="bg-white border-t border-slate-200 py-6 text-center mt-auto">
    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">© <?= date('Y') ?> KhataLink — India's Most Trusted Digital Ledger</div>
</footer>

<script>
function filterEntries() {
    const shopId = document.getElementById('shopSelect').value;
    const options = document.querySelectorAll('#entrySelect option');

    options.forEach(option => {
        if (option.value === "") {
            option.style.display = "";
            return;
        }

        const optionShopId = option.getAttribute('data-shop');
        if (shopId === "" || optionShopId === shopId) {
            option.style.display = "";
        } else {
            option.style.display = "none";
        }
    });

    const entrySelect = document.getElementById('entrySelect');
    if (entrySelect.value) {
        const selectedOption = entrySelect.querySelector(`option[value="${entrySelect.value}"]`);
        if (selectedOption && selectedOption.style.display === "none") {
            entrySelect.value = "";
        }
    }
}

function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }
</script>
</body>
</html>