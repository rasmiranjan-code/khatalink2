<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
require_once '../includes/notification_service.php';
track_visitor($pdo);

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$success = $_GET['success'] ?? '';

// Handle Reply Submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_reply'])) {
    $report_id = (int)$_POST['report_id'];
    $reply = trim($_POST['reply']);
    if(!empty($reply)) {
        // Update the report and mark as solved (is_read = 1)
        $stmt = $pdo->prepare("UPDATE reports SET reply = ?, replied_at = NOW(), is_read = 1 WHERE id = ? AND shop_id = ?");
        $stmt->execute([$reply, $report_id, $shop_id]);

        // Fetch customer_id to send push notification
        $stmt_info = $pdo->prepare("SELECT r.customer_id, s.shop_name FROM reports r JOIN shop_owners s ON r.shop_id = s.id WHERE r.id = ?");
        $stmt_info->execute([$report_id]);
        $info = $stmt_info->fetch();

        // Send notification to customer
        $title = "Reply from " . $info['shop_name'];
        $body = "Aapki report ka jawab aa gaya hai: " . substr($reply, 0, 60) . "...";
        sendKhataPush($pdo, (int)$info['customer_id'], 'customer', $title, $body, ['type' => 'dispute_reply']);

        // PRG Redirect: Refresh par duplicate notification rokne ke liye
        header("Location: reports.php?success=Reply sent to customer successfully!");
        exit();
    }
}

// Action: Mark as Read
if(isset($_GET['mark_read'])) {
    $report_id = (int)$_GET['mark_read'];
    $stmt = $pdo->prepare("UPDATE reports SET is_read = 1 WHERE id = ? AND shop_id = ?");
    $stmt->execute([$report_id, $shop_id]);
    header("Location: reports.php");
    exit();
}

// Action: Delete
if(isset($_GET['delete'])) {
    $report_id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ? AND shop_id = ?");
    $stmt->execute([$report_id, $shop_id]);
    $success = "Report dismissed successfully.";
}

// Fetch all reports for this shop
$stmt = $pdo->prepare("
    SELECT r.*, c.name as customer_name, c.unique_id, ue.total_amount, ue.created_at as entry_date
    FROM reports r
    JOIN customers c ON r.customer_id = c.id
    JOIN udhar_entries ue ON r.entry_id = ue.id
    WHERE r.shop_id = ?
    ORDER BY r.is_read ASC, r.created_at DESC
");
$stmt->execute([$shop_id]);
$reports = $stmt->fetchAll();

// Count unread for navigation badge
$unread_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE shop_id = ? AND is_read = 0");
$unread_count_stmt->execute([$shop_id]);
$unread_count = $unread_count_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Reports — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
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
    <div class="hidden sm:flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-wider">
        <i class="fas fa-store"></i>
        <?= htmlspecialchars($_SESSION['shop_name']) ?>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/shop_sidebar.php'; ?>

    <div class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full">
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
            <div class="mb-4 md:mb-0">
                <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">Customer Reports</h1>
                <p class="text-slate-500 text-sm">Review disputes and issues raised by your customers.</p>
            </div>
            <?php if($success): ?>
                <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-medium flex items-center gap-3">
                    <i class="fas fa-check-circle"></i> <?= $success ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 gap-6">
                <?php if(count($reports) > 0): ?>
                    <?php foreach($reports as $r): ?>
                        <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm hover:shadow-lg transition-all <?= $r['is_read'] ? '' : 'border-l-4 border-blue-600' ?>">
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-500 text-sm"><i class="fas fa-user"></i></div>
                                        <div>
                                            <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($r['customer_name']) ?></div>
                                            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?= htmlspecialchars($r['unique_id']) ?></div>
                                        </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-[10px] font-medium text-slate-400 uppercase"><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></div>
                                        <?php if(!$r['is_read']): ?>
                                            <span class="bg-blue-50 text-blue-600 text-[9px] font-black px-2 py-0.5 rounded-full uppercase tracking-widest mt-1 inline-block">New</span>
                                        <?php endif; ?>
                                </div>
                            </div>

                            <div class="bg-slate-50 border border-slate-100 rounded-2xl p-4 text-sm text-slate-700 leading-relaxed mb-4">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-[10px] font-black text-blue-600 uppercase tracking-widest"><?= htmlspecialchars($r['customer_name']) ?> claims:</span>
                                </div>
                                <i class="fas fa-quote-left text-slate-300 me-2"></i>
                                    <?= nl2br(htmlspecialchars($r['message'])) ?>
                            </div>

                            <?php if(!empty($r['reply'])): ?>
                                <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-4 text-sm text-emerald-800 leading-relaxed mb-4">
                                    <div class="text-[9px] font-black text-emerald-600 uppercase tracking-widest mb-2">
                                        <i class="fas fa-reply me-1"></i> Your Reply
                                    </div>
                                        <?= nl2br(htmlspecialchars($r['reply'])) ?>
                                    <div class="text-right text-[9px] font-medium text-emerald-600 uppercase mt-2">
                                        Replied on: <?= date('d M Y, h:i A', strtotime($r['replied_at'])) ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <form method="POST" class="mb-4">
                                    <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                                    <textarea name="reply" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-3 text-sm focus:bg-white focus:border-blue-500 outline-none transition-all mb-3" rows="2" placeholder="Write a reply to the customer..." required></textarea>
                                    <button type="submit" name="submit_reply" class="bg-slate-900 text-white font-black px-5 py-3 rounded-2xl hover:bg-blue-600 transition-all shadow-lg shadow-slate-200 flex items-center gap-2 uppercase tracking-widest text-[10px]">
                                        <i class="fas fa-paper-plane"></i> Send Reply
                                    </button>
                                </form>
                            <?php endif; ?>

                            <div class="flex flex-col md:flex-row justify-between items-center gap-4 pt-4 border-t border-slate-100">
                                <div class="bg-slate-50 p-3 rounded-2xl flex-1 w-full md:w-auto">
                                    <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Related Transaction</div>
                                    <div class="text-sm font-black text-slate-900">
                                        ₹<?= number_format($r['total_amount'], 2) ?> 
                                        <span class="text-slate-500 font-medium text-xs ml-2">
                                            (<?= date('d M Y', strtotime($r['entry_date'])) ?>)
                                        </div>
                                    </div>
                                    <a href="customer_details.php?customer_id=<?= $r['customer_id'] ?>" class="text-blue-600 text-[10px] font-black uppercase tracking-widest mt-2 inline-flex items-center gap-1 hover:underline">
                                        View Full Ledger <i class="fas fa-arrow-right text-[8px]"></i>
                                    </a>
                                </div>
                                
                                <div class="flex gap-2">
                                        <?php if(!$r['is_read']): ?>
                                            <a href="reports.php?mark_read=<?= $r['id'] ?>" class="bg-blue-50 text-blue-600 text-[10px] font-black px-4 py-2 rounded-xl hover:bg-blue-600 hover:text-white transition-all shadow-sm flex items-center gap-2 uppercase tracking-widest">
                                                <i class="fas fa-check"></i> Mark Read
                                            </a>
                                        <?php endif; ?>
                                        <a href="reports.php?delete=<?= $r['id'] ?>"
                                           class="bg-red-50 text-red-600 text-[10px] font-black px-4 py-2 rounded-xl hover:bg-red-600 hover:text-white transition-all shadow-sm flex items-center gap-2 uppercase tracking-widest"
                                           onclick="return confirm('Are you sure you want to dismiss this report?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-20 bg-white border border-slate-200 rounded-[2.5rem] shadow-sm">
                        <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-3xl mx-auto mb-6">
                            <i class="fas fa-flag-checkered"></i>
                        </div>
                        <h3 class="font-black text-slate-900 uppercase tracking-widest text-xs mb-2">All Clear!</h3>
                        <p class="text-slate-400 text-xs font-medium">No customer reports or disputes found at this moment.</p>
                    </div>
                <?php endif; ?>
        </div>
    </div>
</div>

<footer class="bg-white border-t border-slate-200 py-6 text-center">
    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">
        © <?= date('Y') ?> KhataLink — Premium Digital Ledger
    </div>
</footer>

<script>
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.add('show');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('show');
}
</script>