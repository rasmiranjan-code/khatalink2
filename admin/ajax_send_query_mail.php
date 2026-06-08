<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/mailer.php';
if(!isset($_SESSION['admin_id'])) exit(json_encode(['success'=>false, 'message'=>'Unauthorized']));

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT sq.*, 
           CASE WHEN sq.user_type = 'shop' THEN s.shop_name ELSE c.name END as sender_name,
           CASE WHEN sq.user_type = 'shop' THEN s.email ELSE c.email END as sender_email
    FROM support_queries sq
    LEFT JOIN shop_owners s ON sq.user_id = s.id AND sq.user_type = 'shop'
    LEFT JOIN customers c ON sq.user_id = c.id AND sq.user_type = 'customer'
    WHERE sq.id = ?
");
$stmt->execute([$id]);
$q = $stmt->fetch();

if(!$q) exit(json_encode(['success'=>false, 'message'=>'Not found']));

$subject = "Update on your Ticket #TK-" . $id . " - " . $q['subject'];
$body = "
    <div style='font-family:Arial; border:1px solid #eee; padding:20px; border-radius:10px;'>
        <h3 style='color:#2563eb;'>KhataLink Support Update</h3>
        <p>Hi <b>{$q['sender_name']}</b>,</p>
        <p>Your ticket status has been updated to: <b>".strtoupper($q['status'])."</b></p>
        <div style='background:#f9fafb; padding:15px; border-radius:8px; margin:20px 0;'>
            <p style='font-size:12px; color:#666;'>Admin Response:</p>
            <p><b>".($q['reply'] ?: 'Our team is reviewing your query.')."</b></p>
        </div>
        <p style='font-size:11px; color:#999;'>Log in to your dashboard to view full details.</p>
    </div>
";

$sent = sendMail($q['sender_email'], $subject, $body);
echo json_encode(['success' => $sent, 'message' => $sent ? 'Email sent successfully!' : 'Email failed to send. Check mailer config.']);
?>