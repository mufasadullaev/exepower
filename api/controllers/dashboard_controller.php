<?php
/**
 * Контроллер для работы с данными дашборда
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/db.php';

/**
 * Получение данных для дашборда
 */
function getDashboardData()
{
    $user = requireAuth();

    try {
        // 1. Состояние оборудования (основной статус)
        $equipmentStatus = fetchAll("
    SELECT 
        eq.id,
        eq.name,
        eq.type_name,
        CASE 
            WHEN le.event_type = 'pusk' THEN CONCAT('Запущен (', COALESCE(str.name, 'Не указана'), ')')
            WHEN le.event_type = 'ostanov' THEN CONCAT('Остановлен (', COALESCE(sr.name, 'Не указана'), ')')
            ELSE 'Нет данных'
        END as status
    FROM (
        SELECT 
            e.id,
            CASE 
                WHEN e.name LIKE 'ГТ%' THEN CONCAT('ПГУ', SUBSTRING(e.name, 3))
                ELSE e.name 
            END as name,
            et.name as type_name
        FROM equipment e
        JOIN equipment_types et ON e.type_id = et.id
        WHERE e.name NOT LIKE 'ПТ%'
    ) eq
    LEFT JOIN (
        SELECT *
        FROM (
            SELECT 
                ee.*,
                ROW_NUMBER() OVER (PARTITION BY ee.equipment_id ORDER BY ee.event_time DESC, ee.id DESC) as rn
            FROM equipment_events ee
        ) t
        WHERE t.rn = 1
    ) le ON eq.id = le.equipment_id
    LEFT JOIN stop_reasons sr ON le.reason_id = sr.id
    LEFT JOIN start_reasons str ON le.reason_id = str.id
    ORDER BY 
        CASE 
            WHEN eq.name LIKE 'Блок%' THEN 1
            WHEN eq.name LIKE 'ПГУ%' THEN 2
            ELSE 3
        END,
        eq.name
");

        // 2. Получаем последние события по инструментам (испаритель, АОС)
        $equipmentToolStatus = fetchAll("
            SELECT t.equipment_id, t.tool_type, t.event_type
            FROM (
                SELECT 
                    ete.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY ete.equipment_id, ete.tool_type 
                        ORDER BY ete.event_time DESC, ete.id DESC
                    ) as rn
                FROM equipment_tool_events ete
            ) t
            WHERE t.rn = 1
        ");
        $toolStatusMap = [];
        foreach ($equipmentToolStatus as $row) {
            $toolStatusMap[$row['equipment_id']][$row['tool_type']] = $row['event_type'];
        }

        // 3. Собираем итоговый массив для фронта
        $dashboardRows = [];
        foreach ($equipmentStatus as $eq) {
            $evaporator = isset($toolStatusMap[$eq['id']]['evaporator'])
                ? ($toolStatusMap[$eq['id']]['evaporator'] === 'on' ? 'Включен' : 'Выключен')
                : 'Нет данных';
            $aos = isset($toolStatusMap[$eq['id']]['aos'])
                ? ($toolStatusMap[$eq['id']]['aos'] === 'on' ? 'Включен' : 'Выключен')
                : 'Нет данных';

            $dashboardRows[] = [
                'name' => $eq['name'],
                'type_name' => $eq['type_name'],
                'status' => $eq['status'],
                'evaporator' => $evaporator,
                'aos' => $aos
            ];
        }

        // 4. Работающие вахты
        $currentDate = new DateTime();
        $referenceDate = new DateTime('2025-04-01'); // Опорная дата - 1 апреля 2025
        $daysDiff = $currentDate->diff($referenceDate)->days;
        $patterns = [
            ['3', '3', 'B', '1', '1', '2', '2', 'B'], // Вахта 1
            ['1', '2', '2', 'B', '3', '3', 'B', '1'], // Вахта 2
            ['2', 'B', '3', '3', 'B', '1', '1', '2'], // Вахта 3
            ['B', '1', '1', '2', '2', 'B', '3', '3']  // Вахта 4
        ];
        $patternIndex = ($daysDiff % 8);
        $activeShifts = [];
        foreach ($shifts = fetchAll("SELECT id, name FROM shifts ORDER BY id") as $shift) {
            $shiftNumber = $shift['id'];
            $activeVahta = null;
            for ($vahtaNumber = 0; $vahtaNumber < 4; $vahtaNumber++) {
                $shiftValue = $patterns[$vahtaNumber][$patternIndex];
                if ($shiftValue == $shiftNumber) {
                    $activeVahta = $vahtaNumber + 1;
                    break;
                }
            }
            $activeShifts[] = [
                'name' => $shift['name'],
                'vahta' => $activeVahta ? "Вахта №{$activeVahta}" : 'Нет активной вахты'
            ];
        }

        // 5. Статистика по станции
        $powerStats = fetchOne("
            SELECT 
                COALESCE(SUM(CASE WHEN m.meter_type_id = 1 THEN mr.total ELSE 0 END), 0) as generation,
                COALESCE(SUM(CASE WHEN m.meter_type_id = 2 THEN mr.total ELSE 0 END), 0) as consumption
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE mr.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                AND mr.date <= CURDATE()
        ");

        // 6. Время работы
        $workingHours = fetchAll("
            SELECT 
                CASE 
                    WHEN e.name LIKE 'ГТ%' THEN CONCAT('ПГУ', SUBSTRING(e.name, 3))
                    ELSE e.name 
                END as name,
                et.name as type_name,
                COUNT(DISTINCT DATE(ee_start.event_time)) as working_days,
                COALESCE(
                    SUM(
                        TIMESTAMPDIFF(
                            HOUR,
                            ee_start.event_time,
                            COALESCE(ee_stop.event_time, NOW())
                        )
                    ),
                    0
                ) as working_hours
            FROM equipment e
            JOIN equipment_types et ON e.type_id = et.id
            LEFT JOIN equipment_events ee_start ON e.id = ee_start.equipment_id 
                AND ee_start.event_type = 'pusk'
                AND ee_start.event_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            LEFT JOIN equipment_events ee_stop ON e.id = ee_stop.equipment_id 
                AND ee_stop.event_type = 'ostanov'
                AND ee_stop.event_time > ee_start.event_time
                AND ee_stop.event_time = (
                    SELECT MIN(event_time)
                    FROM equipment_events ee3
                    WHERE ee3.equipment_id = ee_start.equipment_id
                        AND ee3.event_type = 'ostanov'
                        AND ee3.event_time > ee_start.event_time
                )
            WHERE e.name NOT LIKE 'ПТ%'
            GROUP BY e.id, e.name, et.name
            ORDER BY 
                CASE 
                    WHEN e.name LIKE 'Блок%' THEN 1
                    WHEN e.name LIKE 'ГТ%' THEN 2
                    ELSE 3
                END,
                e.name
        ");

        return sendSuccess([
            'dashboardRows' => $dashboardRows,
            'activeShifts' => $activeShifts,
            'powerStats' => $powerStats,
            'workingHours' => $workingHours
        ]);

    } catch (Exception $e) {
        return sendError('Ошибка при получении данных: ' . $e->getMessage());
    }
}

/**
 * Получение списка параметров для типа оборудования
 */
function getParameters()
{
    // Проверка аутентификации
    $user = requireAuth();

    // Получение ID типа оборудования из запроса
    $equipmentTypeId = $_GET['equipment_type_id'] ?? null;

    if (!$equipmentTypeId) {
        return sendError('ID типа оборудования не указан', 400);
    }

    // Получение параметров для указанного типа оборудования
    $parameters = fetchAll(
        "SELECT id, name, description, unit FROM parameters WHERE equipment_type_id = ? ORDER BY name",
        [$equipmentTypeId]
    );

    return sendSuccess([
        'parameters' => $parameters
    ]);
}

/**
 * Сохранение значений параметров
 */
function saveParameterValues()
{
    // Проверка аутентификации
    $user = requireAuth();

    // Получение данных из запроса
    $requestData = json_decode(file_get_contents('php://input'), true);

    // Проверка обязательных полей
    if (
        !isset($requestData['equipmentId']) || !isset($requestData['date']) ||
        !isset($requestData['shiftId']) || !isset($requestData['values'])
    ) {
        return sendError('Не все обязательные поля заполнены', 400);
    }

    $equipmentId = $requestData['equipmentId'];
    $date = $requestData['date'];
    $shiftId = $requestData['shiftId'];
    $values = $requestData['values'];

    // Проверка формата даты
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return sendError('Неверный формат даты. Используйте YYYY-MM-DD', 400);
    }

    // Начало транзакции
    $db = getDbConnection();
    $db->beginTransaction();

    try {
        // Сохранение каждого значения
        $savedCount = 0;
        foreach ($values as $value) {
            if (!isset($value['parameterId']) || !isset($value['value'])) {
                continue;
            }

            $parameterId = $value['parameterId'];
            $paramValue = $value['value'];

            // Проверка существования записи
            $existingValue = fetchOne(
                "SELECT id FROM parameter_values 
                WHERE parameter_id = ? AND equipment_id = ? AND date = ? AND shift_id = ?",
                [$parameterId, $equipmentId, $date, $shiftId]
            );

            if ($existingValue) {
                // Обновление существующего значения
                update(
                    'parameter_values',
                    ['value' => $paramValue],
                    'id = ?',
                    [$existingValue['id']]
                );
            } else {
                // Создание нового значения
                insert('parameter_values', [
                    'parameter_id' => $parameterId,
                    'equipment_id' => $equipmentId,
                    'value' => $paramValue,
                    'date' => $date,
                    'shift_id' => $shiftId,
                    'user_id' => $user['id']
                ]);
            }

            $savedCount++;
        }

        // Подтверждение транзакции
        $db->commit();

        return sendSuccess([
            'message' => "Сохранено $savedCount значений параметров",
            'savedCount' => $savedCount
        ]);
    } catch (Exception $e) {
        // Откат транзакции в случае ошибки
        $db->rollBack();
        return sendError('Ошибка при сохранении значений: ' . $e->getMessage(), 500);
    }
}

/**
 * Получение значений параметров для оборудования за указанную дату и смену
 */
function getParameterValues()
{
    // Проверка аутентификации
    $user = requireAuth();

    // Получение параметров запроса
    $equipmentId = $_GET['equipment_id'] ?? null;
    $date = $_GET['date'] ?? date('Y-m-d');
    $shiftId = $_GET['shift_id'] ?? null;

    if (!$equipmentId) {
        return sendError('ID оборудования не указан', 400);
    }

    // Формирование условий запроса
    $conditions = ['pv.equipment_id = ?'];
    $params = [$equipmentId];

    if ($date) {
        $conditions[] = 'pv.date = ?';
        $params[] = $date;
    }

    if ($shiftId) {
        $conditions[] = 'pv.shift_id = ?';
        $params[] = $shiftId;
    }

    $whereClause = implode(' AND ', $conditions);

    // Получение значений параметров
    $values = fetchAll("
        SELECT 
            pv.id,
            pv.parameter_id,
            p.name as parameter_name,
            p.unit,
            pv.value,
            pv.date,
            s.name as shift_name,
            u.username as user_name,
            pv.created_at
        FROM parameter_values pv
        JOIN parameters p ON pv.parameter_id = p.id
        JOIN shifts s ON pv.shift_id = s.id
        JOIN users u ON pv.user_id = u.id
        WHERE $whereClause
        ORDER BY p.name
    ", $params);

    // Получение информации об оборудовании
    $equipment = fetchOne(
        "SELECT e.*, et.name as type_name 
        FROM equipment e 
        JOIN equipment_types et ON e.type_id = et.id 
        WHERE e.id = ?",
        [$equipmentId]
    );

    // Получение всех параметров для этого типа оборудования
    $parameters = [];
    if ($equipment) {
        $parameters = fetchAll(
            "SELECT id, name, description, unit 
            FROM parameters 
            WHERE equipment_type_id = ? 
            ORDER BY name",
            [$equipment['type_id']]
        );
    }

    return sendSuccess([
        'equipment' => $equipment,
        'parameters' => $parameters,
        'values' => $values,
        'date' => $date,
        'shiftId' => $shiftId
    ]);
}