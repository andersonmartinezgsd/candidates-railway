<?php

require_once __DIR__.'/runtime.php';

class Database
{
    public $conn;

    public function getConnection()
    {
        $this->conn = null;

        $host = gsdRecruitmentEnv('DB_HOST', 'localhost');
        $port = gsdRecruitmentEnv('DB_PORT', '3306');
        $database = gsdRecruitmentEnv('DB_NAME', gsdRecruitmentEnv('DB_DATABASE', ''));
        $username = gsdRecruitmentEnv('DB_USER', gsdRecruitmentEnv('DB_USERNAME', ''));
        $password = gsdRecruitmentEnv('DB_PASS', gsdRecruitmentEnv('DB_PASSWORD', ''));
        $charset = gsdRecruitmentEnv('DB_CHARSET', 'utf8mb4');

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            $this->conn = new PDO($dsn, $username, $password);
            $this->conn->exec('set names utf8mb4');
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $exception) {
            echo 'Error de conexion: '.$exception->getMessage();
        }

        return $this->conn;
    }
}
