<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php'; // For session validation

// 1. Authentication: Get shop_id securely from the server-side session.
if (!isset($_SESSION['shop_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'answer' => 'Unauthorized. Please log in again.']);
    exit();
}
$shop_id = (int)$_SESSION['shop_id'];

// 2. Get user question from the request body.
$request_body = json_decode(file_get_contents('php://input'), true);
$question = $request_body['question'] ?? '';

if (empty($question)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'answer' => 'Question cannot be empty.']);
    exit();
}

// 3. Prepare the request for the Python AI service.
$fastapi_url = 'http://127.0.0.1:8000/api/analyze'; // FIX: Changed endpoint from /api/chat to /api/analyze
$payload = json_encode([
    'question' => $question,
    'shop_id' => $shop_id // Add the authenticated shop_id
]);

// 4. Call the Python service using cURL.
$ch = curl_init($fastapi_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30-second timeout

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 4.5. Improved Error Handling
if ($response === false || $http_code >= 400) {
    error_log("AI Handler Error: Failed to connect to Python service. HTTP Code: " . $http_code . ". Response: " . $response);
    http_response_code(502); // Bad Gateway
    echo json_encode(['success' => false, 'answer' => 'Sorry, the analytics service is currently unavailable.']);
    exit();
}

// 5. Forward the Python service's response to the frontend.
http_response_code($http_code);
echo $response;
?>