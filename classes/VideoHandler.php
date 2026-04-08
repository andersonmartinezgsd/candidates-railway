<?php
// classes/VideoHandler.php
require_once __DIR__ . '/../config/Database.php';

class VideoHandler {
    private $conn;
    private $table_name = "gsd_candidates";

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        // Asegurar que PDO no emule prepares para evitar errores de tipos
        $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * PROCESO 1: Subida desde la Web en Vivo (JS)
     * Ahora espera: $fileProcessed (CON FONDO), $fileOriginal (SIN FONDO)
     */
    public function processUpload($token, $firstName, $lastName, $fullName, $email, $transcript, $score, $fileProcessed, $fileOriginal = null, $userDataJson = null) {
    
        if(empty($token) || $token == 'GSD-AUTO-GEN') {
            $token = 'GSD-' . strtoupper(bin2hex(random_bytes(4)));
        }
    
        $safeToken = preg_replace('/[^a-zA-Z0-9-]/', '', $token);
        
        // --- 1. Procesar el video ORIGINAL (SIN FONDO) ---
        $originalDbPath = null;
        $originalUploadDir = "../uploads/" . $safeToken . "/originals/";
        if (!file_exists($originalUploadDir)) { mkdir($originalUploadDir, 0777, true); }
    
        $originalFileName = "original_" . time() . ".webm";
        $originalTargetPath = $originalUploadDir . $originalFileName;
        $originalDbPath = "uploads/" . $safeToken . "/originals/" . $originalFileName;
    
        // El archivo ORIGINAL llega como $fileOriginal
        if ($fileOriginal && move_uploaded_file($fileOriginal['tmp_name'], $originalTargetPath)) {
            // OK
        } else {
            // Si el original no se movió, es un error grave.
            return ['status' => 'error', 'message' => 'Error moviendo archivo original (SIN FONDO)'];
        }
    
        // --- 2. Procesar el video PROCESADO (CON FONDO) ---
        $processedDbPath = null;
        $processedUploadDir = "../uploads/" . $safeToken . "/processed/";
        if (!file_exists($processedUploadDir)) { mkdir($processedUploadDir, 0777, true); }
    
        $processedFileName = "processed_" . time() . ".webm"; 
        $processedTargetPath = $processedUploadDir . $processedFileName;
        $processedDbPath = "uploads/" . $safeToken . "/processed/" . $processedFileName;
    
        // El archivo CON FONDO llega como $fileProcessed
        if (!move_uploaded_file($fileProcessed['tmp_name'], $processedTargetPath)) {
            return ['status' => 'error', 'message' => 'Error moviendo archivo procesado (CON FONDO)'];
        }
    
        // Guardar en BD: El video PROCESADO se guarda en la columna de PROCESADO
        $result = $this->saveToDBWithProcessedPath($token, $firstName, $lastName, $fullName, $email, $transcript, $score, $originalDbPath, $processedDbPath, $userDataJson);
        
        return $result; // Devolver el resultado de la BD
    }
    
    // *** NUEVA FUNCIÓN DE AYUDA PARA GUARDAR AMBOS ***
    private function saveToDBWithProcessedPath($token, $firstName, $lastName, $fullName, $email, $transcript, $score, $originalPath, $processedPath, $json) {
        try {
            // Verificar si existe
            $check = $this->conn->prepare("SELECT id FROM " . $this->table_name . " WHERE token = ?");
            $check->execute([$token]);
    
            if ($check->rowCount() > 0) {
                // UPDATE: Guardamos el original y el procesado
                $sql = "UPDATE " . $this->table_name . " 
                        SET first_name=?, last_name=?, name=?, email=?, transcript=?, sentiment_score=?, video_original_path=?, video_processed_path=?, biometric_json=?, processing_status='completed'
                        WHERE token=?";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$firstName, $lastName, $fullName, $email, $transcript, $score, $originalPath, $processedPath, $json, $token]);
            } else {
                // INSERT: Guardamos el original y el procesado
                $sql = "INSERT INTO " . $this->table_name . " 
                        (token, first_name, last_name, name, email, transcript, sentiment_score, video_original_path, video_processed_path, biometric_json, processing_status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$token, $firstName, $lastName, $fullName, $email, $transcript, $score, $originalPath, $processedPath, $json]);
            }
            
            // Si la BD fue exitosa, devolvemos el éxito
            return [
                'status' => 'success', 
                'message' => 'Video subido y guardado correctamente. Proceso finalizado en el cliente.', 
                'token' => $token,
                'processed_path' => $processedPath
            ];

        } catch (Exception $e) {
            error_log("Error BD saveToDBWithProcessedPath: " . $e->getMessage());
            return ['status' => 'error', 'message' => 'Database error on save: ' . $e->getMessage()];
        }
    }

    /**
     * Helper para guardar en DB desde la Web en Vivo
     */
    private function saveToDB($token, $firstName, $lastName, $fullName, $email, $transcript, $score, $dbPath, $json) {
        try {
            // Verificar si existe
            $check = $this->conn->prepare("SELECT id FROM " . $this->table_name . " WHERE token = ?");
            $check->execute([$token]);

            if ($check->rowCount() > 0) {
                // UPDATE
                $sql = "UPDATE " . $this->table_name . " 
                        SET first_name=?, last_name=?, name=?, email=?, transcript=?, sentiment_score=?, video_original_path=?, biometric_json=?, processing_status='pending', video_processed_path=NULL 
                        WHERE token=?";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$firstName, $lastName, $fullName, $email, $transcript, $score, $dbPath, $json, $token]);
            } else {
                // INSERT
                $sql = "INSERT INTO " . $this->table_name . " 
                        (token, first_name, last_name, name, email, transcript, sentiment_score, video_original_path, biometric_json, processing_status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$token, $firstName, $lastName, $fullName, $email, $transcript, $score, $dbPath, $json]);
            }
        } catch (Exception $e) {
            error_log("Error BD processUpload: " . $e->getMessage());
        }
    }
    
    private function startAsyncProcessing($absoluteOriginalPath, $token) {
        $pythonScriptPath = realpath(__DIR__ . '/../scripts/process_video.py');
        if ($pythonScriptPath) {
            $command = "python3 {$pythonScriptPath} --input \"{$absoluteOriginalPath}\" --token \"{$token}\" > /dev/null 2>&1 &";
            shell_exec($command);
        }
    }

    /**
     * PROCESO 2: Subida Masiva desde Python Local (Batch)
     */
    public function processBatchUpload($token, $name, $email, $transcript, $summary, $file, $original_filename = '') {
        try {
            if(empty($token) || $token == 'GSD-AUTO-GEN') {
                $token = 'BATCH-' . strtoupper(bin2hex(random_bytes(4)));
            }

            $safeToken = preg_replace('/[^a-zA-Z0-9-]/', '', $token);
            $uploadDir = "../uploads/" . $safeToken . "/processed/";
            
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileName = !empty($original_filename) ? $original_filename : "final_" . time() . ".mp4"; 
            $targetPath = $uploadDir . $fileName;
            $dbPath = "uploads/" . $safeToken . "/processed/" . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                return ['status' => 'error', 'message' => 'Error moviendo archivo al disco'];
            }

            // Upsert en Base de Datos
            $check = $this->conn->prepare("SELECT id FROM " . $this->table_name . " WHERE token = ?");
            $check->execute([$token]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $sql = "UPDATE " . $this->table_name . " 
                        SET name=?, email=?, transcript=?, ai_analysis=?, video_processed_path=?, processing_status='completed', updated_at=NOW() 
                        WHERE token=?";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$name, $email, $transcript, $summary, $dbPath, $token]);
                $candidateId = $existing['id'];
            } else {
                $sql = "INSERT INTO " . $this->table_name . " 
                        (token, name, email, transcript, ai_analysis, video_processed_path, processing_status, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, 'completed', NOW(), NOW())";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$token, $name, $email, $transcript, $summary, $dbPath]);
                $candidateId = $this->conn->lastInsertId();
            }

            return [
                'status' => 'success', 
                'message' => 'Candidato importado correctamente', 
                'token' => $token,
                'id' => $candidateId,
                'path' => $dbPath
            ];

        } catch (Exception $e) {
            error_log("Error Batch Upload: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
?>