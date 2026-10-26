<?php
require_once __DIR__ . '/config.php';

class Database
{
    private $host = DB_HOST;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $dbname = DB_NAME;
    private $connection;

    public function connect()
    {
        $this->connection = null;
        try {
            $this->connection = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->dbname,
                $this->user,
                $this->pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("Database connection failed. Please try again later.");
        }
        return $this->connection;
    }
}
