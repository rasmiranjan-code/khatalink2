<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); } // Admin session check

// Fetch current admin role for the navbar display
$stmt_role = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
$stmt_role->execute([$_SESSION['admin_id']]);
$admin_role = $stmt_role->fetchColumn() ?: 'team';

// Handle Status & Reply Update
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ticket'])) {
    $stmt = $pdo->prepare("UPDATE support_queries SET status = ?, reply = ? WHERE id = ?");
    $stmt->execute([$_POST['status'], $_POST['reply'], $_POST['ticket_id']]);
    header("Location: queries.php?success=Ticket updated successfully");
    exit();
}

$queries = $pdo->query("
    SELECT sq.*, 
           CASE WHEN sq.user_type = 'shop' THEN s.shop_name ELSE c.name END as sender_name,
           CASE WHEN sq.user_type = 'shop' THEN s.email ELSE c.email END as sender_email
    FROM support_queries sq
    LEFT JOIN shop_owners s ON sq.user_id = s.id AND sq.user_type = 'shop'
    LEFT JOIN customers c ON sq.user_id = c.id AND sq.user_type = 'customer'
    ORDER BY sq.status ASC, sq.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Queries — KhataLink Admin</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- FIX 1: Missing opening < on <style> tag was the main bug -->
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        .kl-navbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .layout { display: flex; min-height: calc(100vh - 64px); }
        .main { flex: 1; padding: 32px; overflow-x: hidden; }
        .page-title { font-size: 26px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; margin-bottom: 4px; }
        .page-subtitle { font-size: 14px; color: #64748b; margin-bottom: 28px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 998; }
        @media (max-width: 992px) { .sidebar-overlay.show { display: block; } .main { padding: 20px 16px; } }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>

<nav class="kl-navbar">
    <div style="display:flex; align-items:center; gap:16px;">
        <!-- FIX 2: openSidebar() function was called but never defined — added below in JS -->
        <button class="lg:hidden text-slate-600" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
        <a href="dashboard.php">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="Logo" style="height: 40px;">
        </a>
    </div>
    <div class="text-right">
        <span class="text-sm font-bold text-slate-700"><?= htmlspecialchars($_SESSION['admin_name']) ?></span>
    </div>
</nav>

<div class="layout">
    <?php include '../includes/admin_sidebar.php'; ?>

    <div class="main">
        <div class="page-title">Support Help Desk</div>
        <div class="page-subtitle">Track and respond to merchant and customer queries.</div>

        <?php if(isset($_GET['success'])): ?>
            <div class="bg-emerald-50 text-emerald-600 p-4 rounded-2xl border border-emerald-100 mb-6 font-bold text-sm">
                <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($_GET['success']) ?>
            </div>
        <?php endif; ?>

        <!-- FIX 3: Added empty state message when no queries exist -->
        <?php if(empty($queries)): ?>
            <div class="bg-white p-10 rounded-[2.5rem] border border-slate-200 text-center text-slate-400 font-bold text-sm">
                <i class="fas fa-inbox text-3xl mb-3 block"></i>
                No support queries found.
            </div>
        <?php endif; ?>

        <div class="space-y-6">
            <?php foreach($queries as $q): 
                // FIX 4: Safely fallback status if value is unexpected
                $status = in_array($q['status'] ?? '', ['pending', 'reviewed', 'solved']) ? $q['status'] : 'pending';

                $status_config = [
                    'pending'   => ['bg' => 'bg-slate-100',  'text' => 'text-slate-600',  'border' => 'border-slate-400',  'label' => 'Pending'],
                    'reviewed'  => ['bg' => 'bg-amber-50',   'text' => 'text-amber-600',  'border' => 'border-amber-400',  'label' => 'Reviewed'],
                    'solved'    => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600','border' => 'border-emerald-400','label' => 'Solved']
                ];
                $cfg = $status_config[$status];
            ?>
            <!-- FIX 5: Border color now uses a dedicated 'border' key instead of broken str_replace hack -->
            <div class="bg-white p-8 rounded-[2.5rem] border border-slate-200 shadow-sm hover:shadow-md transition-all border-l-8 <?= $cfg['border'] ?>">
                <div class="flex justify-between items-start mb-6">
                    <div class="flex flex-col md:flex-row gap-4">
                        <span class="text-[9px] font-black px-3 py-1 rounded-lg uppercase <?= $q['user_type'] == 'shop' ? 'bg-blue-50 text-blue-600' : 'bg-purple-50 text-purple-600' ?>">
                            <i class="fas <?= $q['user_type'] == 'shop' ? 'fa-store' : 'fa-user' ?> mr-1"></i> <?= htmlspecialchars($q['user_type']) ?> Query
                        </span>
                        <span class="text-xs text-slate-400 font-bold"><?= date('d M, h:i A', strtotime($q['created_at'])) ?></span>
                    </div>
                    <div class="flex gap-2">
                        <a href="export_query_pdf.php?id=<?= (int)$q['id'] ?>" target="_blank" 
                           class="w-9 h-9 flex items-center justify-center bg-slate-50 text-slate-400 rounded-xl hover:text-red-600 transition-all" 
                           title="Export PDF">
                            <i class="fas fa-file-pdf"></i>
                        </a>
                        <button onclick="sendManualMail(<?= (int)$q['id'] ?>, this)" 
                                class="w-9 h-9 flex items-center justify-center bg-slate-50 text-slate-400 rounded-xl hover:text-blue-600 transition-all" 
                                title="Send Email Notification">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($q['sender_name'] ?? 'Unknown Sender') ?></div>
                    <div class="text-[10px] text-slate-400 font-bold uppercase"><?= htmlspecialchars($q['sender_email'] ?? '') ?></div>
                </div>

                <h3 class="text-lg font-black text-slate-900 mb-3"><?= htmlspecialchars($q['subject'] ?? '') ?></h3>
                <p class="text-sm text-slate-600 leading-relaxed bg-slate-50 p-5 rounded-2xl border border-slate-100 mb-6"><?= nl2br(htmlspecialchars($q['message'] ?? '')) ?></p>

                <!-- FIX 6: Added CSRF-safe hidden field pattern and integer cast on ticket_id -->
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="ticket_id" value="<?= (int)$q['id'] ?>">
                    <input type="hidden" name="update_ticket" value="1">
                    
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-slate-400 mb-2">Admin Official Response</label>
                        <textarea name="reply" rows="3" 
                                  class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" 
                                  placeholder="Write your response here..."><?= htmlspecialchars($q['reply'] ?? '') ?></textarea>
                    </div>

                    <div class="flex flex-col md:flex-row items-center justify-between gap-4 pt-4 border-t border-slate-50">
                        <div class="flex gap-2 items-center">
                            <select name="status" class="bg-slate-100 border-none rounded-xl text-[10px] font-black uppercase px-4 py-2.5 outline-none cursor-pointer">
                                <option value="pending"  <?= $status === 'pending'  ? 'selected' : '' ?>>Pending (Gray)</option>
                                <option value="reviewed" <?= $status === 'reviewed' ? 'selected' : '' ?>>Reviewed (Yellow)</option>
                                <option value="solved"   <?= $status === 'solved'   ? 'selected' : '' ?>>Solved (Green)</option>
                            </select>
                            <button type="submit" class="bg-blue-600 text-white text-[10px] font-black px-6 py-2.5 rounded-xl uppercase tracking-widest hover:bg-blue-700 transition-all shadow-lg shadow-blue-100">
                                Update Ticket
                            </button>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="px-4 py-1.5 rounded-lg font-black text-[10px] uppercase <?= $cfg['bg'] ?> <?= $cfg['text'] ?>"><?= $cfg['label'] ?></span>
                            <span class="text-[10px] font-bold text-slate-300 uppercase tracking-widest">#TK-<?= (int)$q['id'] ?></span>
                        </div>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    // FIX 7: openSidebar() and closeSidebar() were called but never defined
    function openSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        if (sidebar) sidebar.classList.add('show', 'active');
        if (overlay) overlay.classList.add('show');
    }

    function closeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        if (sidebar) sidebar.classList.remove('show', 'active');
        if (overlay) overlay.classList.remove('show');
    }

    async function sendManualMail(id, btn) {
        const icon = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        try {
            const res = await fetch(`ajax_send_query_mail.php?id=${id}`);
            // FIX 8: Added check for non-OK HTTP responses before parsing JSON
            if (!res.ok) throw new Error(`Server error: ${res.status}`);
            const data = await res.json();
            alert(data.message ?? 'Done.');
        } catch (e) {
            alert("Email trigger failed: " + e.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = icon;
        }
    }
</script>

</body>
</html>