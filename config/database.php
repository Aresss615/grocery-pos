<?php
/**
 * J&J Grocery POS - Database Connection Handler
 * Manages MySQL connections with error handling
 */

require_once __DIR__ . '/constants.php';

class Database {
    public $connection;
    private $last_error;

    public function __construct() {
        $this->connect();
    }

    /**
     * Establish database connection
     */
    private function connect() {
        $this->connection = new mysqli(
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME,
            DB_PORT
        );

        if ($this->connection->connect_error) {
            $this->last_error = $this->connection->connect_error;
            $this->displayConnectionError();
            exit();
        }

        // Set charset to UTF-8
        $this->connection->set_charset("utf8mb4");
    }

    /**
     * Display database connection error page
     */
    private function displayConnectionError() {
        ?>
        <!DOCTYPE html>
        <html lang="fil">
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
                    overflow-y: auto;
                    max-height: 150px;
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
                code {
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
                <h1>Database Connection Error</h1>
                <p>Unable to connect to the database. Please check your configuration.</p>
                
                <div class="error-details">
                    <strong>Error:</strong><br><?php echo htmlspecialchars($this->last_error); ?>
                </div>
                
                <div class="steps">
                    <h3>✓ Steps to Fix:</h3>
                    <ol>
                        <li>Ensure XAMPP is running (Apache & MySQL started)</li>
                        <li>Create database: <code><?php echo DB_NAME; ?></code> in phpMyAdmin</li>
                        <li>Import <code>database/database.sql</code> file</li>
                        <li>Verify credentials in <code>config/constants.php</code></li>
                        <li>Refresh this page after setup</li>
                    </ol>
                </div>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * Execute query and return result
     */
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);

        if (!$stmt) {
            $this->last_error = $this->connection->error;
            return false;
        }

        if (!empty($params)) {
            $types = '';
            foreach ($params as $param) {
                if (is_int($param)) $types .= 'i';
                elseif (is_float($param)) $types .= 'd';
                elseif (is_string($param)) $types .= 's';
                else $types .= 's';
            }
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            $this->last_error = $stmt->error;
            return false;
        }

        return $stmt->get_result();
    }

    /**
     * Fetch single row
     */
    public function fetchOne($sql, $params = []) {
        $result = $this->query($sql, $params);
        return $result ? $result->fetch_assoc() : null;
    }

    /**
     * Fetch all rows
     */
    public function fetchAll($sql, $params = []) {
        $result = $this->query($sql, $params);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }

    /**
     * Execute INSERT/UPDATE/DELETE
     */
    public function execute($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);

        if (!$stmt) {
            $this->last_error = $this->connection->error;
            return false;
        }

        if (!empty($params)) {
            $types = '';
            foreach ($params as $param) {
                if (is_int($param)) $types .= 'i';
                elseif (is_float($param)) $types .= 'd';
                elseif (is_string($param)) $types .= 's';
                else $types .= 's';
            }
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            $this->last_error = $stmt->error;
            return false;
        }

        return true;
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId() {
        return $this->connection->insert_id;
    }

    /**
     * Get affected rows
     */
    public function affectedRows() {
        return $this->connection->affected_rows;
    }

    /**
     * Get last error
     */
    public function getError() {
        return $this->last_error;
    }

    /**
     * Close connection
     */
    public function close() {
        if ($this->connection) {
            $this->connection->close();
        }
    }

    /**
     * Destructor
     */
    public function __destruct() {
        $this->close();
    }
}

// Create global database instance
$db = new Database();

?>
