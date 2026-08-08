<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../../config/database.php';
            try {
                self::$instance = new PDO(
                    "mysql:host={$config['host']};dbname={$config['db_name']};charset=utf8mb4",
                    $config['username'],
                    $config['password'],
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
                exit();
            }
        }
        return self::$instance;
    }
}
