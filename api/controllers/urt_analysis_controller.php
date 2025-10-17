<?php
/**
 * URT Analysis Controller
 * Контроллер для анализа удельного расхода топлива (УРТ)
 */

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/db.php';

/**
 * Получение параметров для анализа УРТ
 */
function getUrtAnalysisParams() {
    try {
        $params = fetchAll("
            SELECT 
                id,
                name,
                unit,
                symbol,
                row_num,
                description
            FROM urt_result_params 
            ORDER BY row_num ASC
        ");
        
        sendSuccess($params);
    } catch (Exception $e) {
        sendError('Ошибка при получении параметров УРТ: ' . $e->getMessage());
    }
}

/**
 * Получение значений анализа УРТ
 */
function getUrtAnalysisValues($data) {
    try {
        $date = $data['date'] ?? date('Y-m-d');
        $periodType = $data['periodType'] ?? 'day';
        $shifts = $data['shifts'] ?? [1, 2, 3];
        
        // Получаем параметры
        $params = fetchAll("
            SELECT 
                id,
                name,
                unit,
                symbol,
                row_num,
                description
            FROM urt_result_params 
            ORDER BY row_num ASC
        ");
        
        $result = [];
        
        foreach ($params as $param) {
            $paramData = [
                'id' => $param['id'],
                'name' => $param['name'],
                'unit' => $param['unit'],
                'symbol' => $param['symbol'],
                'row_num' => $param['row_num'],
                'description' => $param['description'],
                'values' => [],
                'valuesByShift' => []
            ];
            
            if ($periodType === 'shift') {
                // Получаем значения по сменам
                foreach ($shifts as $shiftId) {
                    $values = fetchAll("
                        SELECT 
                            urv.block_id,
                            urv.value,
                            urv.norm_value,
                            urv.fact_value,
                            urv.db3_value
                        FROM urt_result_values urv
                        WHERE urv.param_id = ? 
                        AND urv.date = ? 
                        AND urv.shift_id = ?
                        ORDER BY urv.block_id
                    ", [$param['id'], $date, $shiftId]);
                    
                    $shiftValues = [];
                    foreach ($values as $value) {
                        $shiftValues[$value['block_id']] = [
                            'value' => $value['value'],
                            'norm' => $value['norm_value'],
                            'fact' => $value['fact_value'],
                            'db3' => $value['db3_value']
                        ];
                    }
                    
                    $paramData['valuesByShift'][$shiftId] = $shiftValues;
                }
            } else {
                // Получаем агрегированные значения
                $values = fetchAll("
                    SELECT 
                        urv.block_id,
                        urv.value,
                        urv.norm_value,
                        urv.fact_value,
                        urv.db3_value
                    FROM urt_result_values urv
                    WHERE urv.param_id = ? 
                    AND urv.date = ?
                    ORDER BY urv.block_id
                ", [$param['id'], $date]);
                
                foreach ($values as $value) {
                    $paramData['values'][$value['block_id']] = [
                        'value' => $value['value'],
                        'norm' => $value['norm_value'],
                        'fact' => $value['fact_value'],
                        'db3' => $value['db3_value']
                    ];
                }
            }
            
            $result[] = $paramData;
        }
        
        sendSuccess($result);
    } catch (Exception $e) {
        sendError('Ошибка при получении значений УРТ: ' . $e->getMessage());
    }
}

/**
 * Сохранение значений анализа УРТ
 */
function saveUrtAnalysisValues($data) {
    try {
        $date = $data['date'];
        $periodType = $data['periodType'] ?? 'day';
        $values = $data['values'] ?? [];
        
        if (empty($values)) {
            sendError('Нет данных для сохранения');
        }
        
        $savedCount = 0;
        
        foreach ($values as $valueData) {
            $paramId = $valueData['param_id'];
            $blockId = $valueData['block_id'];
            $shiftId = $periodType === 'shift' ? ($valueData['shift_id'] ?? 1) : null;
            
            // Проверяем, существует ли запись
            $existing = fetchOne("
                SELECT id FROM urt_result_values 
                WHERE param_id = ? AND block_id = ? AND date = ?
                " . ($shiftId ? "AND shift_id = ?" : "AND shift_id IS NULL"),
                $shiftId ? [$paramId, $blockId, $date, $shiftId] : [$paramId, $blockId, $date]
            );
            
            $recordData = [
                'param_id' => $paramId,
                'block_id' => $blockId,
                'date' => $date,
                'value' => $valueData['value'] ?? null,
                'norm_value' => $valueData['norm'] ?? null,
                'fact_value' => $valueData['fact'] ?? null,
                'db3_value' => $valueData['db3'] ?? null
            ];
            
            if ($shiftId) {
                $recordData['shift_id'] = $shiftId;
            }
            
            if ($existing) {
                update('urt_result_values', $recordData, 'id = ?', [$existing['id']]);
            } else {
                insert('urt_result_values', $recordData);
            }
            
            $savedCount++;
        }
        
        sendSuccess([
            'message' => 'Значения УРТ сохранены',
            'savedCount' => $savedCount
        ]);
    } catch (Exception $e) {
        sendError('Ошибка при сохранении значений УРТ: ' . $e->getMessage());
    }
}

/**
 * Выполнение расчета анализа УРТ
 */
function performUrtAnalysisCalculation() {
    try {
        requireAuth();
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['periodType']) || !isset($data['dates'])) {
            sendError('Необходимо указать тип периода и даты', 400);
        }
        
        $periodType = $data['periodType'];
        $calculatedParams = 0;
        
        if ($periodType === 'shift') {
            $date = $data['dates']['selectedDate'];
            $shifts = $data['shifts'] ?? [1, 2, 3];
            
            $values = calculateUrtAnalysisValues($date, 'shift', $shifts);
            $calculatedParams = count($values);
            
            if (!empty($values)) {
                saveUrtAnalysisValues([
                    'date' => $date,
                    'periodType' => 'shift',
                    'values' => $values
                ]);
            }
        } elseif ($periodType === 'day') {
            $date = $data['dates']['selectedDate'];
            $values = calculateUrtAnalysisValues($date, 'day');
            $calculatedParams = count($values);
            
            if (!empty($values)) {
                saveUrtAnalysisValues([
                    'date' => $date,
                    'periodType' => 'day',
                    'values' => $values
                ]);
            }
        } else { // period
            $start = $data['dates']['startDate'];
            $end = $data['dates']['endDate'];
            $values = calculateUrtAnalysisValues($start, 'period', null, $end);
            $calculatedParams = count($values);
            
            if (!empty($values)) {
                saveUrtAnalysisValues([
                    'date' => $start,
                    'periodType' => 'period',
                    'period_start' => $start,
                    'period_end' => $end,
                    'values' => $values
                ]);
            }
        }
        
        sendSuccess([
            'message' => 'Расчет анализа УРТ выполнен',
            'results' => $calculatedParams,
            'calculatedParams' => $calculatedParams
        ]);
    } catch (Exception $e) {
        sendError('Ошибка при выполнении расчета анализа УРТ: ' . $e->getMessage());
    }
}

/**
 * Расчет значений для анализа УРТ
 */
function calculateUrtAnalysisValues($date, $periodType, $shifts = null, $endDate = null) {
    $values = [];
    
    // Получаем все параметры УРТ
    $params = fetchAll("SELECT * FROM urt_result_params ORDER BY row_num ASC");
    
    foreach ($params as $param) {
        $paramId = $param['id'];
        $rowNum = $param['row_num'];
        
        // Определяем блоки для расчета (Блок 7, Блок 8, по Блокам, ПГУ 1, ПГУ 2, по ПГУ, ФЭС, по станции)
        // Используем числовые ID для блоков
        $blocks = [
            ['id' => 7, 'name' => 'Блок 7'],
            ['id' => 8, 'name' => 'Блок 8'], 
            ['id' => 9, 'name' => 'по Блокам'],
            ['id' => 1, 'name' => 'ПГУ 1'],
            ['id' => 2, 'name' => 'ПГУ 2'],
            ['id' => 3, 'name' => 'по ПГУ'],
            ['id' => 4, 'name' => 'ФЭС'],
            ['id' => 5, 'name' => 'по станции']
        ];
        
        if ($periodType === 'shift' && $shifts) {
            // Преобразуем строки типа 'shift1' в числа
            $shiftIds = array_map(function($shift) {
                if (is_string($shift) && preg_match('/shift(\d+)/', $shift, $matches)) {
                    return (int)$matches[1];
                }
                return (int)$shift;
            }, $shifts);
            
            foreach ($shiftIds as $shiftId) {
                foreach ($blocks as $block) {
                    $blockId = $block['id'];
                    $calculatedValue = calculateUrtParameterValue($paramId, $blockId, $date, $shiftId, $rowNum);
                    
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => getUrtNormValue($paramId, $blockId),
                            'fact' => $calculatedValue,
                            'db3' => calculateDb3Value($calculatedValue, getUrtNormValue($paramId, $blockId))
                        ];
                    }
                }
            }
        } else {
            foreach ($blocks as $block) {
                $blockId = $block['id'];
                $calculatedValue = calculateUrtParameterValue($paramId, $blockId, $date, null, $rowNum);
                
                if ($calculatedValue !== null) {
                    $values[] = [
                        'param_id' => $paramId,
                        'block_id' => $blockId,
                        'value' => $calculatedValue,
                        'norm' => getUrtNormValue($paramId, $blockId),
                        'fact' => $calculatedValue,
                        'db3' => calculateDb3Value($calculatedValue, getUrtNormValue($paramId, $blockId))
                    ];
                }
            }
        }
    }
    
    return $values;
}

/**
 * Расчет значения параметра УРТ
 */
function calculateUrtParameterValue($paramId, $blockId, $date, $shiftId, $rowNum) {
    // Здесь должна быть логика расчета конкретных параметров УРТ
    // Пока возвращаем заглушку
    return rand(100, 1000) / 100; // Заглушка для тестирования
}

/**
 * Получение нормативного значения УРТ
 */
function getUrtNormValue($paramId, $blockId) {
    // Здесь должна быть логика получения нормативных значений
    // Пока возвращаем заглушку
    return rand(80, 120) / 100; // Заглушка для тестирования
}

/**
 * Расчет значения db3 (отклонение)
 */
function calculateDb3Value($fact, $norm) {
    if ($norm == 0) return 0;
    return $fact - $norm;
}
