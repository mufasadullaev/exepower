<?php
/**
 * Equipment Statistics Controller
 * Handles operations related to equipment statistics (наработки)
 */

/**
 * Get equipment statistics
 * Retrieves statistics for specific equipment in a given date range
 */
function getEquipmentStats() {
    // Проверка аутентификации
    $user = requireAuth();
    
    // Получение параметров запроса
    $equipmentId = $_GET['equipment_id'] ?? null;
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    
    // Отладочная информация
    error_log("Stats Request: equipment_id=$equipmentId, start_date=$startDate, end_date=$endDate");
    
    if (!$equipmentId) {
        return sendError('ID оборудования не указан', 400);
    }
    
    // Проверка существования оборудования
    $equipment = fetchOne("SELECT * FROM equipment WHERE id = ?", [$equipmentId]);
    if (!$equipment) {
        return sendError('Оборудование не найдено', 404);
    }
    
    // Получение событий для указанного оборудования и периода
    $events = fetchAll("
        SELECT 
            ee.id,
            ee.equipment_id,
            e.name as equipment_name,
            ee.event_type,
            ee.event_time,
            ee.reason_id,
            sr.name as reason_name,
            ee.comment,
            ee.shift_id,
            s.name as shift_name,
            ee.user_id,
            u.username as user_name,
            ee.created_at
        FROM equipment_events ee
        JOIN equipment e ON ee.equipment_id = e.id
        LEFT JOIN stop_reasons sr ON ee.reason_id = sr.id
        LEFT JOIN shifts s ON ee.shift_id = s.id
        LEFT JOIN users u ON ee.user_id = u.id
        WHERE ee.equipment_id = ?
        AND DATE(ee.event_time) BETWEEN ? AND ?
        ORDER BY ee.event_time ASC
    ", [$equipmentId, $startDate, $endDate]);
    
    // Подсчет количества пусков и остановов
    $startCount = 0;
    $stopCount = 0;
    
    foreach ($events as $event) {
        if ($event['event_type'] === 'pusk') {
            $startCount++;
        } else if ($event['event_type'] === 'ostanov') {
            $stopCount++;
        }
    }
    
    return sendSuccess([
        'equipment' => $equipment,
        'events' => $events,
        'start_count' => $startCount,
        'stop_count' => $stopCount,
        'period' => [
            'start_date' => $startDate,
            'end_date' => $endDate
        ]
    ]);
} 