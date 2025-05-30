<?php

// Description: Class to manage database connections.

class Database {
    private $host = 'localhost'; // Change if your database host is different
    private $db_name = 'coba';   // Your database name
    private $username = 'root';  // Your database username
    private $password = 'Iqbal#0811'; // Your database password (SECURE THIS!)
    public $conn;

    public function connect() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            // Log the error to the server log, do not display error details to the user
            error_log("Database connection error: " . $e->getMessage());
            // Provide a more generic error message to the user
            http_response_code(500); // Internal Server Error
            echo json_encode(['error' => 'Database connection failed. Please try again later.']);
            exit; // Halt script execution
        }
        return $this->conn;
    }

    public function closeConnection() {
        $this->conn = null;
    }
}
?>