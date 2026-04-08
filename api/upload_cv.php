<?php
// api/upload_cv.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once '../config/Database.php';

// Función auxiliar simple para leer PDF sin librerías externas (Vendor)
function extractTextFromPDF($filename) {
    // Intenta leer el contenido crudo
    $content = @file_get_contents($filename);
    if (!$content) return "";
    
    // Algoritmo básico para extraer texto de streams PDF
    $text = "";
    if (preg_match_all('/BT[\s\r\n]+([\s\S]+?)[\s\r\n]+ET/', $content, $matches)) {
        foreach ($matches[1] as $block) {
            if (preg_match_all('/\((.*?)\)/', $block, $texts)) {
                foreach ($texts[1] as $t) {
                    $text .= $t . " ";
                }
            }
        }
    }
    // Limpieza básica
    return substr(strip_tags($text), 0, 5000); // Limitamos a 5000 caracteres para no saturar a Gemini
}

$response = ['status' => 'error', 'message' => 'Invalid request'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cv_file']) && isset($_POST['candidate_id'])) {
    try {
        $cid = $_POST['candidate_id'];
        $file = $_FILES['cv_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Validar PDF
        if ($ext !== 'pdf') throw new Exception("Solo se permiten archivos PDF.");

        // Crear carpeta si no existe
        $uploadDir = "../uploads/cvs/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $newFileName = "cv_" . $cid . "_" . time() . ".pdf";
        $targetPath = $uploadDir . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            
            // 1. Extraer Texto para la IA
            $extractedText = extractTextFromPDF($targetPath);
            if(strlen($extractedText) < 50) $extractedText = "CV Adjunto pero no se pudo extraer texto automáticamente.";

            // 2. Guardar en BD
            $db = (new Database())->getConnection();
            $stmt = $db->prepare("UPDATE gsd_candidates SET cv_filename = ?, cv_text = ? WHERE id = ?");
            $stmt->execute([$newFileName, $extractedText, $cid]);

            $response = [
                'status' => 'success', 
                'cv_url' => 'uploads/cvs/' . $newFileName,
                'message' => 'CV uploaded successfully'
            ];
        } else {
            throw new Exception("Error moviendo el archivo al servidor.");
        }

    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
}

echo json_encode($response);
?>