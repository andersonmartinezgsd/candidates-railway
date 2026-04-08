<?php
// --- CONFIGURACIÓN DE DEPURACIÓN ---
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

function fatalErrorHandler() {
    $error = error_get_last();
    if ($error !== null && $error['type'] === E_ERROR) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Fatal Error: ' . $error['message'] . ' on line ' . $error['line']
        ]);
    }
}
register_shutdown_function('fatalErrorHandler');

try {
    if (empty($_FILES) && empty($_POST)) {
        throw new Exception("No llegaron datos. Probablemente el archivo excede 'post_max_size' en php.ini");
    }

    // 1. CONFIGURACIÓN DE BASE DE DATOS
    $db_host = 'localhost';
    $db_name = 'u548288135_recruitmentgsd'; 
    $db_user = 'u548288135_recruitmentgsd';     // <-- CÁMBIALO
    $db_pass = 'xBtp$B||:>W6';        // <-- CÁMBIALO

    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. RECIBIR DATOS DEL FORMULARIO
    $firstName = trim($_POST['first_name'] ?? 'Candidate');
    $lastName  = trim($_POST['last_name'] ?? '');
    $email     = trim($_POST['email'] ?? 'no-email@example.com');
    $position  = trim($_POST['position'] ?? 'Unknown Position');
    
    $fullName  = trim($firstName . ' ' . $lastName);
    if(empty($fullName)) $fullName = 'Unknown';

    // 3. GENERAR EL TOKEN

    // Obtener iniciales (Firstname + Lastname)
    $names = explode(' ', trim($fullName));
    $firstInitial = strtoupper(substr($names[0], 0, 1));
    $lastInitial  = strtoupper(substr(end($names), 0, 1));
    
    $initials = $firstInitial . $lastInitial;
    
    // Generar parte aleatoria segura
    $randomPart = strtoupper(bin2hex(random_bytes(6))); // 12 chars
    
    // Construir token (máx ~20 chars)
    $token = "GSD-{$initials}-{$randomPart}";
    
    // Validar que no exista
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM gsd_candidates WHERE token = ?");
    $stmt->execute([$token]);
    
    // En caso de colisión (muy raro)
    while ($stmt->fetchColumn() > 0) {
        $randomPart = strtoupper(bin2hex(random_bytes(6)));
        $token = "GSD-{$initials}-{$randomPart}";
        
        $stmt->execute([$token]);
    }

    // 4. MANEJO DE DIRECTORIOS
    $uploadBaseDir = __DIR__ . '/../uploads/';
    $targetDir = $uploadBaseDir . $token . '/processed/';

    if (!file_exists($targetDir)) {
        if (!mkdir($targetDir, 0777, true)) {
            throw new Exception("Error creando directorio: $targetDir. Revisa permisos.");
        }
    }

    // 5. VERIFICAR Y MOVER EL VIDEO WEBM
    $videoBlob = $_FILES['video_blob'] ?? null;
    if (!$videoBlob || $videoBlob['error'] !== UPLOAD_ERR_OK) {
        $errCode = $videoBlob ? $videoBlob['error'] : 'No file sent';
        throw new Exception("Error en subida de video principal. Código PHP: " . $errCode);
    }

    $timestamp = time();
    $webmFile  = $targetDir . 'video_' . $timestamp . '.webm';
    $dbWebmPath = 'uploads/' . $token . '/processed/video_' . $timestamp . '.webm';

    if (!move_uploaded_file($videoBlob['tmp_name'], $webmFile)) {
        throw new Exception("Error moviendo el video al destino. Verifica permisos.");
    }

    // 6. GUARDAR EN LA BASE DE DATOS MYSQL (Ruta WEBM)
    $sql = "INSERT INTO gsd_candidates 
            (token, type, first_name, last_name, name, email, position_interest, video_processed_path, created_at, updated_at) 
            VALUES 
            (:token, 'candidate', :first_name, :last_name, :name, :email, :position, :video_path, NOW(), NOW())";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':token'      => $token,
        ':first_name' => $firstName,
        ':last_name'  => $lastName,
        ':name'       => $fullName,
        ':email'      => $email,
        ':position'   => $position,
        ':video_path' => $dbWebmPath // Guardamos la ruta del WebM
    ]);

    // 7. RESPUESTA DE ÉXITO AL FRONTEND
    echo json_encode([
        'status'   => 'success',
        'message'  => 'Candidato y video guardado correctamente.',
        'webm_url' => $dbWebmPath,
        'token'    => $token
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
