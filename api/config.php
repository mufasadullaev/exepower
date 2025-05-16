<?php
// Конфигурационный файл

// Пароль для доступа к приложению
define('MASTER_PASSWORD', 'admin123');

// Секретный ключ для JWT
define('JWT_SECRET', 'your_jwt_secret_key_here');

// Срок действия токена (в секундах)
define('TOKEN_EXPIRY', 86400); // 24 часа

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'exepower');
define('DB_USER', 'root');
define('DB_PASS', '');

// Функция для подключения к базе данных
function getDbConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // В продакшене лучше логировать ошибку, а не выводить
        die("Database connection failed: " . $e->getMessage());
    }
} 