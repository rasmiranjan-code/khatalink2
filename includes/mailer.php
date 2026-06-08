<?php
// Path check kijiye ki aapne PHPMailer isi folder mein rakha hai
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

function sendMail(string $to, string $subject, string $body): bool {
    // Using fully qualified class names to resolve "Undefined type" errors in manual installations
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your-email@gmail.com'; // Apna Gmail dalein
        $mail->Password   = 'your-app-password';   // Gmail ka "App Password" use karein
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('your-email@gmail.com', 'KhataLink Support');
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return false;
    }
}

/**
 * Automated WhatsApp bhejane ke liye function.
 * Iske liye aapko ek WhatsApp API provider ki zaroorat hogi.
 */
function sendWhatsApp(string $phone, string $message): bool {
    // Placeholder: Aapke WhatsApp Gateway ki details yahan aayengi
    $api_key = "YOUR_API_KEY"; 
    $instance_id = "YOUR_INSTANCE_ID";
    
    // Aksar providers GET ya POST request use karte hain
    $url = "https://api.whatsapp-gateway.com/send?number=" . urlencode($phone) . "&message=" . urlencode($message) . "&apikey=" . $api_key . "&instance=" . $instance_id;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response !== false;
}

function getVerificationTemplate(string $name, string $link): string {
    return "
    <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <h2 style='color: #2563eb;'>Welcome to KhataLink!</h2>
        <p>Hi $name,</p>
        <p>Thank you for joining KhataLink. Please verify your email to activate your digital ledger.</p>
        <a href='$link' style='display: inline-block; padding: 12px 24px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 5px; font-weight: bold;'>Verify Email Address</a>
        <p style='margin-top: 20px; font-size: 12px; color: #666;'>If you didn't create an account, you can ignore this email.</p>
        <hr>
        <p style='font-size: 11px; color: #999;'>© 2025 KhataLink - India's Digital Udhar Khata</p>
    </div>
    ";
}
?>