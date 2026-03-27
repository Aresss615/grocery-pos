<?php
/**
 * J&J Grocery POS - Database Connection
 * Handles MySQL connection with error handling and display
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // Empty for XAMPP default
define('DB_NAME', 'grocery_pos');
define('DB_PORT', 3306);

// Application Settings
define('APP_NAME', 'J&J Grocery POS');
define('APP_VERSION', '1.0.0');
define('TIMEZONE', 'Asia/Manila');
define('TAX_RATE', 0.12); // 12% tax

// Paths
define('BASE_PATH', dirname(dirname(__FILE__)));
define('CONFIG_PATH', BASE_PATH . '/config');
define('AUTH_PATH', BASE_PATH . '/auth');
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('ASSETS_PATH', '/grocery-pos/assets');

// Set Timezone
date_default_timezone_set(TIMEZONE);

// Start Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Database Connection Class
 */
class Database {
    private $conn;
    private $error;
    
    public function __construct() {
        $this->connect();
    }
    
    /**
     * Connect to database with error handling
     */
    private function connect() {
        $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        
        if ($this->conn->connect_error) {
            $this->error = $this->conn->connect_error;
            $this->showConnectionError();
            die();
        }
        
        $this->conn->set_charset("utf8mb4");
    }
    
    /**
     * Display database connection error
     */
    private function showConnectionError() {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Database Connection Error</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: linear-gradient(135deg, #E53935 0%, #D32F2F 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .error-container {
                    background: white;
                    border-radius: 16px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                    padding: 40px;
                    max-width: 500px;
                    text-align: center;
                }
                .error-icon {
                    font-size: 64px;
                    margin-bottom: 20px;
                }
                h1 {
                    color: #E53935;
                    margin-bottom: 16px;
                    font-size: 28px;
                }
                p {
                    color: #666;
                    line-height: 1.6;
                    margin-bottom: 12px;
                    font-size: 14px;
                }
                .error-details {
                    background: #FEF2F2;
                    border: 2px solid #E53935;
                    border-radius: 8px;
                    padding: 16px;
                    margin: 24px 0;
                    text-align: left;
                    font-family: monospace;
                    font-size: 13px;
                    color: #C62828;
                    max-height: 200px;
                    overflow-y: auto;
                }
                .steps {
                    background: #F5F5F5;
                    border-radius: 8px;
                    padding: 20px;
                    text-align: left;
                    margin: 20px 0;
                }
                .steps h3 {
                    color: #1a1a1a;
                    margin-bottom: 12px;
                    font-size: 14px;
                }
                .steps ol {
                    margin-left: 20px;
                    font-size: 13px;
                    line-height: 1.8;
                    color: #555;
                }
                .steps li {
                    margin-bottom: 8px;
                }
                .steps code {
                    background: white;
                    padding: 2px 6px;
                    border-radius: 4px;
                    font-family: monospace;
                    color: #E53935;
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-icon">🚨</div>
                <h1>Database Connection Failed</h1>
                <p>Unable to connect to the database. Please check your configuration and try again.</p>
                
                <div class="error-details">
                    <strong>Error:</strong><br><?php echo htmlspecialchars($this->error); ?>
                </div>
                
                <div class="steps">
                    <h3>✓ Steps to Fix:</h3>
                    <ol>
                        <li>Ensure XAMPP is running (Apache & MySQL must be started)</li>
                        <li>Verify database name: <code><?php echo DB_NAME; ?></code> exists</li>
                        <li>Check credentials in <code>config/db.php</code>:
                            <ul style="margin-left: 20px; margin-top: 8px;">
                                <li>Host: <code><?php echo DB_HOST; ?></code></li>
                                <li>User: <code><?php echo DB_USER; ?></code></li>
                                <li>Database: <code><?php echo DB_NAME; ?></code></li>
                            </ul>
                        </li>
                        <li>Import database schema: <code>database.sql</code></li>
                        <li>Clear browser cache and refresh</li>
                    </ol>
                </div>
                
                <p><strong>Need help?</strong> Check SETUP.md file for detailed instructions.</p>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * Execute prepared statement for SELECT queries
     */
    public function query($sql, $params = [], $types = '') {
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            error_log("Query prepare failed: " . $this->conn->error);
            return false;
        }
        
        if (!empty($params) && !empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            error_log("Query execute failed: " . $stmt->error);
            return false;
        }
        
        return $stmt->get_result();
    }
    
    /**
     * Get single row
     */
    public function fetch($sql, $params = [], $types = '') {
        $result = $this->query($sql, $params, $types);
        return $result ? $result->fetch_assoc() : false;
    }
    
    /**
     * Get all rows
     */
    public function fetchAll($sql, $params = [], $types = '') {
        $result = $this->query($sql, $params, $types);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    }
    
    /**
     * Execute INSERT/UPDATE/DELETE
     */
    public function execute($sql, $params = [], $types = '') {
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            error_log("Execute prepare failed: " . $this->conn->error);
            return false;
        }
        
        if (!empty($params) && !empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            return false;
        }
        
        return true;
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId() {
        return $this->conn->insert_id;
    }
    
    /**
     * Get affected rows
     */
    public function affectedRows() {
        return $this->conn->affected_rows;
    }
    
    /**
     * Close connection
     */
    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}

// Create global database instance
$db = new Database();
