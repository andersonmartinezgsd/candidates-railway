<?php
// Archivo: api/manage_candidate.php
require_once __DIR__ . '/../../config/Database.php';

$database = new Database();
$pdo = $database->getConnection();

// 1. Recibimos la acción tanto por POST (Formularios) como por GET (Consultas de datos)
$action = $_POST['action'] ?? $_GET['action'] ?? '';
// 2. Recibimos el ID de la misma manera
$id = $_POST['id'] ?? $_GET['id'] ?? null;

if (!$action) {
    http_response_code(400);
    exit(json_encode(['error' => 'Action required']));
}

try {
    // ==========================================
    // ESTABLECER VIDEO PRINCIPAL
    // ==========================================
    if ($action === 'set_main') {
        if (!$id) throw new Exception("ID requerido");
        $email = $_POST['email'] ?? '';
        
        // 1. Convertimos TODOS los videos de ese email a "Alternativos" (is_main = 0)
        $stmt1 = $pdo->prepare("UPDATE gsd_candidates SET is_main = 0 WHERE email = ?");
        $stmt1->execute([$email]);
        
        // 2. Convertimos el ID seleccionado a "Principal" (is_main = 1)
        $stmt2 = $pdo->prepare("UPDATE gsd_candidates SET is_main = 1 WHERE id = ?");
        $stmt2->execute([$id]);
        
        echo json_encode(['status' => 'success']);
    }

    // ==========================================
    // EDITAR CANDIDATO
    // ==========================================
    elseif ($action === 'edit_candidate') {
        if (!$id) throw new Exception("ID requerido");
        
        $name = $_POST['name'] ?? '';
        $title = $_POST['title'] ?? '';
        $email = $_POST['email'] ?? ''; // Ahora editamos también el email
        
        $stmt = $pdo->prepare("UPDATE gsd_candidates SET name = ?, professional_title = ?, email = ? WHERE id = ?");
        $stmt->execute([$name, $title, $email, $id]);
        
        echo json_encode(['status' => 'success']);
    }

    // ==========================================
    // ENVIAR A PAPELERA (RECHAZAR)
    // ==========================================
    elseif ($action === 'reject') {
        if (!$id) throw new Exception("ID requerido");
        
        // Simplemente cambiamos el estado a 'rejected'. 
        // Ya no alteramos el email con "_deleted_" para poder recuperarlo limpio si es necesario.
        $stmt = $pdo->prepare("UPDATE gsd_candidates SET processing_status = 'rejected' WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['status' => 'success']);
    }

    // ==========================================
    // OBTENER RECHAZADOS (PAPELERA)
    // ==========================================
    elseif ($action === 'get_rejected') {
        // Trae los candidatos rechazados. (NO REQUIERE ID)
        $stmt = $pdo->query("SELECT id, name, professional_title FROM gsd_candidates WHERE processing_status = 'rejected' ORDER BY name ASC");
        $rejected = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($rejected);
    }

    // ==========================================
    // RESTAURAR DE LA PAPELERA
    // ==========================================
    elseif ($action === 'restore') {
        if (!$id) throw new Exception("ID requerido");
        
        // Lo regresamos a 'reviewing' para que vuelva a aparecer en el Master
        $stmt = $pdo->prepare("UPDATE gsd_candidates SET processing_status = 'reviewing' WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['status' => 'success']);
    }

    // ==========================================
    // OBTENER FEEDBACKS
    // ==========================================
    elseif ($action === 'get_feedbacks') {
        if (!$id) throw new Exception("ID requerido");
        
        $stmt = $pdo->prepare("SELECT * FROM gsd_candidate_feedback WHERE candidate_id = ? ORDER BY created_at DESC");
        $stmt->execute([$id]);
        $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($feedbacks);
    }

    // ==========================================
    // OBTENER TAGS DE UN CANDIDATO
    // ==========================================
    elseif ($action === 'get_tags') {
        if (!$id) throw new Exception("ID requerido");
        
        $stmt = $pdo->prepare("
            SELECT t.id, t.name 
            FROM gsd_tags t 
            JOIN gsd_candidate_tag_map m ON t.id = m.tag_id 
            WHERE m.candidate_id = ?
        ");
        $stmt->execute([$id]);
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($tags);
    }

    // ==========================================
    // AGREGAR TAG A CANDIDATO
    // ==========================================
    elseif ($action === 'add_tag') {
        if (!$id) throw new Exception("ID requerido");
        $tagId = $_POST['tag_id'] ?? null;
        
        if($tagId) {
            // INSERT IGNORE evita que de error de SQL si intentan agregar la misma etiqueta 2 veces
            $stmt = $pdo->prepare("INSERT IGNORE INTO gsd_candidate_tag_map (candidate_id, tag_id) VALUES (?, ?)");
            $stmt->execute([$id, $tagId]);
        }
        echo json_encode(['status' => 'success']);
    }

    // ==========================================
    // ELIMINAR TAG DE CANDIDATO
    // ==========================================
    elseif ($action === 'remove_tag') {
        if (!$id) throw new Exception("ID requerido");
        $tagId = $_POST['tag_id'] ?? null;
        
        if($tagId) {
            $stmt = $pdo->prepare("DELETE FROM gsd_candidate_tag_map WHERE candidate_id = ? AND tag_id = ?");
            $stmt->execute([$id, $tagId]);
        }
        echo json_encode(['status' => 'success']);
    }

    // Si la acción no existe
    else {
        http_response_code(400);
        echo json_encode(['error' => 'Acción inválida']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}