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
        requireAuth();
        
        $date = $data['date'] ?? date('Y-m-d');
        $periodType = $data['periodType'] ?? 'day';
        
        // Обрабатываем shifts - может быть массивом или строкой
        $shifts = [1, 2, 3]; // по умолчанию
        if (isset($data['shifts'])) {
            if (is_array($data['shifts'])) {
                $shifts = array_map('intval', $data['shifts']);
            } elseif (is_string($data['shifts'])) {
                // Если строка, пытаемся разобрать как JSON или разделить запятыми
                $decoded = json_decode($data['shifts'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $shifts = array_map('intval', $decoded);
                } else {
                    $shifts = array_map('intval', explode(',', $data['shifts']));
                }
            }
        }
        
        error_log("URT getUrtAnalysisValues: date=$date, periodType=$periodType, shifts=" . json_encode($shifts));
        
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
        
        error_log("URT getUrtAnalysisValues: Найдено параметров: " . count($params));
        
        $result = [];
        
        foreach ($params as $param) {
            error_log("URT getUrtAnalysisValues: Обработка параметра id=" . $param['id'] . ", name=" . $param['name']);
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
                    
                    error_log("URT getUrtAnalysisValues: Для параметра id=" . $param['id'] . ", смена=$shiftId найдено значений: " . count($values));
                    
                    $shiftValues = [];
                    foreach ($values as $value) {
                        error_log("URT getUrtAnalysisValues: block_id=" . $value['block_id'] . ", value=" . $value['value'] . ", fact=" . $value['fact_value']);
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
                // Получаем агрегированные значения (только записи без shift_id для суточных данных)
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
                    AND urv.shift_id IS NULL
                    ORDER BY urv.block_id
                ", [$param['id'], $date]);
                
                error_log("URT getUrtAnalysisValues: Для параметра id=" . $param['id'] . " найдено значений: " . count($values));
                
                foreach ($values as $value) {
                    error_log("URT getUrtAnalysisValues: block_id=" . $value['block_id'] . ", value=" . $value['value'] . ", fact=" . $value['fact_value']);
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
        
        error_log("URT getUrtAnalysisValues: Всего параметров в результате: " . count($result));
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
                error_log("URT saveUrtAnalysisValues: Обновлена запись для param_id=$paramId, blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL'));
            } else {
                insert('urt_result_values', $recordData);
                error_log("URT saveUrtAnalysisValues: Создана новая запись для param_id=$paramId, blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", value=" . ($recordData['value'] ?? 'NULL') . ", fact=" . ($recordData['fact_value'] ?? 'NULL'));
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
                    
                    // Для ТГ7, ТГ8, ПГУ1 и ПГУ2 рассчитываем norm, fact, db3
                    // Для param_id = 1 (выработка) и param_id = 2 (расход на с.н.) db3 должен быть null
                    if (($paramId == 1 || $paramId == 2) && ($blockId == 7 || $blockId == 8 || $blockId == 1 || $blockId == 2)) {
                        error_log("URT: param_id=$paramId, blockId=$blockId, shiftId=$shiftId, calculatedValue=" . ($calculatedValue ?? 'NULL'));
                        if ($calculatedValue !== null) {
                            // Для выработки и расхода на с.н. norm и db3 должны быть null
                            $values[] = [
                                'param_id' => $paramId,
                                'block_id' => $blockId,
                                'shift_id' => $shiftId,
                                'value' => $calculatedValue,
                                'norm' => null,
                                'fact' => $calculatedValue,
                                'db3' => null
                            ];
                            error_log("URT: Добавлено значение в массив values для param_id=$paramId, blockId=$blockId, fact=$calculatedValue, norm=null, db3=null");
                        }
                    } else {
                        if ($calculatedValue !== null) {
                            // Для остальных блоков сохраняем значение в fact
                            $values[] = [
                                'param_id' => $paramId,
                                'block_id' => $blockId,
                                'shift_id' => $shiftId,
                                'value' => $calculatedValue,
                                'norm' => null,
                                'fact' => ($paramId == 1 || $paramId == 2 ? $calculatedValue : null),
                                'db3' => null
                            ];
                        }
                    }
                }
            }
        } else {
            foreach ($blocks as $block) {
                $blockId = $block['id'];
                $calculatedValue = calculateUrtParameterValue($paramId, $blockId, $date, null, $rowNum);
                
                // Для ТГ7, ТГ8, ПГУ1 и ПГУ2 рассчитываем norm, fact, db3
                // Для param_id = 1 (выработка) и param_id = 2 (расход на с.н.) db3 должен быть null
                if (($paramId == 1 || $paramId == 2) && ($blockId == 7 || $blockId == 8 || $blockId == 1 || $blockId == 2)) {
                    if ($calculatedValue !== null) {
                        // Для выработки и расхода на с.н. norm и db3 должны быть null
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                    }
                } else {
                    if ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем значение в fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => ($paramId == 1 || $paramId == 2 ? $calculatedValue : null),
                            'db3' => null
                        ];
                    }
                }
            }
        }
    }
    
    error_log("URT calculateUrtAnalysisValues: Всего подготовлено значений для сохранения: " . count($values));
    return $values;
}

/**
 * Расчет значения параметра УРТ
 */
function calculateUrtParameterValue($paramId, $blockId, $date, $shiftId, $rowNum) {
    try {
        // Получаем соединение с базой данных
        $db = getDbConnection();
        
        // Для каждого параметра используем свою логику расчета
        switch ($paramId) {
            // Выработка электроэнергии
            case 1:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8 берем значение из tg_result_values с param_id = 26
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 26 AND tg_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                        error_log("URT calculateUrtParameterValue: Запрос для param_id=1, blockId=$blockId, date=$date, shiftId=$shiftId");
                    } else {
                        $stmt->execute([$blockId, $date]);
                        error_log("URT calculateUrtParameterValue: Запрос для param_id=1, blockId=$blockId, date=$date, shiftId=NULL");
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Результат для param_id=1, blockId=$blockId: " . ($value !== null ? $value : 'NULL'));
                    return $value;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2 берем значение из pgu_fullparam_values с fullparam_id = 3 (Эвыр_пгу)
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_fullparam_values 
                        WHERE fullparam_id = 3 AND pgu_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result ? (float)$result['value'] : null;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам" - сумма выработки ТГ7 и ТГ8
                    $tg7Value = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tg8Value = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    if ($tg7Value !== null && $tg8Value !== null) {
                        return $tg7Value + $tg8Value;
                    } elseif ($tg7Value !== null) {
                        return $tg7Value;
                    } elseif ($tg8Value !== null) {
                        return $tg8Value;
                    }
                    return null;
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ" - сумма выработки ПГУ1 и ПГУ2
                    $pgu1Value = calculateUrtParameterValue($paramId, 1, $date, $shiftId, $rowNum);
                    $pgu2Value = calculateUrtParameterValue($paramId, 2, $date, $shiftId, $rowNum);
                    
                    if ($pgu1Value !== null && $pgu2Value !== null) {
                        return $pgu1Value + $pgu2Value;
                    } elseif ($pgu1Value !== null) {
                        return $pgu1Value;
                    } elseif ($pgu2Value !== null) {
                        return $pgu2Value;
                    }
                    return null;
                }
                elseif ($blockId == 5) {
                    // Для "по Станции" - сумма выработки всех источников
                    $tg7Value = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tg8Value = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    $pgu1Value = calculateUrtParameterValue($paramId, 1, $date, $shiftId, $rowNum);
                    $pgu2Value = calculateUrtParameterValue($paramId, 2, $date, $shiftId, $rowNum);
                    $fesValue = calculateUrtParameterValue($paramId, 4, $date, $shiftId, $rowNum);
                    
                    $sum = 0;
                    $hasValue = false;
                    if ($tg7Value !== null) { $sum += $tg7Value; $hasValue = true; }
                    if ($tg8Value !== null) { $sum += $tg8Value; $hasValue = true; }
                    if ($pgu1Value !== null) { $sum += $pgu1Value; $hasValue = true; }
                    if ($pgu2Value !== null) { $sum += $pgu2Value; $hasValue = true; }
                    if ($fesValue !== null) { $sum += $fesValue; $hasValue = true; }
                    
                    return $hasValue ? $sum : null;
                }
                // Для ФЭС (blockId == 4) выработка не рассчитывается (нужна отдельная логика при необходимости)
                else {
                    return null;
                }
                break;
                
            // Расход электроэнергии на с.н.
            case 2:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8 берем значение из счетчиков расхода на собственные нужды
                    // Маппинг blockId на equipment_id для собственных нужд
                    $equipmentMap = [7 => 1, 8 => 2];
                    $equipmentId = $equipmentMap[$blockId];
                    
                    // Получаем показания счетчиков собственных нужд для блока (только активные счетчики)
                    $stmt = $db->prepare('
                        SELECT mr.shift1, mr.shift2, mr.shift3, mr.total
                        FROM meters m
                        LEFT JOIN meter_readings mr ON m.id = mr.meter_id AND mr.date = ?
                        WHERE m.equipment_id = ? AND m.meter_type_id = 2 AND m.is_active = 1
                    ');
                    $stmt->execute([$date, $equipmentId]);
                    $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Суммируем расход по всем счетчикам собственных нужд блока
                    $totalConsumption = 0;
                    if (!empty($readings)) {
                        foreach ($readings as $reading) {
                            if ($shiftId !== null) {
                                // Для смены
                                $shiftField = 'shift' . $shiftId;
                                $totalConsumption += (float)($reading[$shiftField] ?? 0);
                            } else {
                                // Для суточного/периодного расчета
                                $totalConsumption += (float)($reading['total'] ?? 0);
                            }
                        }
                    }
                    
                    // Получаем долю от общих счетчиков для собственных нужд
                    $commonMeterShares = calculateCommonMeterSharesForUrt($date);
                    
                    if (!empty($commonMeterShares) && isset($commonMeterShares[$equipmentId])) {
                        $equipmentData = $commonMeterShares[$equipmentId];
                        
                        if ($shiftId !== null) {
                            // Для смены - получаем долю от общих счетчиков для собственных нужд
                            $commonOwnNeeds = $equipmentData['shifts'][$shiftId] ?? 0;
                            $totalConsumption += $commonOwnNeeds;
                            
                            error_log("URT: Расход на с.н. для blockId=$blockId, equipmentId=$equipmentId, смена=$shiftId: прямые счетчики=" . ($totalConsumption - $commonOwnNeeds) . ", общие счетчики=$commonOwnNeeds, итого=$totalConsumption");
                        } else {
                            // Для суточного расчета - суммируем все смены
                            $commonOwnNeeds = 0;
                            for ($i = 1; $i <= 3; $i++) {
                                $commonOwnNeeds += $equipmentData['shifts'][$i] ?? 0;
                            }
                            $totalConsumption += $commonOwnNeeds;
                            
                            error_log("URT: Расход на с.н. для blockId=$blockId, equipmentId=$equipmentId, сутки: прямые счетчики=" . ($totalConsumption - $commonOwnNeeds) . ", общие счетчики=$commonOwnNeeds, итого=$totalConsumption");
                        }
                    }
                    
                    error_log("URT calculateUrtParameterValue: Расход на с.н. для blockId=$blockId, equipmentId=$equipmentId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", найдено счетчиков=" . count($readings) . ", значение=$totalConsumption");
                    
                    // Возвращаем значение даже если оно 0 (0 - это валидное значение)
                    return $totalConsumption;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам" - сумма расхода на с.н. ТГ7 и ТГ8
                    $tg7Value = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tg8Value = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    if ($tg7Value !== null && $tg8Value !== null) {
                        return $tg7Value + $tg8Value;
                    } elseif ($tg7Value !== null) {
                        return $tg7Value;
                    } elseif ($tg8Value !== null) {
                        return $tg8Value;
                    }
                    return null;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2 берем значение из счетчиков расхода на собственные нужды
                    // Маппинг ПГУ к equipment_id для собственных нужд
                    // ПГУ1 использует ТСН-1 (equipment_id = 3 для поиска), ПГУ2 использует ТСН-2 (equipment_id = 5)
                    $pguOwnNeedsMapping = [1 => 3, 2 => 5];
                    $equipmentId = $pguOwnNeedsMapping[$blockId];
                    
                    // Получаем показания счетчиков собственных нужд для ПГУ (только активные счетчики)
                    $stmt = $db->prepare('
                        SELECT mr.shift1, mr.shift2, mr.shift3, mr.total
                        FROM meters m
                        LEFT JOIN meter_readings mr ON m.id = mr.meter_id AND mr.date = ?
                        WHERE m.equipment_id = ? AND m.meter_type_id = 2 AND m.is_active = 1
                    ');
                    $stmt->execute([$date, $equipmentId]);
                    $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Суммируем расход по всем счетчикам собственных нужд ПГУ
                    $totalConsumption = 0;
                    if (!empty($readings)) {
                        foreach ($readings as $reading) {
                            if ($shiftId !== null) {
                                // Для смены
                                $shiftField = 'shift' . $shiftId;
                                $totalConsumption += (float)($reading[$shiftField] ?? 0);
                            } else {
                                // Для суточного/периодного расчета - суммируем все смены
                                $totalConsumption += (float)($reading['total'] ?? 0);
                            }
                        }
                    }
                    
                    // Получаем долю от общих счетчиков для собственных нужд ПГУ
                    $commonMeterShares = calculateCommonMeterSharesForUrt($date);
                    
                    if (!empty($commonMeterShares) && isset($commonMeterShares[$equipmentId])) {
                        $equipmentData = $commonMeterShares[$equipmentId];
                        
                        if ($shiftId !== null) {
                            // Для смены - получаем долю от общих счетчиков для собственных нужд
                            $commonOwnNeeds = $equipmentData['shifts'][$shiftId] ?? 0;
                            $totalConsumption += $commonOwnNeeds;
                            
                            error_log("URT: Расход на с.н. для ПГУ blockId=$blockId, equipmentId=$equipmentId, смена=$shiftId: прямые счетчики=" . ($totalConsumption - $commonOwnNeeds) . ", общие счетчики=$commonOwnNeeds, итого=$totalConsumption");
                        } else {
                            // Для суточного расчета - суммируем все смены
                            $commonOwnNeeds = 0;
                            for ($i = 1; $i <= 3; $i++) {
                                $commonOwnNeeds += $equipmentData['shifts'][$i] ?? 0;
                            }
                            $totalConsumption += $commonOwnNeeds;
                            
                            error_log("URT: Расход на с.н. для ПГУ blockId=$blockId, equipmentId=$equipmentId, сутки: прямые счетчики=" . ($totalConsumption - $commonOwnNeeds) . ", общие счетчики=$commonOwnNeeds, итого=$totalConsumption");
                        }
                    }
                    
                    error_log("URT calculateUrtParameterValue: Расход на с.н. для ПГУ blockId=$blockId, equipmentId=$equipmentId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", найдено счетчиков=" . count($readings) . ", значение=$totalConsumption");
                    
                    // Возвращаем значение даже если оно 0 (0 - это валидное значение)
                    return $totalConsumption;
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ" - сумма расхода на с.н. ПГУ1 и ПГУ2
                    $pgu1Value = calculateUrtParameterValue($paramId, 1, $date, $shiftId, $rowNum);
                    $pgu2Value = calculateUrtParameterValue($paramId, 2, $date, $shiftId, $rowNum);
                    
                    if ($pgu1Value !== null && $pgu2Value !== null) {
                        return $pgu1Value + $pgu2Value;
                    } elseif ($pgu1Value !== null) {
                        return $pgu1Value;
                    } elseif ($pgu2Value !== null) {
                        return $pgu2Value;
                    }
                    return null;
                }
                elseif ($blockId == 5) {
                    // Для "по Станции" - сумма расхода на с.н. всех источников
                    $tg7Value = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tg8Value = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    $pgu1Value = calculateUrtParameterValue($paramId, 1, $date, $shiftId, $rowNum);
                    $pgu2Value = calculateUrtParameterValue($paramId, 2, $date, $shiftId, $rowNum);
                    $fesValue = calculateUrtParameterValue($paramId, 4, $date, $shiftId, $rowNum);
                    
                    $sum = 0;
                    $hasValue = false;
                    if ($tg7Value !== null) { $sum += $tg7Value; $hasValue = true; }
                    if ($tg8Value !== null) { $sum += $tg8Value; $hasValue = true; }
                    if ($pgu1Value !== null) { $sum += $pgu1Value; $hasValue = true; }
                    if ($pgu2Value !== null) { $sum += $pgu2Value; $hasValue = true; }
                    if ($fesValue !== null) { $sum += $fesValue; $hasValue = true; }
                    
                    return $hasValue ? $sum : null;
                }
                // Для ФЭС (blockId == 4) расход на с.н. не рассчитывается (нужна отдельная логика при необходимости)
                else {
                    return null;
                }
                break;
                
            // Отпуск электроэнергии
            case 3:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8 берем значение из tg_result_values с param_id = 27
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 27 AND tg_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : '')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result ? (float)$result['value'] : null;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам" - сумма отпуска ТГ7 и ТГ8
                    $tg7Value = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tg8Value = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    if ($tg7Value !== null && $tg8Value !== null) {
                        return $tg7Value + $tg8Value;
                    }
                }
                break;
                
            // Фактический УРТ (param_id = 22)
            case 22:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8 берем значение из tg_result_values с param_id = 13 (Фактическое значение, category 4)
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 13 AND tg_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : '')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result ? (float)$result['value'] : null;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам" - средневзвешенное значение ТГ7 и ТГ8 по отпуску электроэнергии
                    $tg7Fact = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tg8Fact = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    $tg7Release = calculateUrtParameterValue(3, 7, $date, $shiftId, $rowNum);
                    $tg8Release = calculateUrtParameterValue(3, 8, $date, $shiftId, $rowNum);
                    
                    if ($tg7Fact !== null && $tg8Fact !== null && $tg7Release !== null && $tg8Release !== null) {
                        $totalRelease = $tg7Release + $tg8Release;
                        if ($totalRelease > 0) {
                            return ($tg7Fact * $tg7Release + $tg8Fact * $tg8Release) / $totalRelease;
                        }
                    }
                }
                break;
            
            // Для остальных параметров возвращаем null (логика расчета еще не реализована)
            default:
                return null;
        }
        
        return null;
    } catch (Exception $e) {
        error_log('Ошибка при расчете значения параметра УРТ: ' . $e->getMessage());
        return null;
    }
}

/**
 * Получение нормативного значения УРТ
 */
function getUrtNormValue($paramId, $blockId) {
    try {
        // Для каждого параметра используем свою логику получения нормативного значения
        switch ($paramId) {
            // Нормативный УРТ (param_id = 21)
            case 21:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8 берем значение из tg_result_values с param_id = 11 (Номинальное значение с учетом работы ОИУ, category 4)
                    $db = getDbConnection();
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 11 AND tg_id = ? 
                        ORDER BY date DESC LIMIT 1
                    ');
                    
                    $stmt->execute([$blockId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result ? (float)$result['value'] : null;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2 берем значение из pgu_result_values с param_id = 12 (Номинальное значение, (для ПГУ))
                    $db = getDbConnection();
                    $pguId = $blockId; // blockId 1 = ПГУ1, blockId 2 = ПГУ2
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_result_values 
                        WHERE param_id = 12 AND pgu_id = ? 
                        ORDER BY date DESC LIMIT 1
                    ');
                    
                    $stmt->execute([$pguId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result ? (float)$result['value'] : null;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам" - среднее значение ТГ7 и ТГ8
                    $tg7Norm = getUrtNormValue($paramId, 7);
                    $tg8Norm = getUrtNormValue($paramId, 8);
                    
                    if ($tg7Norm !== null && $tg8Norm !== null) {
                        return ($tg7Norm + $tg8Norm) / 2;
                    }
                }
                break;
                
            // Для остальных параметров возвращаем null (логика расчета еще не реализована)
            default:
                return null;
        }
        
        return null;
    } catch (Exception $e) {
        error_log('Ошибка при получении нормативного значения УРТ: ' . $e->getMessage());
        return null;
    }
}

/**
 * Расчет значения db3 (отклонение)
 */
function calculateDb3Value($fact, $norm) {
    if ($norm == 0) return 0;
    return $fact - $norm;
}

/**
 * Расчет долей общих счетчиков для УРТ
 * Возвращает массив: [equipment_id => ['equipment_id' => id, 'equipment_name' => name, 'shifts' => [1 => value, 2 => value, 3 => value]]]
 */
function calculateCommonMeterSharesForUrt($date) {
    try {
        $db = getDbConnection();
        
        // Получаем данные об использовании общих счетчиков за дату
        $stmt = $db->prepare('
            SELECT 
                cmbu.*,
                m.name as meter_name,
                m.coefficient_k,
                e.name as equipment_name
            FROM common_meter_block_usage cmbu
            JOIN meters m ON cmbu.meter_id = m.id
            JOIN equipment e ON cmbu.equipment_id = e.id
            WHERE cmbu.date = ?
            ORDER BY cmbu.start_time
        ');
        $stmt->execute([$date]);
        $usage = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($usage)) {
            return [];
        }
        
        // Получаем показания счетчиков за дату
        $stmt = $db->prepare('
            SELECT mr.*, m.coefficient_k
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE mr.date = ? AND mr.meter_id IN (
                SELECT DISTINCT meter_id FROM common_meter_block_usage WHERE date = ?
            )
        ');
        $stmt->execute([$date, $date]);
        $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Создаем массив показаний по счетчикам
        $meterReadings = [];
        foreach ($readings as $reading) {
            $meterReadings[$reading['meter_id']] = $reading;
        }
        
        // Результат: [equipment_id => ['equipment_id' => id, 'equipment_name' => name, 'shifts' => [1 => value, 2 => value, 3 => value]]]
        $result = [];
        
        // Инициализируем записи для ТГ7, ТГ8, ПГУ1 и ПГУ2
        $result[1] = [
            'equipment_id' => 1,
            'equipment_name' => 'ТГ7',
            'shifts' => [1 => 0, 2 => 0, 3 => 0]
        ];
        
        $result[2] = [
            'equipment_id' => 2,
            'equipment_name' => 'ТГ8',
            'shifts' => [1 => 0, 2 => 0, 3 => 0]
        ];
        
        $result[3] = [
            'equipment_id' => 3,
            'equipment_name' => 'ПГУ1',
            'shifts' => [1 => 0, 2 => 0, 3 => 0]
        ];
        
        $result[5] = [
            'equipment_id' => 5,
            'equipment_name' => 'ПГУ2',
            'shifts' => [1 => 0, 2 => 0, 3 => 0]
        ];
        
        // Обрабатываем каждую запись использования
        foreach ($usage as $record) {
            $equipmentId = $record['equipment_id'];
            $meterId = $record['meter_id'];
            $startTime = $record['start_time'];
            $endTime = $record['end_time'];
            $startReading = (float)$record['start_reading'];
            $endReading = (float)$record['end_reading'];
            $coefficient = (float)$record['coefficient_k'];
            
            // Пропускаем, если equipment_id не входит в наш список
            if (!isset($result[$equipmentId])) {
                continue;
            }
            
            // Получаем показания счетчика
            if (!isset($meterReadings[$meterId])) {
                error_log("URT: Нет показаний для счетчика $meterId на дату $date");
                continue;
            }
            
            $reading = $meterReadings[$meterId];
            $r0 = (float)($reading['r0'] ?? 0);
            $r8 = (float)($reading['r8'] ?? 0);
            $r16 = (float)($reading['r16'] ?? 0);
            $r24 = (float)($reading['r24'] ?? 0);
            
            // Определяем границы смен с правильной логикой
            $shiftBoundaries = [
                1 => ['start' => '00:00:00', 'end' => '08:00:00', 'r_start' => $r0, 'r_end' => $r8],
                2 => ['start' => '08:00:00', 'end' => '16:00:00', 'r_start' => $r8, 'r_end' => $r16],
                3 => ['start' => '16:00:00', 'end' => '24:00:00', 'r_start' => $r16, 'r_end' => $r24]
            ];
            
            // Исправляем логику: если r16 или r24 NULL, используем предыдущее значение
            if ($r16 === null && $r8 !== null) {
                $shiftBoundaries[2]['r_end'] = $r8;
            }
            if ($r24 === null && $r16 !== null) {
                $shiftBoundaries[3]['r_end'] = $r16;
            } elseif ($r24 === null && $r8 !== null) {
                $shiftBoundaries[3]['r_end'] = $r8;
            }
            
            // Определяем, в какой смене находится начало и конец использования
            $startShift = null;
            $endShift = null;
            
            foreach ($shiftBoundaries as $shiftNum => $boundary) {
                $shiftStart = strtotime($date . ' ' . $boundary['start']);
                $shiftEnd = strtotime($date . ' ' . $boundary['end']);
                $usageStart = strtotime($date . ' ' . $startTime);
                $usageEnd = strtotime($date . ' ' . $endTime);
                
                if ($usageStart >= $shiftStart && $usageStart < $shiftEnd) {
                    $startShift = $shiftNum;
                }
                
                if ($usageEnd > $shiftStart && $usageEnd <= $shiftEnd) {
                    $endShift = $shiftNum;
                }
            }
            
            // Если использование полностью в одной смене
            if ($startShift === $endShift && $startShift !== null) {
                $energyConsumed = (float)$record['energy_consumed'];
                $result[$equipmentId]['shifts'][$startShift] += $energyConsumed;
            } 
            // Если использование пересекает границы смен
            else if ($startShift !== null && $endShift !== null) {
                // Для начальной смены
                $startShiftBoundary = $shiftBoundaries[$startShift];
                $Ra = $startShiftBoundary['r_end'];
                
                if ($Ra !== null && $startReading !== null) {
                    $startShiftConsumed = ($Ra - $startReading) * $coefficient / 1000;
                    $result[$equipmentId]['shifts'][$startShift] += $startShiftConsumed;
                }
                
                // Для конечной смены
                $endShiftBoundary = $shiftBoundaries[$endShift];
                $Rb = $endShiftBoundary['r_start'];
                
                if ($Rb !== null && $endReading !== null) {
                    $endShiftConsumed = ($endReading - $Rb) * $coefficient / 1000;
                    $result[$equipmentId]['shifts'][$endShift] += $endShiftConsumed;
                }
                
                // Для промежуточных смен (если есть)
                for ($i = $startShift + 1; $i < $endShift; $i++) {
                    if (isset($shiftBoundaries[$i]) && $shiftBoundaries[$i]['r_start'] !== null && $shiftBoundaries[$i]['r_end'] !== null) {
                        $intermediateConsumed = ($shiftBoundaries[$i]['r_end'] - $shiftBoundaries[$i]['r_start']) * $coefficient / 1000;
                        $result[$equipmentId]['shifts'][$i] += $intermediateConsumed;
                    }
                }
            }
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log('URT: Ошибка при расчете долей общих счетчиков: ' . $e->getMessage());
        return [];
    }
}
