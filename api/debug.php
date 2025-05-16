<?php
// Включаем вывод ошибок
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Заголовок для вывода текста
header('Content-Type: text/html; charset=utf-8');

echo "<h1>API Debug</h1>";

// Проверяем наличие файлов
echo "<h2>Проверка файлов</h2>";
$files = [
    'config.php',
    'helpers/response.php',
    'helpers/auth.php',
    'controllers/auth_controller.php',
    'controllers/dashboard_controller.php'
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    echo "Файл $file: " . (file_exists($path) ? 'Существует' : 'Отсутствует') . "<br>";
}

// Проверяем функции
echo "<h2>Проверка функций</h2>";

// Проверяем config.php
echo "<h3>config.php</h3>";
try {
    require_once __DIR__ . '/config.php';
    echo "MASTER_PASSWORD: " . (defined('MASTER_PASSWORD') ? 'Определена' : 'Не определена') . "<br>";
    echo "JWT_SECRET: " . (defined('JWT_SECRET') ? 'Определена' : 'Не определена') . "<br>";
} catch (Exception $e) {
    echo "Ошибка при подключении config.php: " . $e->getMessage() . "<br>";
}

// Проверяем response.php
echo "<h3>response.php</h3>";
try {
    require_once __DIR__ . '/helpers/response.php';
    echo "sendResponse: " . (function_exists('sendResponse') ? 'Существует' : 'Отсутствует') . "<br>";
    echo "sendSuccess: " . (function_exists('sendSuccess') ? 'Существует' : 'Отсутствует') . "<br>";
    echo "sendError: " . (function_exists('sendError') ? 'Существует' : 'Отсутствует') . "<br>";
} catch (Exception $e) {
    echo "Ошибка при подключении response.php: " . $e->getMessage() . "<br>";
}

// Проверяем auth.php
echo "<h3>auth.php</h3>";
try {
    require_once __DIR__ . '/helpers/auth.php';
    echo "generateJWT: " . (function_exists('generateJWT') ? 'Существует' : 'Отсутствует') . "<br>";
    echo "verifyJWT: " . (function_exists('verifyJWT') ? 'Существует' : 'Отсутствует') . "<br>";
    echo "getAuthenticatedUser: " . (function_exists('getAuthenticatedUser') ? 'Существует' : 'Отсутствует') . "<br>";
    echo "requireAuth: " . (function_exists('requireAuth') ? 'Существует' : 'Отсутствует') . "<br>";
    echo "base64UrlEncode: " . (function_exists('base64UrlEncode') ? 'Существует' : 'Отсутствует') . "<br>";
    echo "base64UrlDecode: " . (function_exists('base64UrlDecode') ? 'Существует' : 'Отсутствует') . "<br>";
} catch (Exception $e) {
    echo "Ошибка при подключении auth.php: " . $e->getMessage() . "<br>";
}

// Проверяем auth_controller.php
echo "<h3>auth_controller.php</h3>";
try {
    require_once __DIR__ . '/controllers/auth_controller.php';
    echo "login: " . (function_exists('login') ? 'Существует' : 'Отсутствует') . "<br>";
    echo "verify: " . (function_exists('verify') ? 'Существует' : 'Отсутствует') . "<br>";
} catch (Exception $e) {
    echo "Ошибка при подключении auth_controller.php: " . $e->getMessage() . "<br>";
}

// Проверяем dashboard_controller.php
echo "<h3>dashboard_controller.php</h3>";
try {
    require_once __DIR__ . '/controllers/dashboard_controller.php';
    echo "getDashboardData: " . (function_exists('getDashboardData') ? 'Существует' : 'Отсутствует') . "<br>";
} catch (Exception $e) {
    echo "Ошибка при подключении dashboard_controller.php: " . $e->getMessage() . "<br>";
}

// Информация о PHP
echo "<h2>Информация о PHP</h2>";
echo "PHP версия: " . phpversion() . "<br>";
echo "Расширения: <br>";
$extensions = get_loaded_extensions();
echo "<ul>";
foreach ($extensions as $extension) {
    echo "<li>$extension</li>";
}
echo "</ul>";

// Информация о сервере
echo "<h2>Информация о сервере</h2>";
echo "Сервер: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "Путь к API: " . __DIR__ . "<br>";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";

// Проверка getallheaders
echo "<h2>Проверка getallheaders</h2>";
if (function_exists('getallheaders')) {
    echo "Функция getallheaders доступна<br>";
} else {
    echo "Функция getallheaders недоступна. Создаем полифилл.<br>";
    
    // Добавляем полифилл для getallheaders
    echo "Добавляем следующий код в helpers/auth.php:<br>";
    echo "<pre>
if (!function_exists('getallheaders')) {
    function getallheaders() {
        \$headers = [];
        foreach (\$_SERVER as \$name => \$value) {
            if (substr(\$name, 0, 5) == 'HTTP_') {
                \$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr(\$name, 5)))))] = \$value;
            }
        }
        return \$headers;
    }
}
</pre>";
} 