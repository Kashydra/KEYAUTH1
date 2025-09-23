<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Configurações do banco de dados
$servername = "localhost";
$username = "seu_usuario";
$password = "sua_senha";
$dbname = "keyauth_db";

// Senha do administrador - MUDE ESTA SENHA!
$ADMIN_PASSWORD = "GHOSTDEVOWNER_2025/2000_LOL";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode([
        'success' => false, 
        'message' => 'Erro de conexão: ' . $e->getMessage()
    ]));
}

// Criar tabelas se não existirem
function createTables($pdo) {
    $sql = "CREATE TABLE IF NOT EXISTS keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        key_code VARCHAR(50) UNIQUE NOT NULL,
        key_name VARCHAR(255) DEFAULT '',
        key_type VARCHAR(50) NOT NULL,
        custom_days INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        used_at TIMESTAMP NULL,
        expires_at TIMESTAMP NULL,
        is_active BOOLEAN DEFAULT TRUE,
        is_disabled BOOLEAN DEFAULT FALSE,
        hwid VARCHAR(255) NULL,
        ip_address VARCHAR(45) NULL,
        last_check TIMESTAMP NULL,
        INDEX idx_key_code (key_code),
        INDEX idx_expires_at (expires_at),
        INDEX idx_active (is_active),
        INDEX idx_disabled (is_disabled)
    )";
    
    $pdo->exec($sql);
    
    // Tabela de logs
    $sqlLogs = "CREATE TABLE IF NOT EXISTS key_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        key_code VARCHAR(50) NOT NULL,
        action ENUM('verify', 'activate', 'edit', 'disable', 'enable', 'delete', 'create') NOT NULL,
        ip_address VARCHAR(45),
        hwid VARCHAR(255),
        details TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_key_code (key_code),
        INDEX idx_created_at (created_at)
    )";
    
    $pdo->exec($sqlLogs);
}

createTables($pdo);

// Verificar senha de admin
function verifyAdminPassword($password) {
    global $ADMIN_PASSWORD;
    return hash_equals($ADMIN_PASSWORD, $password);
}

// Registrar log
function logAction($pdo, $keyCode, $action, $ip, $hwid = null, $details = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO key_logs (key_code, action, ip_address, hwid, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$keyCode, $action, $ip, $hwid, $details]);
    } catch(Exception $e) {
        // Log silencioso
    }
}

// Gerar código de key
function generateKeyCode($prefix = 'GHOST') {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $result = strtoupper($prefix) . '-';
    for ($i = 0; $i < 4; $i++) {
        if ($i > 0) $result .= '-';
        for ($j = 0; $j < 4; $j++) {
            $result .= $chars[rand(0, strlen($chars) - 1)];
        }
    }
    return $result;
}

// Calcular expiração
function calculateExpiration($type, $customDays = null) {
    if ($customDays) {
        return date('Y-m-d H:i:s', strtotime("+{$customDays} days"));
    }
    
    $days = [
        'trial' => 3,
        'daily' => 1,
        'weekly' => 7,
        'monthly' => 30,
        'quarterly' => 90,
        'yearly' => 365,
        'lifetime' => 36500
    ];
    
    $duration = isset($days[$type]) ? $days[$type] : 30;
    return date('Y-m-d H:i:s', strtotime("+{$duration} days"));
}

// Processar requisições
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'];

switch ($action) {
    case 'verify':
        $keyCode = $_POST['key'] ?? $_GET['key'] ?? '';
        $hwid = $_POST['hwid'] ?? $_GET['hwid'] ?? '';
        
        if (empty($keyCode)) {
            echo json_encode(['success' => false, 'message' => 'Key não fornecida']);
            break;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM keys WHERE key_code = ? AND is_active = 1");
        $stmt->execute([$keyCode]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$key) {
            logAction($pdo, $keyCode, 'verify', $ip, $hwid, 'Key not found');
            echo json_encode(['success' => false, 'message' => 'Key não encontrada']);
            break;
        }
        
        if ($key['is_disabled']) {
            logAction($pdo, $keyCode, 'verify', $ip, $hwid, 'Key disabled');
            echo json_encode(['success' => false, 'message' => 'Key foi desativada']);
            break;
        }
        
        $now = date('Y-m-d H:i:s');
        
        // Atualizar último check
        $stmt = $pdo->prepare("UPDATE keys SET last_check = ? WHERE key_code = ?");
        $stmt->execute([$now, $keyCode]);
        
        if (!$key['used_at']) {
            // Primeira utilização
            $expiresAt = $key['custom_days'] ? 
                calculateExpiration(null, $key['custom_days']) : 
                calculateExpiration($key['key_type']);
            
            $stmt = $pdo->prepare("UPDATE keys SET used_at = ?, expires_at = ?, hwid = ?, ip_address = ? WHERE key_code = ?");
            $stmt->execute([$now, $expiresAt, $hwid, $ip, $keyCode]);
            
            logAction($pdo, $keyCode, 'activate', $ip, $hwid, 'First activation');
            
            echo json_encode([
                'success' => true,
                
