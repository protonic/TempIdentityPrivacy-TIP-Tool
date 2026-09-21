<?php
// Don't require config here - let the calling script handle it

class Database
{
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $charset = 'utf8mb4';

    public function __construct()
    {
        // Validate required database configuration constants
        $required_constants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];

        foreach ($required_constants as $constant) {
            if (!defined($constant)) {
                error_log('Database configuration error: Missing required configuration');
                throw new Exception('Database configuration not properly initialized');
            }
        }

        // Set database connection parameters from configuration
        $this->host = DB_HOST;
        $this->db_name = DB_NAME;
        $this->username = DB_USER;
        $this->password = DB_PASS;
    }
    private $conn;

    public function connect()
    {
        if ($this->conn) {
            return $this->conn;
        }

        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false
            ];

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            return $this->conn;
        } catch (PDOException $e) {
            error_log('Database connection error: ' . $e->getMessage());
            die('Database connection failed.');
        }
    }
}
