<?php
// Set JSON response header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'invalid',
        'signature' => '',
        'timestamp' => time()
    ]);
    exit;
}

// Get POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate request structure
if (!$data || !isset($data['key']) || !isset($data['product']) || !isset($data['info'])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'invalid',
        'signature' => '',
        'timestamp' => time()
    ]);
    exit;
}

$licenseKey = $data['key'];
$product = $data['product'];
$domain = $data['info']['domain'] ?? null;
$ownerName = $data['info']['owner_name'] ?? null;
$panelVersion = $data['info']['panel_version'] ?? null;
$serverIp = $data['info']['ip_address'] ?? null;
$controllerHash = $data['info']['controller_hash'] ?? null;

// Expected hash of the original controller file
$expectedHash = 'c19a677e07d393f6b32ccd9cf1cb9c003b0ec77e5e3789e03e00832f4f07d5fe';

// Verify controller file integrity (prevent tampering)
if (!$controllerHash || !hash_equals($expectedHash, $controllerHash)) {
    // Log security violation
    error_log("Security violation: License=$licenseKey, Product=$product, Domain=$domain, Hash=$controllerHash");
    
    // Return invalid response without revealing the reason
    http_response_code(401);
    echo json_encode([
        'status' => 'invalid',
        'signature' => '',
        'timestamp' => time()
    ]);
    exit;
}

// Database configuration - using dedicated user with limited permissions
$dbHost = 'localhost';
$dbName = 'blueprint_api';
$dbUser = 'blueprint_api';
$dbPass = '110711';

// Verification secret for HMAC signature (stored securely)
$verificationSecret = 'lwmmAa4/xXYtuMj6ti9dR7XICV9X52PxFfgqR1BPf2M=';

try {
    // Create PDO connection with error handling
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements for security
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    // Rate limiting: Check if IP has made too many requests
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $rateLimitWindow = 60; // 60 seconds
    $maxRequestsPerWindow = 30; // Max 30 requests per minute
    
    // Clean up old rate limit entries (older than 5 minutes)
    $pdo->exec("DELETE FROM rate_limits WHERE last_request < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    
    // Check current rate limit
    $rateStmt = $pdo->prepare(
        "SELECT id, request_count, UNIX_TIMESTAMP(last_request) as last_req_time 
         FROM rate_limits 
         WHERE ip_address = ? AND license_key = ? 
         AND last_request > DATE_SUB(NOW(), INTERVAL ? SECOND)
         LIMIT 1"
    );
    $rateStmt->execute([$ipAddress, $licenseKey, $rateLimitWindow]);
    $rateLimit = $rateStmt->fetch();
    
    if ($rateLimit) {
        if ($rateLimit['request_count'] >= $maxRequestsPerWindow) {
            // Rate limit exceeded
            http_response_code(429);
            echo json_encode([
                'status' => 'invalid',
                'signature' => '',
                'timestamp' => time()
            ]);
            exit;
        }
        
        // Update request count
        $updateStmt = $pdo->prepare("UPDATE rate_limits SET request_count = request_count + 1, last_request = NOW() WHERE id = ?");
        $updateStmt->execute([$rateLimit['id']]);
    } else {
        // Create new rate limit entry
        $insertStmt = $pdo->prepare("INSERT INTO rate_limits (ip_address, license_key, request_count) VALUES (?, ?, 1)");
        $insertStmt->execute([$ipAddress, $licenseKey]);
    }
    
    // Use prepared statement to prevent SQL injection
    $stmt = $pdo->prepare(
        "SELECT license_key, product, status FROM licences WHERE license_key = ? AND product = ? LIMIT 1"
    );
    $stmt->execute([$licenseKey, $product]);
    
    $license = $stmt->fetch();
    
    // Determine response status
    if ($license && $license['status'] === 'active') {
        $responseStatus = 'good';
        $httpCode = 200;
    } elseif ($license && $license['status'] === 'inactive') {
        $responseStatus = 'bad';
        $httpCode = 403;
    } else {
        $responseStatus = 'invalid';
        $httpCode = 401;
    }
    
    // Log the verification attempt with all provided info and response
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $logStmt = $pdo->prepare(
        "INSERT INTO verification_logs (license_key, product, domain, owner_name, panel_version, server_ip, controller_hash, ip_address, request_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $logStmt->execute([$licenseKey, $product, $domain, $ownerName, $panelVersion, $serverIp, $controllerHash, $ipAddress, $responseStatus]);
    
    // Generate timestamp for response
    $timestamp = time();
    
    // Generate cryptographic signature ONLY for valid licenses
    if ($responseStatus === 'good') {
        $payload = $licenseKey . '|' . $timestamp . '|' . $domain;
        $signature = hash_hmac('sha256', $payload, $verificationSecret);
    } else {
        $signature = '';
    }
    
    // Send signed response
    http_response_code($httpCode);
    echo json_encode([
        'status' => $responseStatus,
        'signature' => $signature,
        'timestamp' => $timestamp
    ]);
    
} catch (PDOException $e) {
    // Log error but don't expose database details to client
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'invalid',
        'signature' => '',
        'timestamp' => time()
    ]);
}
