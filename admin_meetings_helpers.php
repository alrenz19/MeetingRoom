<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// --- Standalone DB connection ---
function create_db_connection() {
    $db_config = [
        'host' => '172.16.81.215',       // Change as needed
        'dbname' => 'mrbs',              // Your MRBS database name
        'username' => 'mrbsNuser',       // Your DB username
        'password' => 'MrbsPassword123!' // Your DB password
    ];

    try {
        $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset=utf8mb4";
        $pdo = new \PDO($dsn, $db_config['username'], $db_config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false
        ]);
        return $pdo;
    } catch (\PDOException $e) {
        return null;
    }
}

// Helper functions
function db_query_one($sql, $params = []) {
    $pdo = create_db_connection();
    if (!$pdo) return 0;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : 0;
    } catch (\PDOException $e) {
        return 0;
    }
}

function db_query_all($sql, $params = []) {
    $pdo = create_db_connection();
    if (!$pdo) return [];

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(); // returns array of rows
    } catch (\PDOException $e) {
        return [];
    }
}