<?php
// Конфигурационный файл

// Функция для чтения .env файла
function loadEnv($path) {
    if (!file_exists($path)) {
        die(".env file not found");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0 || empty(trim($line))) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        // Обработка переменных в значениях (например, ${APP_URL})
        if (preg_match('/\${([^}]+)}/', $value, $matches)) {
            $envVar = $matches[1];
            if (isset($_ENV[$envVar])) {
                $value = str_replace('${' . $envVar . '}', $_ENV[$envVar], $value);
            }
        }

        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
}

// Загрузка .env файла (поддержка Docker)
$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) {
    $envPath = '/var/www/.env'; // Путь в Docker контейнере
}
loadEnv($envPath);

// Секретный ключ для JWT
define('JWT_SECRET', getenv('JWT_SECRET'));

// Срок действия токена (в секундах)
define('TOKEN_EXPIRY', getenv('JWT_EXPIRATION')); 

// Настройки базы данных
define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));

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