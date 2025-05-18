<?php
/**
 * API Entry Point
 */
date_default_timezone_set('Europe/Moscow');

// вывод ошибок (отключить в проде)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

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
require_once 'controllers/equipment_tools_controller.php';

$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

$base_path = '/api';
$path = str_replace($base_path, '', $path);
$path = trim($path, '/');

// роутинг запросов
try {
    switch ($path) {
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
            
        case 'dashboard':
            getDashboardData();
            break;
            
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
            
        case 'equipment-events':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                getEquipmentEvents();
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                createEquipmentEvent();
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
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
            
        case 'stop-reasons':
            getStopReasons();
            break;
            
        case 'start-reasons':
            getStartReasons();
            break;
            
        case 'equipment-stats':
            getEquipmentStats();
            break;
            
        case 'functions':
            getAllFunctions();
            break;
            
        case (preg_match('/^functions\/(\d+)\/coeff_sets$/', $path, $matches) ? true : false):
            $functionId = $matches[1];
            getFunctionCoeffSets($functionId);
            break;
            
        case (preg_match('/^functions\/(\d+)\/coeff_sets\/(\d+)\/coefficients$/', $path, $matches) ? true : false):
            $functionId = $matches[1];
            $setId = $matches[2];
            getCoefficients($functionId, $setId);
            break;
            
        case (preg_match('/^functions\/(\d+)\/coeff_sets\/(\d+)$/', $path, $matches) ? true : false):
            $functionId = $matches[1];
            $setId = $matches[2];
            if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                updateCoefficients($functionId, $setId);
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
        case (preg_match('/^functions\/(\d+)\/coeff_sets$/', $path, $matches) ? true : false):
            $functionId = $matches[1];
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                createCoeffSet($functionId);
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
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
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                getReplacementByDate();
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                saveReplacement();
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
        case (preg_match('/^meter-replacements\/(\d+)$/', $path, $matches) ? true : false):
            $replacementId = $matches[1];
            if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                updateReplacement($replacementId);
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
            
        case 'meter-replacements/cancel':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                cancelReplacement();
            } else {
                sendError('Метод не поддерживается', 405);
            }
            break;
        
        case (preg_match('/^equipment-tools\/status\/(\d+)$/', $path, $matches) ? true : false):
            $equipmentId = $matches[1];
            return getToolStatus($equipmentId);
            break;
        
        case 'equipment-tools/toggle':
            return toggleTool();
            break;
        
        default:
            sendError('Маршрут не найден', 404);
            break;
    }
} catch (Exception $e) {
    sendError('Ошибка сервера: ' . $e->getMessage(), 500);
} 