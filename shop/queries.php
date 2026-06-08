<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];

// Fetch tickets for this shop
$stmt = $pdo->prepare("SELECT * FROM support_queries WHERE user_id = ? AND user_type = 'shop' ORDER BY created_at DESC");
$stmt->execute([$shop_id]);
$queries = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Support Tickets — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
        <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-8"></a>
    </div>
    <span class="text-[10px] font-black text-blue-700 uppercase tracking-widest bg-blue-50 px-3 py-1 rounded-full">Help Desk</span>
</nav>

<div class="flex">
    <?php include '../includes/shop_sidebar.php'; ?>
    <main class="flex-1 p-4 md:p-8 max-w-4xl mx-auto w-full">
        <div class="flex justify-between items-end mb-8">
            <div>
                <h1 class="text-3xl font-black text-slate-900 tracking-tight">Support History</h1>
                <p class="text-slate-500 text-sm">Track your reported issues and resolutions.</p>
            </div>
            <a href="new_ticket.php" class="bg-blue-600 text-white font-black px-6 py-3 rounded-2xl text-[10px] uppercase tracking-widest shadow-lg shadow-blue-100 hover:bg-blue-700 transition-all">
                <i class="fas fa-plus mr-1"></i> New Ticket
            </a>
        </div>

        <div class="space-y-4">
            <?php if(empty($queries)): ?>
                <div class="text-center py-20 bg-white border border-dashed border-slate-200 rounded-[2.5rem]">
                    <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-2xl mx-auto mb-4"><i class="fas fa-ticket-alt"></i></div>
                    <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No tickets raised yet</p>
                </div>
            <?php else: ?>
                <?php foreach($queries as $q): 
                    $status = $q['status'];
                    $status_ui = [
                        'pending'   => ['bg' => 'bg-slate-100', 'text' => 'text-slate-600', 'icon' => 'fa-clock'],
                        'reviewed'  => ['bg' => 'bg-amber-50', 'text' => 'text-amber-600', 'icon' => 'fa-search'],
                        'solved'    => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'icon' => 'fa-check-circle']
                    ];
                    $ui = $status_ui[$status] ?? $status_ui['pending'];
                ?>
                <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm border-l-4 <?= str_replace('text', 'border', $ui['text']) ?>">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex items-center gap-3">
                            <span class="text-[9px] font-black px-2.5 py-1 rounded-lg uppercase <?= $ui['bg'] ?> <?= $ui['text'] ?>">
                                <i class="fas <?= $ui['icon'] ?> mr-1"></i> <?= $status ?>
                            </span>
                            <span class="text-[10px] font-bold text-slate-400 uppercase"><?= date('d M, Y', strtotime($q['created_at'])) ?></span>
                        </div>
                        <span class="text-[10px] font-mono text-slate-300">#TK-<?= $q['id'] ?></span>
                    </div>
                    <h3 class="font-black text-slate-900 mb-2"><?= htmlspecialchars($q['subject']) ?></h3>
                    <p class="text-sm text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($q['message'])) ?></p>
                    
                    <?php if(!empty($q['reply'])): ?>
                    <div class="mt-6 p-5 bg-blue-50 rounded-2xl border border-blue-100 relative">
                        <div class="text-[9px] font-black text-blue-600 uppercase tracking-widest mb-2 flex items-center gap-1"><i class="fas fa-robot"></i> Official Response</div>
                        <p class="text-xs font-bold text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($q['reply'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<footer class="bg-white border-t border-slate-200 py-6 text-center mt-12">
    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">© <?= date('Y') ?> KhataLink — Merchant Support</div>
</footer>

<script>
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }
</script>
</body>
</html>