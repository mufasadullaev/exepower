<?php
/**
 * Equipment Events Controller
 * Handles operations related to equipment events (пуски и остановы)
 */

/**
 * Get equipment events
 * Retrieves events for specific equipment around a given date
 */
function getEquipmentEvents() {
    // Проверка аутентификации
    $user = requireAuth();
    
    // Получение параметров запроса
    $equipmentId = $_GET['equipment_id'] ?? null;
    $date = $_GET['date'] ?? date('Y-m-d');
    $month = $_GET['month'] ?? null;
    $limit = $_GET['limit'] ?? 20;  // Увеличиваем лимит до 20 событий
    
    // Отладочная информация
    error_log("API Request: equipment_id=$equipmentId, date=$date, month=$month, limit=$limit");
    
    if (!$equipmentId) {
        return sendError('ID оборудования не указан', 400);
    }
    
    // Проверка существования оборудования
    $equipment = fetchOne("SELECT * FROM equipment WHERE id = ?", [$equipmentId]);
    if (!$equipment) {
        return sendError('Оборудование не найдено', 404);
    }
    
    // Получаем последнее событие для определения текущего состояния
    $lastEvent = getLastEquipmentEvent($equipmentId);
    
    // SQL запрос и параметры для получения событий за период
    $sql = "
        SELECT 
            ee.id,
            ee.equipment_id,
            e.name as equipment_name,
            ee.event_type,
            ee.event_time,
            ee.reason_id,
            CASE 
                WHEN ee.event_type = 'ostanov' THEN sr.name
                WHEN ee.event_type = 'pusk' THEN str.name
                ELSE NULL
            END as reason_name,
            ee.comment,
            ee.shift_id,
            s.name as shift_name,
            ee.user_id,
            u.username as user_name,
            ee.created_at
        FROM equipment_events ee
        JOIN equipment e ON ee.equipment_id = e.id
        LEFT JOIN stop_reasons sr ON ee.event_type = 'ostanov' AND ee.reason_id = sr.id
        LEFT JOIN start_reasons str ON ee.event_type = 'pusk' AND ee.reason_id = str.id
        LEFT JOIN shifts s ON ee.shift_id = s.id
        LEFT JOIN users u ON ee.user_id = u.id
        WHERE ee.equipment_id = ?
    ";
    $params = [$equipmentId];
    
    // Фильтрация по месяцу или конкретной дате
    if ($month) {
        // Если передан параметр month в формате YYYY-MM
        $sql .= " AND DATE_FORMAT(ee.event_time, '%Y-%m') = ?";
        $params[] = $month;
        error_log("Filtering by month: $month");
    } else {
        // Фильтрация по конкретной дате (для обратной совместимости)
        $sql .= " AND DATE(ee.event_time) = ?";
        $params[] = $date;
        error_log("Filtering by date: $date");
    }
    
    $sql .= " ORDER BY ee.event_time DESC LIMIT ?";
    $params[] = $limit;
    
    error_log("SQL Query: $sql");
    error_log("SQL Params: " . json_encode($params));
    
    // Получение событий для указанного оборудования и периода
    $events = fetchAll($sql, $params);
    
    error_log("Found events: " . count($events));
    
    return sendSuccess([
        'equipment' => $equipment,
        'events' => $events,
        'date' => $month ?? $date,
        'last_event' => $lastEvent
    ]);
}

/**
 * Create a new equipment event
 */
function createEquipmentEvent() {
    try {
        error_log("DEBUG: Starting createEquipmentEvent");
        
        // Проверка аутентификации
        $user = requireAuth();
        error_log("DEBUG: Auth passed, user: " . json_encode($user));
        
        // Получение данных из запроса
        $rawInput = file_get_contents('php://input');
        error_log("DEBUG: Raw input: " . $rawInput);
        
        $requestData = json_decode($rawInput, true);
        error_log("DEBUG: Decoded request data: " . json_encode($requestData));
        
        // Проверка обязательных полей
        if (!isset($requestData['equipment_id']) || !isset($requestData['event_type']) || !isset($requestData['event_time'])) {
            error_log("DEBUG: Missing required fields");
            error_log("DEBUG: equipment_id present: " . isset($requestData['equipment_id']));
            error_log("DEBUG: event_type present: " . isset($requestData['event_type']));
            error_log("DEBUG: event_time present: " . isset($requestData['event_time']));
            return sendError('Не все обязательные поля заполнены', 400);
        }
        
        $equipmentId = $requestData['equipment_id'];
        $eventType = $requestData['event_type'];
        $eventTime = $requestData['event_time'];
        $reasonId = $requestData['reason_id'] ?? null;
        $comment = $requestData['comment'] ?? null;
        
        error_log("DEBUG: Parsed values - equipmentId: $equipmentId, eventType: $eventType, eventTime: $eventTime, reasonId: $reasonId");
        
        // Проверка корректности типа события
        if ($eventType !== 'pusk' && $eventType !== 'ostanov') {
            error_log("DEBUG: Invalid event type: $eventType");
            return sendError('Неверный тип события. Допустимые значения: pusk, ostanov', 400);
        }
        
        // Для останова требуется указать причину
        if ($eventType === 'ostanov' && !$reasonId) {
            error_log("DEBUG: Missing reason_id for ostanov event");
            return sendError('Для останова необходимо указать причину', 400);
        }
        
        // Проверка хронологии событий
        error_log("DEBUG: Validating event chronology");
        $validationResult = validateEventChronology($equipmentId, $eventType, $eventTime);
        error_log("DEBUG: Validation result: " . json_encode($validationResult));
        
        if (!$validationResult['valid']) {
            error_log("DEBUG: Chronology validation failed");
            return sendError($validationResult['message'], 400);
        }
        
        // Определение текущей смены
        $shiftId = getCurrentShift();
        error_log("DEBUG: Current shift ID: $shiftId");
        
        // Создание события
        error_log("DEBUG: Attempting to insert event into database");
        $eventId = insert('equipment_events', [
            'equipment_id' => $equipmentId,
            'event_type' => $eventType,
            'event_time' => $eventTime,
            'reason_id' => $reasonId,
            'comment' => $comment,
            'shift_id' => $shiftId,
            'user_id' => $user['id']
        ]);
        
        error_log("DEBUG: Event created with ID: $eventId");
        
        // Если это останов, то выключаем все инструменты
        if ($eventType === 'ostanov') {
            error_log("DEBUG: Turning off tools for equipment $equipmentId");
            turnOffAllTools($equipmentId, $shiftId, $user['id']);
        }
        
        // Получение созданного события
        error_log("DEBUG: Fetching created event details");
        $event = fetchOne("
            SELECT 
                ee.id,
                ee.equipment_id,
                e.name as equipment_name,
                ee.event_type,
                ee.event_time,
                ee.reason_id,
                CASE 
                    WHEN ee.event_type = 'ostanov' THEN sr.name
                    WHEN ee.event_type = 'pusk' THEN str.name
                    ELSE NULL
                END as reason_name,
                ee.comment,
                ee.shift_id,
                s.name as shift_name,
                ee.user_id,
                u.username as user_name,
                ee.created_at
            FROM equipment_events ee
            JOIN equipment e ON ee.equipment_id = e.id
            LEFT JOIN stop_reasons sr ON ee.event_type = 'ostanov' AND ee.reason_id = sr.id
            LEFT JOIN start_reasons str ON ee.event_type = 'pusk' AND ee.reason_id = str.id
            LEFT JOIN shifts s ON ee.shift_id = s.id
            LEFT JOIN users u ON ee.user_id = u.id
            WHERE ee.id = ?
        ", [$eventId]);
        
        error_log("DEBUG: Successfully retrieved event: " . json_encode($event));
        
        return sendSuccess([
            'message' => 'Событие успешно создано',
            'event' => $event
        ]);
    } catch (Exception $e) {
        error_log("ERROR in createEquipmentEvent: " . $e->getMessage());
        error_log("ERROR stack trace: " . $e->getTraceAsString());
        return sendError('Ошибка при создании события: ' . $e->getMessage(), 500);
    }
}

/**
 * Update an existing equipment event
 */
function updateEquipmentEvent($eventId) {
    // Проверка аутентификации
    $user = requireAuth();
    
    // Проверка существования события
    $event = fetchOne("SELECT * FROM equipment_events WHERE id = ?", [$eventId]);
    if (!$event) {
        return sendError('Событие не найдено', 404);
    }
    
    // Получение данных из запроса
    $requestData = json_decode(file_get_contents('php://input'), true);
    
    // Отладка входящих данных
    error_log("Updating event ID: $eventId with data: " . json_encode($requestData));
    
    // Проверка обязательных полей
    if (!isset($requestData['event_type']) || !isset($requestData['event_time'])) {
        return sendError('Не все обязательные поля заполнены', 400);
    }
    
    $eventType = $requestData['event_type'];
    $eventTime = $requestData['event_time'];
    $reasonId = $requestData['reason_id'] ?? null;
    $comment = $requestData['comment'] ?? null;
    
    error_log("Event time received for update: $eventTime");
    
    // Проверка корректности типа события
    if ($eventType !== 'pusk' && $eventType !== 'ostanov') {
        return sendError('Неверный тип события. Допустимые значения: pusk, ostanov', 400);
    }
    
    // Для останова требуется указать причину
    if ($eventType === 'ostanov' && !$reasonId) {
        return sendError('Для останова необходимо указать причину', 400);
    }
    
    // Проверка хронологии событий (исключая текущее событие)
    $validationResult = validateEventChronology($event['equipment_id'], $eventType, $eventTime, $eventId);
    if (!$validationResult['valid']) {
        return sendError($validationResult['message'], 400);
    }
    
    // Обновление события
    try {
        update('equipment_events', [
            'event_type' => $eventType,
            'event_time' => $eventTime,
            'reason_id' => $reasonId,
            'comment' => $comment
        ], 'id = ?', [$eventId]);
        
        error_log("Event updated with ID: $eventId and time: $eventTime");
        
        // Если изменили тип события на останов, то выключаем все инструменты
        if ($eventType === 'ostanov' && $event['event_type'] !== 'ostanov') {
            turnOffAllTools($event['equipment_id'], getCurrentShift(), $user['id']);
        }
        
        // Получение обновленного события
        $updatedEvent = fetchOne("
            SELECT 
                ee.id,
                ee.equipment_id,
                e.name as equipment_name,
                ee.event_type,
                ee.event_time,
                ee.reason_id,
                CASE 
                    WHEN ee.event_type = 'ostanov' THEN sr.name
                    WHEN ee.event_type = 'pusk' THEN str.name
                    ELSE NULL
                END as reason_name,
                ee.comment,
                ee.shift_id,
                s.name as shift_name,
                ee.user_id,
                u.username as user_name,
                ee.created_at
            FROM equipment_events ee
            JOIN equipment e ON ee.equipment_id = e.id
            LEFT JOIN stop_reasons sr ON ee.event_type = 'ostanov' AND ee.reason_id = sr.id
            LEFT JOIN start_reasons str ON ee.event_type = 'pusk' AND ee.reason_id = str.id
            LEFT JOIN shifts s ON ee.shift_id = s.id
            LEFT JOIN users u ON ee.user_id = u.id
            WHERE ee.id = ?
        ", [$eventId]);
        
        error_log("Updated event retrieved from DB: " . json_encode($updatedEvent));
        
        return sendSuccess([
            'message' => 'Событие успешно обновлено',
            'event' => $updatedEvent
        ]);
    } catch (Exception $e) {
        return sendError('Ошибка при обновлении события: ' . $e->getMessage(), 500);
    }
}

/**
 * Get stop reasons
 */
function getStopReasons() {
    // Проверка аутентификации
    $user = requireAuth();
    
    // Получение всех причин останова
    $reasons = fetchAll("SELECT * FROM stop_reasons ORDER BY name");
    
    return sendSuccess([
        'reasons' => $reasons
    ]);
}
function getStartReasons() {
    $user = requireAuth();
    
    try {
        $reasons = fetchAll("SELECT * FROM start_reasons ORDER BY id");
        
        return sendSuccess([
            'reasons' => $reasons
        ]);
    } catch (Exception $e) {
        return sendError('Ошибка при получении типов пусков: ' . $e->getMessage());
    }
} 
/**
 * Validate event chronology
 * Checks that the event time is valid in relation to other events
 */
function validateEventChronology($equipmentId, $eventType, $eventTime, $excludeEventId = null) {
    // Отладочная информация
    error_log("Validating chronology: equipment=$equipmentId, type=$eventType, time=$eventTime, exclude=$excludeEventId");
    
    // Получаем все события для оборудования, исключая редактируемое
    $query = "
        SELECT id, event_time, event_type
        FROM equipment_events 
        WHERE equipment_id = ?
    ";
    $params = [$equipmentId];
    
    if ($excludeEventId) {
        $query .= " AND id != ?";
        $params[] = $excludeEventId;
    }
    
    $query .= " ORDER BY event_time ASC";
    $events = fetchAll($query, $params);
    
    error_log("Existing events: " . json_encode($events));
    
    // Создаем виртуальное событие для вставки
    $virtualEvent = [
        'id' => $excludeEventId ?? 'new',
        'event_time' => $eventTime,
        'event_type' => $eventType
    ];
    
    // Находим позицию для вставки виртуального события
    $insertIndex = 0;
    foreach ($events as $index => $event) {
        if (new DateTime($event['event_time']) > new DateTime($eventTime)) {
            break;
        }
        $insertIndex = $index + 1;
    }
    
    // Вставляем виртуальное событие в массив
    array_splice($events, $insertIndex, 0, [$virtualEvent]);
    
    error_log("Events with virtual event: " . json_encode($events));
    
    // Проверяем всю последовательность событий
    for ($i = 0; $i < count($events) - 1; $i++) {
        $currentEvent = $events[$i];
        $nextEvent = $events[$i + 1];
        
        // Проверяем время
        if (new DateTime($currentEvent['event_time']) > new DateTime($nextEvent['event_time'])) {
            error_log("Rejecting: events are not in chronological order");
            return [
                'valid' => false,
                'message' => 'События должны быть в хронологическом порядке.'
            ];
        }
        
        // Проверяем тип
        if ($currentEvent['event_type'] === $nextEvent['event_type']) {
            error_log("Rejecting: two consecutive events of the same type");
            return [
                'valid' => false,
                'message' => 'Нарушена хронология событий! Исправьте дату и время.'
            ];
        }
        
        // Проверяем последовательность пуск-останов
        if ($currentEvent['event_type'] === 'pusk' && $nextEvent['event_type'] === 'pusk') {
            error_log("Rejecting: start event followed by start event");
            return [
                'valid' => false,
                'message' => 'После пуска должен следовать останов.'
            ];
        }
        
        if ($currentEvent['event_type'] === 'ostanov' && $nextEvent['event_type'] === 'ostanov') {
            error_log("Rejecting: stop event followed by stop event");
            return [
                'valid' => false,
                'message' => 'После останова должен следовать пуск.'
            ];
        }
    }
    
    error_log("Event sequence is valid");
    return ['valid' => true];
}

/**
 * Delete an equipment event
 */
function deleteEquipmentEvent($eventId) {
    // Проверка аутентификации
    $user = requireAuth();
    
    // Проверка существования события
    $event = fetchOne("SELECT * FROM equipment_events WHERE id = ?", [$eventId]);
    if (!$event) {
        return sendError('Событие не найдено', 404);
    }
    
    // Удаление события
    try {
        delete('equipment_events', 'id = ?', [$eventId]);
        
        return sendSuccess([
            'message' => 'Событие успешно удалено',
            'event_id' => $eventId
        ]);
    } catch (Exception $e) {
        return sendError('Ошибка при удалении события: ' . $e->getMessage(), 500);
    }
}

/**
 * Get current shift
 * Determines the current shift based on time
 */
function getCurrentShift() {
    // Получение текущего времени
    $currentHour = (int)date('H');
    
    // Определение смены по времени (примерная логика)
    if ($currentHour >= 0 && $currentHour < 8) {
        return 1; // Первая смена (ночная)
    } elseif ($currentHour >= 8 && $currentHour < 16) {
        return 2; // Вторая смена (дневная)
    } else {
        return 3; // Третья смена (вечерняя)
    }
}

/**
 * Turn off all tools for the equipment
 */
function turnOffAllTools($equipmentId, $shiftId, $userId) {
    try {
        // Получаем список активных инструментов для оборудования
        $activeTools = fetchAll("
            SELECT t1.tool_type
            FROM equipment_tool_events t1
            INNER JOIN (
                SELECT tool_type, MAX(event_time) as max_time
                FROM equipment_tool_events
                WHERE equipment_id = ?
                GROUP BY tool_type
            ) t2 ON t1.tool_type = t2.tool_type AND t1.event_time = t2.max_time
            WHERE t1.equipment_id = ? AND t1.event_type = 'on'
        ", [$equipmentId, $equipmentId]);
        
        $currentTime = date('Y-m-d H:i:s');
        
        // Выключаем каждый активный инструмент
        foreach ($activeTools as $tool) {
            insert('equipment_tool_events', [
                'equipment_id' => $equipmentId,
                'tool_type' => $tool['tool_type'],
                'event_type' => 'off',
                'event_time' => $currentTime,
                'shift_id' => $shiftId,
                'user_id' => $userId
            ]);
            
            error_log("Tool {$tool['tool_type']} turned off for equipment $equipmentId");
        }
        
        return count($activeTools) > 0;
    } catch (Exception $e) {
        error_log("Error turning off tools: " . $e->getMessage());
        return false;
    }
}

/**
 * Get last equipment event
 * Retrieves the most recent event for specific equipment
 */
function getLastEquipmentEvent($equipmentId) {
    $sql = "
        SELECT 
            ee.id,
            ee.equipment_id,
            e.name as equipment_name,
            ee.event_type,
            ee.event_time,
            ee.reason_id,
            CASE 
                WHEN ee.event_type = 'ostanov' THEN sr.name
                WHEN ee.event_type = 'pusk' THEN str.name
                ELSE NULL
            END as reason_name,
            ee.comment,
            ee.shift_id,
            s.name as shift_name,
            ee.user_id,
            u.username as user_name,
            ee.created_at
        FROM equipment_events ee
        JOIN equipment e ON ee.equipment_id = e.id
        LEFT JOIN stop_reasons sr ON ee.event_type = 'ostanov' AND ee.reason_id = sr.id
        LEFT JOIN start_reasons str ON ee.event_type = 'pusk' AND ee.reason_id = str.id
        LEFT JOIN shifts s ON ee.shift_id = s.id
        LEFT JOIN users u ON ee.user_id = u.id
        WHERE ee.equipment_id = ?
        ORDER BY ee.event_time DESC
        LIMIT 1
    ";
    
    return fetchOne($sql, [$equipmentId]);
} 