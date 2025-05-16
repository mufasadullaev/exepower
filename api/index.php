<?php
/**
 * API Entry Point
 */

// Устанавливаем часовой пояс для корректной работы с датами
date_default_timezone_set('Europe/Moscow');

// Включение вывода ошибок (отключить в продакшене)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// CORS заголовки
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Обработка OPTIONS запроса для CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Подключение необходимых файлов
require_once 'config.php';
require_once 'helpers/response.php';
require_once 'helpers/auth.php';
require_once 'helpers/db.php';
require_once 'controllers/auth_controller.php';
require_once 'controllers/dashboard_controller.php';
require_once 'controllers/equipment_controller.php';
require_once 'controllers/equipment_events_controller.php';
require_once 'controllers/equipment_stats_controller.php';
require_once 'controllers/functions_controller.php';
require_once 'controllers/counters_controller.php';

// Получение пути запроса
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Удаление базового пути API
$base_path = '/api';
$path = str_replace($base_path, '', $path);
$path = trim($path, '/');

// Маршрутизация запросов
try {
    switch ($path) {
        // Маршруты аутентификации
        case 'auth/login':
            login();
            break;
            
        case 'auth/verify':
            verify();
            break;
            
        case 'auth/register':
            register();
            break;
            
        case 'auth/users':
            getUsers();
            break;
            
        // Маршруты дашборда
        case 'dashboard':
            getDashboardData();
            break;
            
        // Маршруты для работы с параметрами
        case 'parameters':
            getParameters();
            break;
            
        case 'parameter-values':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                getParameterValues();
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                saveParameterValues();
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
        case 'equipment':
            getEquipment();
            break;
            
        // Маршруты для работы с событиями оборудования (пуски и остановы)
        case 'equipment-events':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                getEquipmentEvents();
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                createEquipmentEvent();
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
        // Обновление существующего события
        case (preg_match('/^equipment-events\/(\d+)$/', $path, $matches) ? true : false):
            $eventId = $matches[1];
            if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                updateEquipmentEvent($eventId);
            } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
                deleteEquipmentEvent($eventId);
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
        // Получение причин останова
        case 'stop-reasons':
            getStopReasons();
            break;
            
        // Получение статистики оборудования
        case 'equipment-stats':
            getEquipmentStats();
            break;
            
        // Маршруты для работы с функциями и коэффициентами
        case 'functions':
            getAllFunctions();
            break;
            
        // Получение наборов коэффициентов для функции
        case (preg_match('/^functions\/(\d+)\/coeff_sets$/', $path, $matches) ? true : false):
            $functionId = $matches[1];
            getFunctionCoeffSets($functionId);
            break;
            
        // Получение коэффициентов для набора
        case (preg_match('/^functions\/(\d+)\/coeff_sets\/(\d+)\/coefficients$/', $path, $matches) ? true : false):
            $functionId = $matches[1];
            $setId = $matches[2];
            getCoefficients($functionId, $setId);
            break;
            
        // Обновление коэффициентов набора
        case (preg_match('/^functions\/(\d+)\/coeff_sets\/(\d+)$/', $path, $matches) ? true : false):
            $functionId = $matches[1];
            $setId = $matches[2];
            if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                updateCoefficients($functionId, $setId);
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
        // Создание нового набора коэффициентов
        case (preg_match('/^functions\/(\d+)\/coeff_sets$/', $path, $matches) ? true : false):
            $functionId = $matches[1];
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                createCoeffSet($functionId);
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
        // Тестовый маршрут
        case 'test':
            sendSuccess(['message' => 'API работает!']);
            break;
            
        // Маршрут для отладки
        case 'debug':
            require_once 'debug.php';
            break;
            
        // Маршруты для работы со счетчиками
        case 'meter-types':
            getMeterTypes();
            break;

        case 'meters':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                getMeters();
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;

        case 'meter-readings':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                getReadings();
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                saveReadings();
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;

        case 'meter-replacements':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                saveReplacement();
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
        // Маршрут по умолчанию
        default:
            sendError('Маршрут не найден', 404);
            break;
    }
} catch (Exception $e) {
    sendError('Ошибка сервера: ' . $e->getMessage(), 500);
} 