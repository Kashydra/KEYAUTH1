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

// Senha do administrador
$ADMIN_PASSWORD = "GHOSTDEVOWNER_2025/2000_LOL";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Erro de conexão: ' . $e->getMessage()]));
}

// Função para criar tabelas se não existirem
function createTables($pdo) {
    $sql = "CREATE TABLE IF NOT EXISTS keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        key_code VARCHAR(50) UNIQUE NOT NULL,
        key_type ENUM('daily', 'weekly', 'monthly', 'yearly', 'lifetime') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        used_at TIMESTAMP NULL,
        expires_at TIMESTAMP NULL,
        is_active BOOLEAN DEFAULT TRUE,
        hwid VARCHAR(255) NULL,
        ip_address VARCHAR(45) NULL
    )";
    
    $pdo->exec($sql);
}

createTables($pdo);

// Função para gerar código de key
function generateKeyCode() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $result = 'GHOST-';
    for ($i = 0; $i < 4; $i++) {
        if ($i > 0) $result .= '-';
        for ($j = 0; $j < 4; $j++) {
            $result .= $chars[rand(0, strlen($chars) - 1)];
        }
    }
    return $result;
}

// Função para calcular data de expiração
function calculateExpiration($type) {
    $days = [
        'daily' => 1,
        'weekly' => 7,
        'monthly' => 30,
        'yearly' => 365,
        'lifetime' => 36500
    ];
    
    return date('Y-m-d H:i:s', strtotime("+{$days[$type]} days"));
}

// Processar requisições
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'verify':
        $keyCode = $_POST['key'] ?? '';
        $hwid = $_POST['hwid'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'];
        
        if (empty($keyCode)) {
            echo json_encode(['success' => false, 'message' => 'Key não fornecida']);
            break;
        }
        
        // Buscar key no banco
        $stmt = $pdo->prepare("SELECT * FROM keys WHERE key_code = ? AND is_active = 1");
        $stmt->execute([$keyCode]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$key) {
            echo json_encode(['success' => false, 'message' => 'Key não encontrada']);
            break;
        }
        
        $now = date('Y-m-d H:i:s');
        
        // Se é primeira utilização
        if (!$key['used_at']) {
            $expiresAt = calculateExpiration($key['key_type']);
            
            $stmt = $pdo->prepare("UPDATE keys SET used_at = ?, expires_at = ?, hwid = ?, ip_address = ? WHERE key_code = ?");
            $stmt->execute([$now, $expiresAt, $hwid, $ip, $keyCode]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Key ativada com sucesso',
                'type' => $key['key_type'],
                'expires_at' => $expiresAt,
                'first_use' => true
            ]);
        } else {
            // Verificar se não expirou
            if ($now > $key['expires_at']) {
                echo json_encode(['success' => false, 'message' => 'Key expirada']);
                break;
            }
            
            // Verificar HWID se fornecido
            if ($hwid && $key['hwid'] && $key['hwid'] !== $hwid) {
                echo json_encode(['success' => false, 'message' => 'HWID não autorizado']);
                break;
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Key válida',
                'type' => $key['key_type'],
                'expires_at' => $key['expires_at'],
                'first_use' => false
            ]);
        }
        break;
        
    case 'create':
        $adminPass = $_POST['admin_password'] ?? '';
        $keyType = $_POST['key_type'] ?? 'daily';
        $quantity = intval($_POST['quantity'] ?? 1);
        
        if ($adminPass !== $ADMIN_PASSWORD) {
            echo json_encode(['success' => false, 'message' => 'Senha de administrador incorreta']);
            break;
        }
        
        if ($quantity < 1 || $quantity > 100) {
            echo json_encode(['success' => false, 'message' => 'Quantidade deve ser entre 1 e 100']);
            break;
        }
        
        $createdKeys = [];
        
        for ($i = 0; $i < $quantity; $i++) {
            do {
                $keyCode = generateKeyCode();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM keys WHERE key_code = ?");
                $stmt->execute([$keyCode]);
                $exists = $stmt->fetchColumn() > 0;
            } while ($exists);
            
            $stmt = $pdo->prepare("INSERT INTO keys (key_code, key_type) VALUES (?, ?)");
            $stmt->execute([$keyCode, $keyType]);
            
            $createdKeys[] = $keyCode;
        }
        
        echo json_encode([
            'success' => true,
            'message' => "$quantity key(s) criada(s) com sucesso",
            'keys' => $createdKeys
        ]);
        break;
        
    case 'list':
        $adminPass = $_POST['admin_password'] ?? '';
        
        if ($adminPass !== $ADMIN_PASSWORD) {
            echo json_encode(['success' => false, 'message' => 'Senha de administrador incorreta']);
            break;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM keys ORDER BY created_at DESC");
        $stmt->execute();
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'keys' => $keys]);
        break;
        
    case 'delete_expired':
        $adminPass = $_POST['admin_password'] ?? '';
        
        if ($adminPass !== $ADMIN_PASSWORD) {
            echo json_encode(['success' => false, 'message' => 'Senha de administrador incorreta']);
            break;
        }
        
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("DELETE FROM keys WHERE expires_at IS NOT NULL AND expires_at < ?");
        $stmt->execute([$now]);
        
        $deletedCount = $stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'message' => "$deletedCount key(s) expirada(s) removida(s)"
        ]);
        break;
        
    case 'stats':
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN used_at IS NULL THEN 1 ELSE 0 END) as unused,
                SUM(CASE WHEN used_at IS NOT NULL AND expires_at > NOW() THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 1 ELSE 0 END) as expired
            FROM keys
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Ação não reconhecida']);
        break;
}
?>