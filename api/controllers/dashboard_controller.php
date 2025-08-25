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
        "SELECT id, name, description, unit FROM parameters WHERE equipment_type_id = ? ORDER BY id",
        [$equipmentTypeId]
    );

    return sendSuccess([
        'parameters' => $parameters
    ]);
}

/**
 * Получение значения cell на основе parameter_id и equipment_id
 */
function getCellValue($parameterId, $equipmentId) {
    $mapping = [
        // Отпуск тепла на теплоцентраль (parameter_id: 11)
        11 => [
            4 => 'E6',  // ПТ 1
            6 => 'H6'   // ПТ 2
        ],
        // Отпуск тепла на блоки (parameter_id: 12)
        12 => [
            4 => 'E7',  // ПТ 1
            6 => 'H7'   // ПТ 2
        ],
        // Барометрическое давление (parameter_id: 13)
        13 => [
            3 => 'D14', // ГТ 1
            5 => 'G14'  // ГТ 2
        ],
        // Относительная влажность атмосферного воздуха (parameter_id: 14)
        14 => [
            3 => 'D15', // ГТ 1
            5 => 'G15'  // ГТ 2
        ],
        // Относительная влажность на входе компрессора (parameter_id: 15)
        15 => [
            3 => 'D16', // ГТ 1
            5 => 'G16'  // ГТ 2
        ],
        // cosφГТ (parameter_id: 16)
        16 => [
            3 => 'D17', // ГТ 1
            5 => 'G17'  // ГТ 2
        ],
        // Частота ГТ (parameter_id: 17)
        17 => [
            3 => 'D18', // ГТ 1
            5 => 'G18'  // ГТ 2
        ],
        // cosφПТ (parameter_id: 18)
        18 => [
            4 => 'E19', // ПТ 1
            6 => 'H19'  // ПТ 2
        ],
        // Температура влажного термометра на входе в градирни (parameter_id: 19)
        19 => [
            4 => 'E20', // ПТ 1
            6 => 'H20'  // ПТ 2
        ],
        // Температура наружного воздуха (parameter_id: 20)
        20 => [
            3 => 'D21', // ГТ 1
            5 => 'G21'  // ГТ 2
        ],
        // Температура воздуха на входе в компрессор (parameter_id: 21)
        21 => [
            3 => 'D22', // ГТ 1
            5 => 'G22'  // ГТ 2
        ],
        // Давление природного газа на входе в ГДКС (parameter_id: 22)
        22 => [
            3 => 'D25', // ГТ 1
            5 => 'G25'  // ГТ 2
        ],
        // Температура природного газа на входе в ГДКС (parameter_id: 23)
        23 => [
            3 => 'D26', // ГТ 1
            5 => 'G26'  // ГТ 2
        ],
        // Расход топлива на ГТУ (parameter_id: 24)
        24 => [
            3 => 'D27', // ГТ 1
            5 => 'G27'  // ГТ 2
        ],
        // Плотность природного газа (parameter_id: 25)
        25 => [
            3 => 'D28', // ГТ 1
            5 => 'G28'  // ГТ 2
        ],
        // Соотношение Н/С (parameter_id: 26)
        26 => [
            3 => 'D29', // ГТ 1
            5 => 'G29'  // ГТ 2
        ],
        // Низшая теплота сгорания топлива на ГТУ (parameter_id: 27)
        27 => [
            3 => 'D30', // ГТ 1
            5 => 'G30'  // ГТ 2
        ],
        // Сопротивление КВОУ (parameter_id: 28)
        28 => [
            3 => 'D31', // ГТ 1
            5 => 'G31'  // ГТ 2
        ]
    ];

    $result = $mapping[$parameterId][$equipmentId] ?? null;
    return $result;
}

/**
 * Копирование значений из parameter_values в pgu_fullparam_values
 */
function copyToPguFullParams($equipmentId, $date, $shiftId, $userId) {
    // Определяем pgu_id на основе equipment_id
    $pguMapping = [
        3 => 1, // ГТ 1 -> ПГУ 1
        4 => 1, // ПТ 1 -> ПГУ 1  
        5 => 2, // ГТ 2 -> ПГУ 2
        6 => 2  // ПТ 2 -> ПГУ 2
    ];
    
    $pguId = $pguMapping[$equipmentId] ?? null;
    if (!$pguId) {
        return; // Не ПГУ оборудование
    }
    
    // Определяем букву для cell
    $cellLetter = $pguId == 1 ? 'F' : 'G';
    
    // Маппинг parameter_values cell -> pgu_fullparam_values cell
    $cellMapping = [
        // ПГУ 1 (F)
        'E6' => ['row_num' => 14, 'cell' => 'F14'],   // Отпуск тепла на теплоцентраль
        'D14' => ['row_num' => 23, 'cell' => 'F23'],  // Барометрическое давление
        'D15' => ['row_num' => 24, 'cell' => 'F24'],  // Относительная влажность атмосферного воздуха
        'D16' => ['row_num' => 25, 'cell' => 'F25'],  // Относительная влажность на входе компрессора
        'D21' => ['row_num' => 20, 'cell' => 'F20'],  // Температура наружного воздуха
        'D22' => ['row_num' => 21, 'cell' => 'F21'],  // Температура воздуха на входе в компрессор
        'E20' => ['row_num' => 26, 'cell' => 'F26'],  // Температура влажного термометра на входе в градирни
        'D17' => ['row_num' => 27, 'cell' => 'F27'],  // cosφГТ
        'D18' => ['row_num' => 28, 'cell' => 'F28'],  // Частота ГТ
        'E19' => ['row_num' => 29, 'cell' => 'F29'],  // cosφПТ
        'D30' => ['row_num' => 30, 'cell' => 'F30'],  // Низшая теплота сгорания топлива на ГТУ
        'D25' => ['row_num' => 32, 'cell' => 'F32'],  // Давление природного газа на входе в ГДКС
        'D26' => ['row_num' => 33, 'cell' => 'F33'],  // Температура природного газа на входе в ГДКС
        'D31' => ['row_num' => 35, 'cell' => 'F35'],  // Сопротивление КВОУ
        'D29' => ['row_num' => 37, 'cell' => 'F37'],  // Соотношение Н/С
        'D27' => ['row_num' => 41, 'cell' => 'F41'],  // Расход топлива на ГТУ
        'D28' => ['row_num' => 43, 'cell' => 'F43'],  // Плотность природного газа
        
        // ПГУ 2 (G)
        'H6' => ['row_num' => 14, 'cell' => 'G14'],   // Отпуск тепла на теплоцентраль
        'G14' => ['row_num' => 23, 'cell' => 'G23'],  // Барометрическое давление
        'G15' => ['row_num' => 24, 'cell' => 'G24'],  // Относительная влажность атмосферного воздуха
        'G16' => ['row_num' => 25, 'cell' => 'G25'],  // Относительная влажность на входе компрессора
        'G21' => ['row_num' => 20, 'cell' => 'G20'],  // Температура наружного воздуха
        'G22' => ['row_num' => 21, 'cell' => 'G21'],  // Температура воздуха на входе в компрессор
        'H20' => ['row_num' => 26, 'cell' => 'G26'],  // Температура влажного термометра на входе в градирни
        'G17' => ['row_num' => 27, 'cell' => 'G27'],  // cosφГТ
        'G18' => ['row_num' => 28, 'cell' => 'G28'],  // Частота ГТ
        'H19' => ['row_num' => 29, 'cell' => 'G29'],  // cosφПТ
        'G30' => ['row_num' => 30, 'cell' => 'G30'],  // Низшая теплота сгорания топлива на ГТУ
        'G25' => ['row_num' => 32, 'cell' => 'G32'],  // Давление природного газа на входе в ГДКС
        'G26' => ['row_num' => 33, 'cell' => 'G33'],  // Температура природного газа на входе в ГДКС
        'G31' => ['row_num' => 35, 'cell' => 'G35'],  // Сопротивление КВОУ
        'G29' => ['row_num' => 37, 'cell' => 'G37'],  // Соотношение Н/С
        'G27' => ['row_num' => 41, 'cell' => 'G41'],  // Расход топлива на ГТУ
        'G28' => ['row_num' => 43, 'cell' => 'G43']   // Плотность природного газа
    ];
    
    // Получаем значения parameter_values только для данного оборудования
    $paramValues = fetchAll(
        "SELECT pv.*, p.name as param_name 
         FROM parameter_values pv 
         JOIN parameters p ON pv.parameter_id = p.id 
         WHERE pv.equipment_id = ? AND pv.date = ? AND pv.shift_id = ? AND pv.cell IS NOT NULL",
        [$equipmentId, $date, $shiftId]
    );
    
    foreach ($paramValues as $paramValue) {
        $sourceCell = $paramValue['cell'];
        $mapping = $cellMapping[$sourceCell] ?? null;
        
        if (!$mapping) {
            continue; // Нет маппинга для этой ячейки
        }
        
        // Проверяем, существует ли уже запись
        $existingValue = fetchOne(
            "SELECT id FROM pgu_fullparam_values 
             WHERE fullparam_id = (SELECT id FROM pgu_fullparams WHERE row_num = ?) 
             AND pgu_id = ? AND date = ? AND shift_id = ?",
            [$mapping['row_num'], $pguId, $date, $shiftId]
        );
        
        if ($existingValue) {
            // Обновляем существующую запись
            update(
                'pgu_fullparam_values',
                ['value' => $paramValue['value'], 'cell' => $mapping['cell']],
                'id = ?',
                [$existingValue['id']]
            );
        } else {
            // Создаем новую запись
            insert('pgu_fullparam_values', [
                'fullparam_id' => fetchOne("SELECT id FROM pgu_fullparams WHERE row_num = ?", [$mapping['row_num']])['id'],
                'pgu_id' => $pguId,
                'date' => $date,
                'shift_id' => $shiftId,
                'value' => $paramValue['value'],
                'cell' => $mapping['cell']
            ]);
        }
    }
    
    // Расчетные значения F15/G15 и F36/G36
    calculateDerivedValues($pguId, $date, $shiftId, $cellLetter);
}

/**
 * Расчет производных значений
 */
function calculateDerivedValues($pguId, $date, $shiftId, $cellLetter) {
    // Получаем значения для расчетов
    $values = fetchAll(
        "SELECT cell, value FROM pgu_fullparam_values 
         WHERE pgu_id = ? AND date = ? AND shift_id = ?",
        [$pguId, $date, $shiftId]
    );
    
    $valueMap = [];
    foreach ($values as $value) {
        $valueMap[$value['cell']] = $value['value'];
    }
    
    // Расчет F15/G15 = (F12 - F13 - F14 * 0.02) / F12 * 100
    if (isset($valueMap[$cellLetter . '12']) && isset($valueMap[$cellLetter . '13']) && isset($valueMap[$cellLetter . '14'])) {
        $f12 = $valueMap[$cellLetter . '12'];
        $f13 = $valueMap[$cellLetter . '13'];
        $f14 = $valueMap[$cellLetter . '14'];
        
        $f15 = ($f12 - $f13 - $f14 * 0.02) / $f12 * 100;
        
        // Сохраняем F15/G15
        $rowNum15 = 15;
        $existingValue = fetchOne(
            "SELECT id FROM pgu_fullparam_values 
             WHERE fullparam_id = (SELECT id FROM pgu_fullparams WHERE row_num = ?) 
             AND pgu_id = ? AND date = ? AND shift_id = ?",
            [$rowNum15, $pguId, $date, $shiftId]
        );
        
        if ($existingValue) {
            update(
                'pgu_fullparam_values',
                ['value' => $f15, 'cell' => $cellLetter . '15'],
                'id = ?',
                [$existingValue['id']]
            );
        } else {
            insert('pgu_fullparam_values', [
                'fullparam_id' => fetchOne("SELECT id FROM pgu_fullparams WHERE row_num = ?", [$rowNum15])['id'],
                'pgu_id' => $pguId,
                'date' => $date,
                'shift_id' => $shiftId,
                'value' => $f15,
                'cell' => $cellLetter . '15'
            ]);
        }
    }

    if (isset($valueMap[$cellLetter . '30']) && isset($valueMap[$cellLetter . '43'])) {
        $f30 = $valueMap[$cellLetter . '30'];
        $f43 = $valueMap[$cellLetter . '43'];
        
        $f31 = $f30 / 4.1868 * $f43;
        
        // Сохраняем F31/G31
        $rowNum31 = 31;
        $existingValue = fetchOne(
            "SELECT id FROM pgu_fullparam_values 
             WHERE fullparam_id = (SELECT id FROM pgu_fullparams WHERE row_num = ?) 
             AND pgu_id = ? AND date = ? AND shift_id = ?",
            [$rowNum31, $pguId, $date, $shiftId]
        );
        
        if ($existingValue) {
            update(
                'pgu_fullparam_values',
                ['value' => $f31, 'cell' => $cellLetter . '31'],
                'id = ?',
                [$existingValue['id']]
            );
        } else {
            insert('pgu_fullparam_values', [
                'fullparam_id' => fetchOne("SELECT id FROM pgu_fullparams WHERE row_num = ?", [$rowNum31])['id'],
                'pgu_id' => $pguId,
                'date' => $date,
                'shift_id' => $shiftId,
                'value' => $f31,
                'cell' => $cellLetter . '31'
            ]);
        }
    }
    
    if (isset($valueMap[$cellLetter . '14']) && isset($valueMap[$cellLetter . '16'])) {
        $f14 = $valueMap[$cellLetter . '14'];
        $f16 = $valueMap[$cellLetter . '16'];

        $f34 = $f14 / $f16;

        $rowNum34 = 34;
        $existingValue = fetchOne(
            "SELECT id FROM pgu_fullparam_values 
             WHERE fullparam_id = (SELECT id FROM pgu_fullparams WHERE row_num = ?) 
             AND pgu_id = ? AND date = ? AND shift_id = ?",
            [$rowNum34, $pguId, $date, $shiftId]
        );

        if ($existingValue) {
            update(
                'pgu_fullparam_values',
                ['value' => $f34, 'cell' => $cellLetter . '34'],
                'id = ?',
                [$existingValue['id']]
            );
        } else {
            insert('pgu_fullparam_values', [
                'fullparam_id' => fetchOne("SELECT id FROM pgu_fullparams WHERE row_num = ?", [$rowNum34])['id'],
                'pgu_id' => $pguId,
                'date' => $date,
                'shift_id' => $shiftId,
                'value' => $f34,
                'cell' => $cellLetter . '34'
            ]);
        }
    }

    // Расчет F36/G36 = F21 - F20
    if (isset($valueMap[$cellLetter . '21']) && isset($valueMap[$cellLetter . '20'])) {
        $f21 = $valueMap[$cellLetter . '21'];
        $f20 = $valueMap[$cellLetter . '20'];
        
        $f36 = $f21 - $f20;
        
        // Сохраняем F36/G36
        $rowNum36 = 36;
        $existingValue = fetchOne(
            "SELECT id FROM pgu_fullparam_values 
             WHERE fullparam_id = (SELECT id FROM pgu_fullparams WHERE row_num = ?) 
             AND pgu_id = ? AND date = ? AND shift_id = ?",
            [$rowNum36, $pguId, $date, $shiftId]
        );
        
        if ($existingValue) {
            update(
                'pgu_fullparam_values',
                ['value' => $f36, 'cell' => $cellLetter . '36'],
                'id = ?',
                [$existingValue['id']]
            );
        } else {
            insert('pgu_fullparam_values', [
                'fullparam_id' => fetchOne("SELECT id FROM pgu_fullparams WHERE row_num = ?", [$rowNum36])['id'],
                'pgu_id' => $pguId,
                'date' => $date,
                'shift_id' => $shiftId,
                'value' => $f36,
                'cell' => $cellLetter . '36'
            ]);
        }
    }
    
    if (isset($valueMap[$cellLetter . '31'])) {
        $f31 = $valueMap[$cellLetter . '31'];
        $f38 = (8000.3 - $f31) / 8000.3 * 100;
    }

    $rowNum38 = 38;
    $existingValue = fetchOne(
        "SELECT id FROM pgu_fullparam_values 
        WHERE fullparam_id = (SELECT id FROM pgu_fullparams WHERE row_num = ?) 
        AND pgu_id = ? AND date = ? AND shift_id = ?",  
        [$rowNum38, $pguId, $date, $shiftId]
    );

    if ($existingValue) {
        update(
            'pgu_fullparam_values',
            ['value' => $f38, 'cell' => $cellLetter . '38'],
            'id = ?',
            [$existingValue['id']]
        );                          
    } else {
        insert('pgu_fullparam_values', [
            'fullparam_id' => fetchOne("SELECT id FROM pgu_fullparams WHERE row_num = ?", [$rowNum38])['id'],
            'pgu_id' => $pguId,
            'date' => $date,
            'shift_id' => $shiftId,
            'value' => $f38,
            'cell' => $cellLetter . '38'
        ]);
    }

    if (isset($valueMap[$cellLetter . '41']) && isset($valueMap[$cellLetter . '31'])) {
        $f41 = $valueMap[$cellLetter . '41'];
        $f31 = $valueMap[$cellLetter . '31'];
        $f42 = $f41 * $f31 / 7000;
    }

    $rowNum42 = 42;
    $existingValue = fetchOne(
        "SELECT id FROM pgu_fullparam_values 
        WHERE fullparam_id = (SELECT id FROM pgu_fullparams WHERE row_num = ?) 
        AND pgu_id = ? AND date = ? AND shift_id = ?",  
        [$rowNum42, $pguId, $date, $shiftId]
    );

    if ($existingValue) {   
        update(
            'pgu_fullparam_values',
            ['value' => $f42, 'cell' => $cellLetter . '42'],
            'id = ?',
            [$existingValue['id']]
        );
    } else {
        insert('pgu_fullparam_values', [
            'fullparam_id' => fetchOne("SELECT id FROM pgu_fullparams WHERE row_num = ?", [$rowNum42])['id'],
            'pgu_id' => $pguId,
            'date' => $date,
            'shift_id' => $shiftId,
            'value' => $f42,
            'cell' => $cellLetter . '42'
        ]);
    }
}

/**
 * Сохранение значений параметров
 */
function saveParameterValues()
{
    // Проверка аутентификации
    $user = requireAuth();

    // Получение данных из тела запроса
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        return sendError('Неверный формат данных', 400);
    }

    // Проверка обязательных полей
    if (
        !isset($input['equipmentId']) || !isset($input['date']) ||
        !isset($input['shiftId']) || !isset($input['values'])
    ) {
        return sendError('Не все обязательные поля заполнены', 400);
    }

    $equipmentId = $input['equipmentId'];
    $date = $input['date'];
    $shiftId = $input['shiftId'];
    $values = $input['values'];

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

            // Получаем значение cell для данного параметра и оборудования
            $cellValue = getCellValue($parameterId, $equipmentId);
            
            if ($cellValue === null) {
                continue; // Пропускаем параметр если нет маппинга
            }

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
                    ['value' => $paramValue, 'cell' => $cellValue],
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
                    'user_id' => $user['id'],
                    'cell' => $cellValue
                ]);
            }

            $savedCount++;
        }

        // Копирование значений в pgu_fullparam_values (только параметры, не счетчики)
        try {
        copyToPguFullParams($equipmentId, $date, $shiftId, $user['id']);
        } catch (Exception $e) {
            $db->rollBack();
            return sendError('Ошибка при копировании значений в pgu_fullparam_values: ' . $e->getMessage(), 500);
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

