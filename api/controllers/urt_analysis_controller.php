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
                    
                    // Для param_id = 11 (Расход на с.н. норма) сохраняем в norm
                    if ($paramId == 11) {
                        if ($calculatedValue !== null) {
                            $values[] = [
                                'param_id' => $paramId,
                                'block_id' => $blockId,
                                'shift_id' => $shiftId,
                                'value' => $calculatedValue,
                                'norm' => $calculatedValue,
                                'fact' => null,
                                'db3' => null
                            ];
                            error_log("URT: Добавлено значение в массив values для param_id=$paramId (норма), blockId=$blockId, norm=$calculatedValue");
                        }
                    }
                    // Для param_id = 12 (Расход на с.н. факт) сохраняем в fact
                    elseif ($paramId == 12) {
                        if ($calculatedValue !== null) {
                            $values[] = [
                                'param_id' => $paramId,
                                'block_id' => $blockId,
                                'shift_id' => $shiftId,
                                'value' => $calculatedValue,
                                'norm' => null,
                                'fact' => $calculatedValue,
                                'db3' => null
                            ];
                            error_log("URT: Добавлено значение в массив values для param_id=$paramId (факт), blockId=$blockId, fact=$calculatedValue");
                        }
                    }
                    // Для param_id = 13 (Температура острого пара) для ТГ7 и ТГ8: norm=540, fact=из parameter_values, db3=(norm-fact)*0.028/100*E33/7
                    elseif ($paramId == 13) {
                        if ($blockId == 7 || $blockId == 8) {
                            // Для ТГ7 и ТГ8: D18/G18=540 (норма), E18/H18=факт, F18/I18=(norm-fact)*0.028/100*E33/7
                            $norm = 540; // D18/G18
                            $fact = $calculatedValue; // E18/H18
                            
                            // Получаем E31/F31 (param_id = 38, row_num = 31, "Исходно-нормативное значение удельного расхода тепла брутто") для расчета db3
                            // В формуле указано E33/F33, но в базе данных это E31/F31
                            $db = getDbConnection();
                            $cell31 = $blockId == 7 ? 'E31' : 'F31';
                            $stmt = $db->prepare('
                                SELECT value FROM tg_result_values 
                                WHERE param_id = 38 AND tg_id = ? AND date = ? AND cell = ?
                                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                            );
                            
                            if ($shiftId) {
                                $stmt->execute([$blockId, $date, $cell31, $shiftId]);
                            } else {
                                $stmt->execute([$blockId, $date, $cell31]);
                            }
                            
                            $result31 = $stmt->fetch(PDO::FETCH_ASSOC);
                            $e31 = $result31 ? (float)$result31['value'] : 0;
                            
                            // Расчет db3: F18/I18=(D18-E18)*0.028/100*E31/7
                            $db3 = null;
                            if ($fact !== null) {
                                if ($e31 > 0) {
                                    $db3 = ($norm - $fact) * 0.028 / 100 * $e31 / 7;
                                } else {
                                    $db3 = 0; // Если E31 = 0, то db3 = 0
                                }
                            }
                            
                            $values[] = [
                                'param_id' => $paramId,
                                'block_id' => $blockId,
                                'shift_id' => $shiftId,
                                'value' => $fact,
                                'norm' => $norm,
                                'fact' => $fact,
                                'db3' => $db3
                            ];
                            error_log("URT: Добавлено значение для param_id=$paramId (температура острого пара), blockId=$blockId, norm=$norm, fact=$fact, db3=" . ($db3 ?? 'NULL') . ", E31=$e31");
                        } elseif ($calculatedValue !== null) {
                            // Для остальных блоков сохраняем только fact
                            $values[] = [
                                'param_id' => $paramId,
                                'block_id' => $blockId,
                                'shift_id' => $shiftId,
                                'value' => $calculatedValue,
                                'norm' => null,
                                'fact' => $calculatedValue,
                                'db3' => null
                            ];
                            error_log("URT: Добавлено значение для param_id=$paramId (температура острого пара), blockId=$blockId, fact=$calculatedValue");
                        }
                    }
                    // Для param_id = 14 (Давление острого пара) для ТГ7 и ТГ8: norm=130, fact=из parameter_values, db3=IF(нагрузка<=150,0,(norm-fact)*0.05/100*E33/7)
                    elseif ($paramId == 14) {
                        if ($blockId == 7 || $blockId == 8) {
                            // Для ТГ7 и ТГ8: D19/G19=130 (норма), E19/H19=факт, F19/I19=IF(E10/H10<=150,0,(norm-fact)*0.05/100*E33/7)
                            $norm = 130; // D19/G19
                            $fact = $calculatedValue; // E19/H19
                            
                            // Получаем E31/F31 (param_id = 38, row_num = 31, "Исходно-нормативное значение удельного расхода тепла брутто") для расчета db3
                            // В формуле указано E33/F33, но в базе данных это E31/F31
                            $db = getDbConnection();
                            $cell31 = $blockId == 7 ? 'E31' : 'F31';
                            $stmt = $db->prepare('
                                SELECT value FROM tg_result_values 
                                WHERE param_id = 38 AND tg_id = ? AND date = ? AND cell = ?
                                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                            );
                            
                            if ($shiftId) {
                                $stmt->execute([$blockId, $date, $cell31, $shiftId]);
                            } else {
                                $stmt->execute([$blockId, $date, $cell31]);
                            }
                            
                            $result31 = $stmt->fetch(PDO::FETCH_ASSOC);
                            $e31 = $result31 ? (float)$result31['value'] : 0;
                            
                            // Получаем E10/H10 (средняя электрическая нагрузка, param_id = 6) для проверки условия
                            $load = calculateUrtParameterValue(6, $blockId, $date, $shiftId, $rowNum);
                            
                            // Расчет db3: F19/I19=IF(E10/H10<=150,0,(D19-E19)*0.05/100*E31/7)
                            $db3 = null;
                            if ($fact !== null && $load !== null && $load > 150) {
                                if ($e31 > 0) {
                                    $db3 = ($norm - $fact) * 0.05 / 100 * $e31 / 7;
                                } else {
                                    $db3 = 0; // Если E31 = 0, то db3 = 0
                                }
                            } else {
                                $db3 = 0; // Если нагрузка <= 150, то db3 = 0
                            }
                            
                            $values[] = [
                                'param_id' => $paramId,
                                'block_id' => $blockId,
                                'shift_id' => $shiftId,
                                'value' => $fact,
                                'norm' => $norm,
                                'fact' => $fact,
                                'db3' => $db3
                            ];
                            error_log("URT: Добавлено значение для param_id=$paramId (давление острого пара), blockId=$blockId, norm=$norm, fact=$fact, db3=$db3, нагрузка=$load, E31=$e31");
                        } elseif ($calculatedValue !== null) {
                            // Для остальных блоков сохраняем только fact
                            $values[] = [
                                'param_id' => $paramId,
                                'block_id' => $blockId,
                                'shift_id' => $shiftId,
                                'value' => $calculatedValue,
                                'norm' => null,
                                'fact' => $calculatedValue,
                                'db3' => null
                            ];
                            error_log("URT: Добавлено значение для param_id=$paramId (давление острого пара), blockId=$blockId, fact=$calculatedValue");
                        }
                    }
                    // Для param_id = 15 (Температура пара промперегрева) для ТГ7 и ТГ8: norm=545, fact=из parameter_values, db3=(norm-fact)*0.02/100*E33/7
                    elseif ($paramId == 15) {
                        if ($blockId == 7 || $blockId == 8) {
                            // Для ТГ7 и ТГ8: D20/G20=545 (норма), E20/H20=факт, F20/I20=(norm-fact)*0.02/100*E33/7
                            $norm = 545; // D20/G20
                            $fact = $calculatedValue; // E20/H20
                            
                            // Получаем E31/F31 (param_id = 38, row_num = 31, "Исходно-нормативное значение удельного расхода тепла брутто") для расчета db3
                            // В формуле указано E33/F33, но в базе данных это E31/F31
                            $db = getDbConnection();
                            $cell31 = $blockId == 7 ? 'E31' : 'F31';
                            $stmt = $db->prepare('
                                SELECT value FROM tg_result_values 
                                WHERE param_id = 38 AND tg_id = ? AND date = ? AND cell = ?
                                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                            );
                            
                            if ($shiftId) {
                                $stmt->execute([$blockId, $date, $cell31, $shiftId]);
                            } else {
                                $stmt->execute([$blockId, $date, $cell31]);
                            }
                            
                            $result31 = $stmt->fetch(PDO::FETCH_ASSOC);
                            $e31 = $result31 ? (float)$result31['value'] : 0;
                            
                            // Расчет db3: F20/I20=(D20-E20)*0.02/100*E31/7
                            $db3 = null;
                            if ($fact !== null) {
                                if ($e31 > 0) {
                                    $db3 = ($norm - $fact) * 0.02 / 100 * $e31 / 7;
                                } else {
                                    $db3 = 0; // Если E31 = 0, то db3 = 0
                                }
                            }
                            
                            $values[] = [
                                'param_id' => $paramId,
                                'block_id' => $blockId,
                                'shift_id' => $shiftId,
                                'value' => $fact,
                                'norm' => $norm,
                                'fact' => $fact,
                                'db3' => $db3
                            ];
                            error_log("URT: Добавлено значение для param_id=$paramId (температура пара промперегрева), blockId=$blockId, norm=$norm, fact=$fact, db3=" . ($db3 ?? 'NULL') . ", E31=$e31");
                        } elseif ($calculatedValue !== null) {
                            // Для остальных блоков сохраняем только fact
                            $values[] = [
                                'param_id' => $paramId,
                                'block_id' => $blockId,
                                'shift_id' => $shiftId,
                                'value' => $calculatedValue,
                                'norm' => null,
                                'fact' => $calculatedValue,
                                'db3' => null
                            ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура пара промперегрева), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 18 (Температура уходящих газов) для ТГ7 и ТГ8: norm=0.2523*E10+99.234, fact=из parameter_values, db3=IFERROR((fact-norm)*0.048/100*E21*1000/E10/7/(E32/100),0)
                elseif ($paramId == 18) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D23/G23=норма (0.2523*E10/H10+99.234), E23/H23=факт, F23/I23=dbэ
                        $fact = $calculatedValue; // E23/H23
                        
                        // Получаем E10/H10 (средняя электрическая нагрузка, param_id = 6) для расчета нормы и db3
                        $load = calculateUrtParameterValue(6, $blockId, $date, $shiftId, $rowNum);
                        
                        // Расчет нормы: D23/G23 = 0.2523*E10/H10+99.234
                        $norm = null;
                        if ($load !== null) {
                            $norm = 0.2523 * $load + 99.234;
                        }
                        
                        // Получаем E21/F21 (param_id = 33, row_num = 21, "Средняя температура охлаждающей воды на выходе из конденсатора") для расчета db3
                        $db = getDbConnection();
                        $cell21 = $blockId == 7 ? 'E21' : 'F21';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 33 AND tg_id = ? AND date = ? AND cell = ?
                            ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                        );
                        
                        if ($shiftId) {
                            $stmt->execute([$blockId, $date, $cell21, $shiftId]);
                        } else {
                            $stmt->execute([$blockId, $date, $cell21]);
                        }
                        
                        $result21 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e21 = $result21 ? (float)$result21['value'] : 0;
                        
                        // Получаем E32/F32 (param_id = 288, row_num = 32, category = '3b', "Исходно-нормативное значение") для расчета db3
                        $cell32_tg = $blockId == 7 ? 'E32' : 'F32';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 288 AND tg_id = ? AND date = ? AND cell = ?
                            ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                        );
                        
                        if ($shiftId) {
                            $stmt->execute([$blockId, $date, $cell32_tg, $shiftId]);
                        } else {
                            $stmt->execute([$blockId, $date, $cell32_tg]);
                        }
                        
                        $result32 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e32 = $result32 ? (float)$result32['value'] : 0;
                        
                        // Расчет db3: F23/I23 = IFERROR((E23-D23)*0.048/100*E21*1000/E10/7/(E32/100),0)
                        $db3 = null;
                        if ($fact !== null && $norm !== null && $load !== null && $load > 0 && $e21 > 0 && $e32 > 0) {
                            $db3 = ($fact - $norm) * 0.048 / 100 * $e21 * 1000 / $load / 7 / ($e32 / 100);
                        } else {
                            $db3 = 0; // IFERROR возвращает 0 при ошибке
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура уходящих газов), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", norm=$norm, fact=$fact, db3=$db3, load=$load, E21=$e21, E32=$e32");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура уходящих газов), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", fact=$calculatedValue");
                    }
                }
                // Для param_id = 18 (Температура уходящих газов) для ТГ7 и ТГ8: norm=0.2523*E10+99.234, fact=из parameter_values, db3=IFERROR((fact-norm)*0.048/100*E21*1000/E10/7/(E32/100),0)
                elseif ($paramId == 18) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D23/G23=норма (0.2523*E10/H10+99.234), E23/H23=факт, F23/I23=dbэ
                        $fact = $calculatedValue; // E23/H23
                        
                        // Получаем E10/H10 (средняя электрическая нагрузка, param_id = 6) для расчета нормы и db3
                        $load = calculateUrtParameterValue(6, $blockId, $date, null, $rowNum);
                        
                        // Расчет нормы: D23/G23 = 0.2523*E10/H10+99.234
                        $norm = null;
                        if ($load !== null) {
                            $norm = 0.2523 * $load + 99.234;
                        }
                        
                        // Получаем E21/F21 (param_id = 33, row_num = 21, "Средняя температура охлаждающей воды на выходе из конденсатора") для расчета db3
                        $db = getDbConnection();
                        $cell21 = $blockId == 7 ? 'E21' : 'F21';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 33 AND tg_id = ? AND date = ? AND cell = ?
                            AND shift_id IS NULL
                        ');
                        $stmt->execute([$blockId, $date, $cell21]);
                        
                        $result21 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e21 = $result21 ? (float)$result21['value'] : 0;
                        
                        // Получаем E32/F32 (param_id = 288, row_num = 32, category = '3b', "Исходно-нормативное значение") для расчета db3
                        $cell32_tg = $blockId == 7 ? 'E32' : 'F32';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 288 AND tg_id = ? AND date = ? AND cell = ?
                            AND shift_id IS NULL
                        ');
                        $stmt->execute([$blockId, $date, $cell32_tg]);
                        
                        $result32 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e32 = $result32 ? (float)$result32['value'] : 0;
                        
                        // Расчет db3: F23/I23 = IFERROR((E23-D23)*0.048/100*E21*1000/E10/7/(E32/100),0)
                        $db3 = null;
                        if ($fact !== null && $norm !== null && $load !== null && $load > 0 && $e21 > 0 && $e32 > 0) {
                            $db3 = ($fact - $norm) * 0.048 / 100 * $e21 * 1000 / $load / 7 / ($e32 / 100);
                        } else {
                            $db3 = 0; // IFERROR возвращает 0 при ошибке
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => null,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура уходящих газов), blockId=$blockId, norm=$norm, fact=$fact, db3=$db3, load=$load, E21=$e21, E32=$e32");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => null,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура уходящих газов), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 20 (Пуски) для ТГ7 и ТГ8: norm=fact=C13/D13*50, db3=IFERROR(E27*1000/E7*7,0)
                elseif ($paramId == 20) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D27/G27=норма (C13/D13*50), E27/H27=факт (C13/D13*50), F27/I27=dbэ
                        $fact = $calculatedValue; // E27/H27 = C13/D13*50
                        $norm = $fact; // D27/G27 = E27/H27 = C13/D13*50
                        
                        // Получаем E7/H7 (выработка электроэнергии, param_id = 1) для расчета db3
                        $generation = calculateUrtParameterValue(1, $blockId, $date, $shiftId, $rowNum);
                        
                        // Расчет db3: F27/I27 = IFERROR(E27*1000/E7*7,0) или H27*1000/H7*7
                        $db3 = null;
                        if ($fact !== null && $generation !== null && $generation > 0) {
                            $db3 = $fact * 1000 / $generation * 7;
                        } else {
                            $db3 = 0; // IFERROR возвращает 0 при ошибке
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (пуски), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", norm=$norm, fact=$fact, db3=$db3, выработка=$generation");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (пуски), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", fact=$calculatedValue");
                    }
                }
                // Для param_id = 19 (Избыток воздуха в уходящих газах) для ТГ7 и ТГ8: norm=-0.00072*E10+1.41415, fact=из parameter_values, db3=IFERROR((fact-norm)*0.052/100*E21*1000/E10/7/(E32/100),0)
                elseif ($paramId == 19) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D24/G24=норма (-0.00072*E10/H10+1.41415), E24/H24=факт, F24/I24=dbэ
                        $fact = $calculatedValue; // E24/H24
                        
                        // Получаем E10/H10 (средняя электрическая нагрузка, param_id = 6) для расчета нормы и db3
                        $load = calculateUrtParameterValue(6, $blockId, $date, null, $rowNum);
                        
                        // Расчет нормы: D24/G24 = -0.00072*E10/H10+1.41415
                        $norm = null;
                        if ($load !== null) {
                            $norm = -0.00072 * $load + 1.41415;
                        }
                        
                        // Получаем E21/F21 (param_id = 33, row_num = 21, "Средняя температура охлаждающей воды на выходе из конденсатора") для расчета db3
                        $db = getDbConnection();
                        $cell21 = $blockId == 7 ? 'E21' : 'F21';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 33 AND tg_id = ? AND date = ? AND cell = ?
                            AND shift_id IS NULL
                        ');
                        $stmt->execute([$blockId, $date, $cell21]);
                        
                        $result21 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e21 = $result21 ? (float)$result21['value'] : 0;
                        
                        // Получаем E32/F32 (param_id = 288, row_num = 32, category = '3b', "Исходно-нормативное значение") для расчета db3
                        $cell32_tg = $blockId == 7 ? 'E32' : 'F32';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 288 AND tg_id = ? AND date = ? AND cell = ?
                            AND shift_id IS NULL
                        ');
                        $stmt->execute([$blockId, $date, $cell32_tg]);
                        
                        $result32 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e32 = $result32 ? (float)$result32['value'] : 0;
                        
                        // Расчет db3: F24/I24 = IFERROR((E24-D24)*0.052/100*E21*1000/E10/7/(E32/100),0)
                        $db3 = null;
                        if ($fact !== null && $norm !== null && $load !== null && $load > 0 && $e21 > 0 && $e32 > 0) {
                            $db3 = ($fact - $norm) * 0.052 / 100 * $e21 * 1000 / $load / 7 / ($e32 / 100);
                        } else {
                            $db3 = 0; // IFERROR возвращает 0 при ошибке
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => null,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (избыток воздуха в уходящих газах), blockId=$blockId, norm=$norm, fact=$fact, db3=$db3, load=$load, E21=$e21, E32=$e32");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => null,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (избыток воздуха в уходящих газах), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 16 (Температура питательной воды) для ТГ7 и ТГ8: norm=формула с E31/F31, fact=из parameter_values, db3=(norm-fact)*0.03/100*E31/7
                elseif ($paramId == 16) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D21/G21=норма (формула), E21/H21=факт, F21/I21=(norm-fact)*0.03/100*E31/7
                        $fact = $calculatedValue; // E21/H21
                        
                        // Получаем E29/F29 (param_id = 36, row_num = 29, "Исходно-нормативный расход свежего пара") для расчета нормы и db3
                        // В формуле указано E31/F31, но в базе данных это E29/F29
                        $db = getDbConnection();
                        $cell29 = $blockId == 7 ? 'E29' : 'F29';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 36 AND tg_id = ? AND date = ? AND cell = ?
                            ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                        );
                        
                        if ($shiftId) {
                            $stmt->execute([$blockId, $date, $cell29, $shiftId]);
                        } else {
                            $stmt->execute([$blockId, $date, $cell29]);
                        }
                        
                        $result29 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e29 = $result29 ? (float)$result29['value'] : 0;
                        
                        // Расчет нормы: D21/G21=ROUND(-5.71314058251619E-07*E29^3+0.000703529060743331*E29^2-0.167071426973362*E29+210.960627074105,1)
                        $norm = null;
                        if ($e29 > 0) {
                            $norm = round(-5.71314058251619E-07 * pow($e29, 3) + 0.000703529060743331 * pow($e29, 2) - 0.167071426973362 * $e29 + 210.960627074105, 1);
                        }
                        
                        // Расчет db3: F21/I21=(norm-fact)*0.03/100*E29/7
                        // В формуле указано E33/F33, но в базе данных это E29/F29
                        $db3 = null;
                        if ($fact !== null && $norm !== null) {
                            if ($e29 > 0) {
                                $db3 = ($norm - $fact) * 0.03 / 100 * $e29 / 7;
                            } else {
                                $db3 = 0; // Если E29 = 0, то db3 = 0
                            }
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура питательной воды), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", norm=$norm, fact=$fact, db3=" . ($db3 ?? 'NULL') . ", E31=$e31, E33=$e33");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура питательной воды), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", fact=$calculatedValue");
                    }
                }
                // Для param_id = 27 (Расход газа) для всех блоков
                elseif ($paramId == 27) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (расход газа), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 28 (Расход мазута) для всех блоков
                elseif ($paramId == 28) {
                    if ($calculatedValue !== null || $calculatedValue === 0) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue ?? 0,
                            'norm' => null,
                            'fact' => $calculatedValue ?? 0,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (расход мазута), blockId=$blockId, fact=" . ($calculatedValue ?? 0));
                    }
                }
                // Для param_id = 29 (Калорийность газа) только для "по Станции"
                elseif ($paramId == 29 && $blockId == 5) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (калорийность газа), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 30 (Калорийность мазута) только для "по Станции"
                elseif ($paramId == 30 && $blockId == 5) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (калорийность мазута), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 31 (Расход топлива на электроэнергию) для всех блоков
                elseif ($paramId == 31) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (расход топлива на электроэнергию), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для ТГ7, ТГ8, ПГУ1 и ПГУ2 рассчитываем norm, fact, db3
                // Для param_id = 1,2,3,4,5,6 (основные энергопоказатели) и param_id = 7,8 (темп. воды вход/выход, только ТГ7/ТГ8) db3 всегда null
                elseif (($paramId == 1 || $paramId == 2 || $paramId == 3 || $paramId == 4 || $paramId == 5 || $paramId == 6
                    || ($paramId == 7 && ($blockId == 7 || $blockId == 8))
                    || ($paramId == 8 && ($blockId == 7 || $blockId == 8)))
                    && ($blockId == 7 || $blockId == 8 || $blockId == 1 || $blockId == 2)) {
                        error_log("URT: param_id=$paramId, blockId=$blockId, shiftId=$shiftId, calculatedValue=" . ($calculatedValue ?? 'NULL'));
                        if ($calculatedValue !== null) {
                            // Для этих параметров norm и db3 всегда null
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
                                'fact' => ($paramId == 1 || $paramId == 2 || $paramId == 3 || $paramId == 4 || $paramId == 5 || $paramId == 7 || $paramId == 8 || $paramId == 20 || $paramId == 27 || $paramId == 28 || $paramId == 29 || $paramId == 30 || $paramId == 31 || $paramId == 32 || $paramId == 33 || $paramId == 34 || $paramId == 35 || $paramId == 36 || $paramId == 37 || $paramId == 38 ? $calculatedValue : null),
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
                
                // Для param_id = 11 (Расход на с.н. норма) сохраняем в norm
                if ($paramId == 11) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => $calculatedValue,
                            'fact' => null,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение в массив values для param_id=$paramId (норма), blockId=$blockId, norm=$calculatedValue");
                    }
                }
                // Для param_id = 12 (Расход на с.н. факт) сохраняем в fact
                elseif ($paramId == 12) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение в массив values для param_id=$paramId (факт), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 13 (Температура острого пара) для ТГ7 и ТГ8: norm=540, fact=из parameter_values, db3=(norm-fact)*0.028/100*E33/7
                elseif ($paramId == 13) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D18/G18=540 (норма), E18/H18=факт, F18/I18=(norm-fact)*0.028/100*E33/7
                        $norm = 540; // D18/G18
                        $fact = $calculatedValue; // E18/H18
                        
                        // Получаем E31/F31 (param_id = 38, row_num = 31, "Исходно-нормативное значение удельного расхода тепла брутто") для расчета db3
                        // В формуле указано E33/F33, но в базе данных это E31/F31
                        $db = getDbConnection();
                        $cell31 = $blockId == 7 ? 'E31' : 'F31';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 38 AND tg_id = ? AND date = ? AND cell = ?
                            AND shift_id IS NULL
                        ');
                        $stmt->execute([$blockId, $date, $cell31]);
                        
                        $result31 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e31 = $result31 ? (float)$result31['value'] : 0;
                        
                        // Расчет db3: F18/I18=(D18-E18)*0.028/100*E31/7
                        $db3 = null;
                        if ($fact !== null) {
                            if ($e31 > 0) {
                                $db3 = ($norm - $fact) * 0.028 / 100 * $e31 / 7;
                            } else {
                                $db3 = 0; // Если E31 = 0, то db3 = 0
                            }
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура острого пара), blockId=$blockId, norm=$norm, fact=$fact, db3=" . ($db3 ?? 'NULL') . ", E31=$e31");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура острого пара), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 14 (Давление острого пара) для ТГ7 и ТГ8: norm=130, fact=из parameter_values, db3=IF(нагрузка<=150,0,(norm-fact)*0.05/100*E33/7)
                elseif ($paramId == 14) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D19/G19=130 (норма), E19/H19=факт, F19/I19=IF(E10/H10<=150,0,(norm-fact)*0.05/100*E33/7)
                        $norm = 130; // D19/G19
                        $fact = $calculatedValue; // E19/H19
                        
                        // Получаем E33/F33 (param_id = 39, row_num = 33) для расчета db3
                        $db = getDbConnection();
                        $cell33 = $blockId == 7 ? 'E33' : 'F33';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 39 AND tg_id = ? AND date = ? AND cell = ?
                            AND shift_id IS NULL
                        ');
                        $stmt->execute([$blockId, $date, $cell33]);
                        
                        $result33 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e33 = $result33 ? (float)$result33['value'] : 0;
                        
                        // Получаем E10/H10 (средняя электрическая нагрузка, param_id = 6) для проверки условия
                        $load = calculateUrtParameterValue(6, $blockId, $date, null, $rowNum);
                        
                        // Расчет db3: F19/I19=IF(E10/H10<=150,0,(D19-E19)*0.05/100*E33/7)
                        $db3 = null;
                        if ($fact !== null && $load !== null && $load > 150 && $e33 > 0) {
                            $db3 = ($norm - $fact) * 0.05 / 100 * $e33 / 7;
                        } else {
                            $db3 = 0; // Если нагрузка <= 150, то db3 = 0
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (давление острого пара), blockId=$blockId, norm=$norm, fact=$fact, db3=$db3, нагрузка=$load, E31=$e31");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (давление острого пара), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 15 (Температура пара промперегрева) для ТГ7 и ТГ8: norm=545, fact=из parameter_values, db3=(norm-fact)*0.02/100*E33/7
                elseif ($paramId == 15) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D20/G20=545 (норма), E20/H20=факт, F20/I20=(norm-fact)*0.02/100*E33/7
                        $norm = 545; // D20/G20
                        $fact = $calculatedValue; // E20/H20
                        
                        // Получаем E31/F31 (param_id = 38, row_num = 31, "Исходно-нормативное значение удельного расхода тепла брутто") для расчета db3
                        // В формуле указано E33/F33, но в базе данных это E31/F31
                        $db = getDbConnection();
                        $cell31 = $blockId == 7 ? 'E31' : 'F31';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 38 AND tg_id = ? AND date = ? AND cell = ?
                            ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                        );
                        
                        if ($shiftId) {
                            $stmt->execute([$blockId, $date, $cell31, $shiftId]);
                        } else {
                            $stmt->execute([$blockId, $date, $cell31]);
                        }
                        
                        $result31 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e31 = $result31 ? (float)$result31['value'] : 0;
                        
                        // Расчет db3: F20/I20=(D20-E20)*0.02/100*E31/7
                        $db3 = null;
                        if ($fact !== null) {
                            if ($e31 > 0) {
                                $db3 = ($norm - $fact) * 0.02 / 100 * $e31 / 7;
                            } else {
                                $db3 = 0; // Если E31 = 0, то db3 = 0
                            }
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура пара промперегрева), blockId=$blockId, norm=$norm, fact=$fact, db3=" . ($db3 ?? 'NULL') . ", E31=$e31");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура пара промперегрева), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 18 (Температура уходящих газов) для ТГ7 и ТГ8: norm=0.2523*E10+99.234, fact=из parameter_values, db3=IFERROR((fact-norm)*0.048/100*E21*1000/E10/7/(E32/100),0)
                elseif ($paramId == 18) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D23/G23=норма (0.2523*E10/H10+99.234), E23/H23=факт, F23/I23=dbэ
                        $fact = $calculatedValue; // E23/H23
                        
                        // Получаем E10/H10 (средняя электрическая нагрузка, param_id = 6) для расчета нормы и db3
                        $load = calculateUrtParameterValue(6, $blockId, $date, $shiftId, $rowNum);
                        
                        // Расчет нормы: D23/G23 = 0.2523*E10/H10+99.234
                        $norm = null;
                        if ($load !== null) {
                            $norm = 0.2523 * $load + 99.234;
                        }
                        
                        // Получаем E21/F21 (param_id = 33, row_num = 21, "Средняя температура охлаждающей воды на выходе из конденсатора") для расчета db3
                        $db = getDbConnection();
                        $cell21 = $blockId == 7 ? 'E21' : 'F21';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 33 AND tg_id = ? AND date = ? AND cell = ?
                            ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                        );
                        
                        if ($shiftId) {
                            $stmt->execute([$blockId, $date, $cell21, $shiftId]);
                        } else {
                            $stmt->execute([$blockId, $date, $cell21]);
                        }
                        
                        $result21 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e21 = $result21 ? (float)$result21['value'] : 0;
                        
                        // Получаем E32/F32 (param_id = 288, row_num = 32, category = '3b', "Исходно-нормативное значение") для расчета db3
                        $cell32_tg = $blockId == 7 ? 'E32' : 'F32';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 288 AND tg_id = ? AND date = ? AND cell = ?
                            ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                        );
                        
                        if ($shiftId) {
                            $stmt->execute([$blockId, $date, $cell32_tg, $shiftId]);
                        } else {
                            $stmt->execute([$blockId, $date, $cell32_tg]);
                        }
                        
                        $result32 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e32 = $result32 ? (float)$result32['value'] : 0;
                        
                        // Расчет db3: F23/I23 = IFERROR((E23-D23)*0.048/100*E21*1000/E10/7/(E32/100),0)
                        $db3 = null;
                        if ($fact !== null && $norm !== null && $load !== null && $load > 0 && $e21 > 0 && $e32 > 0) {
                            $db3 = ($fact - $norm) * 0.048 / 100 * $e21 * 1000 / $load / 7 / ($e32 / 100);
                        } else {
                            $db3 = 0; // IFERROR возвращает 0 при ошибке
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура уходящих газов), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", norm=$norm, fact=$fact, db3=$db3, load=$load, E21=$e21, E32=$e32");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура уходящих газов), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", fact=$calculatedValue");
                    }
                }
                // Для param_id = 18 (Температура уходящих газов) для ТГ7 и ТГ8: norm=0.2523*E10+99.234, fact=из parameter_values, db3=IFERROR((fact-norm)*0.048/100*E21*1000/E10/7/(E32/100),0)
                elseif ($paramId == 18) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D23/G23=норма (0.2523*E10/H10+99.234), E23/H23=факт, F23/I23=dbэ
                        $fact = $calculatedValue; // E23/H23
                        
                        // Получаем E10/H10 (средняя электрическая нагрузка, param_id = 6) для расчета нормы и db3
                        $load = calculateUrtParameterValue(6, $blockId, $date, null, $rowNum);
                        
                        // Расчет нормы: D23/G23 = 0.2523*E10/H10+99.234
                        $norm = null;
                        if ($load !== null) {
                            $norm = 0.2523 * $load + 99.234;
                        }
                        
                        // Получаем E21/F21 (param_id = 33, row_num = 21, "Средняя температура охлаждающей воды на выходе из конденсатора") для расчета db3
                        $db = getDbConnection();
                        $cell21 = $blockId == 7 ? 'E21' : 'F21';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 33 AND tg_id = ? AND date = ? AND cell = ?
                            AND shift_id IS NULL
                        ');
                        $stmt->execute([$blockId, $date, $cell21]);
                        
                        $result21 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e21 = $result21 ? (float)$result21['value'] : 0;
                        
                        // Получаем E32/F32 (param_id = 288, row_num = 32, category = '3b', "Исходно-нормативное значение") для расчета db3
                        $cell32_tg = $blockId == 7 ? 'E32' : 'F32';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 288 AND tg_id = ? AND date = ? AND cell = ?
                            AND shift_id IS NULL
                        ');
                        $stmt->execute([$blockId, $date, $cell32_tg]);
                        
                        $result32 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e32 = $result32 ? (float)$result32['value'] : 0;
                        
                        // Расчет db3: F23/I23 = IFERROR((E23-D23)*0.048/100*E21*1000/E10/7/(E32/100),0)
                        $db3 = null;
                        if ($fact !== null && $norm !== null && $load !== null && $load > 0 && $e21 > 0 && $e32 > 0) {
                            $db3 = ($fact - $norm) * 0.048 / 100 * $e21 * 1000 / $load / 7 / ($e32 / 100);
                        } else {
                            $db3 = 0; // IFERROR возвращает 0 при ошибке
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => null,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура уходящих газов), blockId=$blockId, norm=$norm, fact=$fact, db3=$db3, load=$load, E21=$e21, E32=$e32");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => null,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура уходящих газов), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 20 (Пуски) для ТГ7 и ТГ8: norm=fact=C13/D13*50, db3=IFERROR(E27*1000/E7*7,0)
                elseif ($paramId == 20) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D27/G27=норма (C13/D13*50), E27/H27=факт (C13/D13*50), F27/I27=dbэ
                        $fact = $calculatedValue; // E27/H27 = C13/D13*50
                        $norm = $fact; // D27/G27 = E27/H27 = C13/D13*50
                        
                        // Получаем E7/H7 (выработка электроэнергии, param_id = 1) для расчета db3
                        $generation = calculateUrtParameterValue(1, $blockId, $date, $shiftId, $rowNum);
                        
                        // Расчет db3: F27/I27 = IFERROR(E27*1000/E7*7,0) или H27*1000/H7*7
                        $db3 = null;
                        if ($fact !== null && $generation !== null && $generation > 0) {
                            $db3 = $fact * 1000 / $generation * 7;
                        } else {
                            $db3 = 0; // IFERROR возвращает 0 при ошибке
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (пуски), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", norm=$norm, fact=$fact, db3=$db3, выработка=$generation");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (пуски), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", fact=$calculatedValue");
                    }
                }
                // Для param_id = 19 (Избыток воздуха в уходящих газах) для ТГ7 и ТГ8: norm=-0.00072*E10+1.41415, fact=из parameter_values, db3=IFERROR((fact-norm)*0.052/100*E21*1000/E10/7/(E32/100),0)
                elseif ($paramId == 19) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D24/G24=норма (-0.00072*E10/H10+1.41415), E24/H24=факт, F24/I24=dbэ
                        $fact = $calculatedValue; // E24/H24
                        
                        // Получаем E10/H10 (средняя электрическая нагрузка, param_id = 6) для расчета нормы и db3
                        $load = calculateUrtParameterValue(6, $blockId, $date, null, $rowNum);
                        
                        // Расчет нормы: D24/G24 = -0.00072*E10/H10+1.41415
                        $norm = null;
                        if ($load !== null) {
                            $norm = -0.00072 * $load + 1.41415;
                        }
                        
                        // Получаем E21/F21 (param_id = 33, row_num = 21, "Средняя температура охлаждающей воды на выходе из конденсатора") для расчета db3
                        $db = getDbConnection();
                        $cell21 = $blockId == 7 ? 'E21' : 'F21';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 33 AND tg_id = ? AND date = ? AND cell = ?
                            AND shift_id IS NULL
                        ');
                        $stmt->execute([$blockId, $date, $cell21]);
                        
                        $result21 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e21 = $result21 ? (float)$result21['value'] : 0;
                        
                        // Получаем E32/F32 (param_id = 288, row_num = 32, category = '3b', "Исходно-нормативное значение") для расчета db3
                        $cell32_tg = $blockId == 7 ? 'E32' : 'F32';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 288 AND tg_id = ? AND date = ? AND cell = ?
                            AND shift_id IS NULL
                        ');
                        $stmt->execute([$blockId, $date, $cell32_tg]);
                        
                        $result32 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e32 = $result32 ? (float)$result32['value'] : 0;
                        
                        // Расчет db3: F24/I24 = IFERROR((E24-D24)*0.052/100*E21*1000/E10/7/(E32/100),0)
                        $db3 = null;
                        if ($fact !== null && $norm !== null && $load !== null && $load > 0 && $e21 > 0 && $e32 > 0) {
                            $db3 = ($fact - $norm) * 0.052 / 100 * $e21 * 1000 / $load / 7 / ($e32 / 100);
                        } else {
                            $db3 = 0; // IFERROR возвращает 0 при ошибке
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => null,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (избыток воздуха в уходящих газах), blockId=$blockId, norm=$norm, fact=$fact, db3=$db3, load=$load, E21=$e21, E32=$e32");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => null,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (избыток воздуха в уходящих газах), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 16 (Температура питательной воды) для ТГ7 и ТГ8: norm=формула с E31/F31, fact=из parameter_values, db3=(norm-fact)*0.03/100*E31/7
                elseif ($paramId == 16) {
                    if ($blockId == 7 || $blockId == 8) {
                        // Для ТГ7 и ТГ8: D21/G21=норма (формула), E21/H21=факт, F21/I21=(norm-fact)*0.03/100*E31/7
                        $fact = $calculatedValue; // E21/H21
                        
                        // Получаем E31/F31 (param_id = 38, row_num = 31, "Исходно-нормативное значение удельного расхода тепла брутто") для расчета нормы
                        $db = getDbConnection();
                        $cell31 = $blockId == 7 ? 'E31' : 'F31';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 38 AND tg_id = ? AND date = ? AND cell = ?
                            ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                        );
                        
                        if ($shiftId) {
                            $stmt->execute([$blockId, $date, $cell31, $shiftId]);
                        } else {
                            $stmt->execute([$blockId, $date, $cell31]);
                        }
                        
                        $result31 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e31 = $result31 ? (float)$result31['value'] : 0;
                        
                        // Расчет нормы: D21/G21=ROUND(-5.71314058251619E-07*E31^3+0.000703529060743331*E31^2-0.167071426973362*E31+210.960627074105,1)
                        $norm = null;
                        if ($e31 > 0) {
                            $norm = round(-5.71314058251619E-07 * pow($e31, 3) + 0.000703529060743331 * pow($e31, 2) - 0.167071426973362 * $e31 + 210.960627074105, 1);
                        }
                        
                        // Получаем E33/F33 (param_id = 38, row_num = 33) для расчета db3
                        $cell33 = $blockId == 7 ? 'E33' : 'F33';
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 38 AND tg_id = ? AND date = ? AND cell = ?
                            ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                        );
                        
                        if ($shiftId) {
                            $stmt->execute([$blockId, $date, $cell33, $shiftId]);
                        } else {
                            $stmt->execute([$blockId, $date, $cell33]);
                        }
                        
                        $result33 = $stmt->fetch(PDO::FETCH_ASSOC);
                        $e33 = $result33 ? (float)$result33['value'] : 0;
                        
                        // Расчет db3: F21/I21=(norm-fact)*0.03/100*E33/7
                        $db3 = null;
                        if ($fact !== null && $norm !== null) {
                            if ($e33 > 0) {
                                $db3 = ($norm - $fact) * 0.03 / 100 * $e33 / 7;
                            } else {
                                $db3 = 0; // Если E33 = 0, то db3 = 0
                            }
                        }
                        
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $fact,
                            'norm' => $norm,
                            'fact' => $fact,
                            'db3' => $db3
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура питательной воды), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", norm=$norm, fact=$fact, db3=" . ($db3 ?? 'NULL') . ", E31=$e31, E33=$e33");
                    } elseif ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем только fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (температура питательной воды), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для ТГ7, ТГ8, ПГУ1 и ПГУ2 рассчитываем norm, fact, db3
                // Для param_id = 1,2,3,4,5,6 (основные энергопоказатели) и param_id = 7,8 (темп. воды вход/выход, только ТГ7/ТГ8) db3 всегда null
                elseif (($paramId == 1 || $paramId == 2 || $paramId == 3 || $paramId == 4 || $paramId == 5 || $paramId == 6
                    || ($paramId == 7 && ($blockId == 7 || $blockId == 8))
                    || ($paramId == 8 && ($blockId == 7 || $blockId == 8)))
                    && ($blockId == 7 || $blockId == 8 || $blockId == 1 || $blockId == 2)) {
                    if ($calculatedValue !== null) {
                        // Для этих параметров norm и db3 всегда null
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                    }
                }
                // Для param_id = 27 (Расход газа) для всех блоков
                elseif ($paramId == 27) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (расход газа), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 28 (Расход мазута) для всех блоков
                elseif ($paramId == 28) {
                    if ($calculatedValue !== null || $calculatedValue === 0) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue ?? 0,
                            'norm' => null,
                            'fact' => $calculatedValue ?? 0,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (расход мазута), blockId=$blockId, fact=" . ($calculatedValue ?? 0));
                    }
                }
                // Для param_id = 29 (Калорийность газа) только для "по Станции"
                elseif ($paramId == 29 && $blockId == 5) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (калорийность газа), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 30 (Калорийность мазута) только для "по Станции"
                elseif ($paramId == 30 && $blockId == 5) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (калорийность мазута), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 31 (Расход топлива на электроэнергию) для всех блоков
                elseif ($paramId == 31) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (расход топлива на электроэнергию), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 32 (Расход топлива на тепло) для ПГУ1, ПГУ2, "по ПГУ" и "по Станции"
                elseif ($paramId == 32 && ($blockId == 1 || $blockId == 2 || $blockId == 3 || $blockId == 5)) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (расход топлива на тепло), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 33 (Доля газа в балансе топлива) для ТГ7, ТГ8 и "по Блокам"
                elseif ($paramId == 33 && ($blockId == 7 || $blockId == 8 || $blockId == 9)) {
                    if ($calculatedValue !== null || $calculatedValue === 0) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue ?? 0,
                            'norm' => null,
                            'fact' => $calculatedValue ?? 0,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (доля газа в балансе топлива), blockId=$blockId, fact=" . ($calculatedValue ?? 0));
                    }
                }
                // Для param_id = 34 (Номинальный УРТ) для всех блоков - сохраняем в fact
                elseif ($paramId == 34) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (номинальный УРТ), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 35 (Нормативное значение) для всех блоков - сохраняем в fact
                elseif ($paramId == 35) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (нормативное значение), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 36 (Фактический УРТ) для всех блоков - сохраняем в fact
                elseif ($paramId == 36) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (фактический УРТ), blockId=$blockId, fact=$calculatedValue");
                    }
                }
                // Для param_id = 37 (Фактический УРТ с учётом ФЭС) для всех блоков - сохраняем в fact
                elseif ($paramId == 37) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (фактический УРТ с учётом ФЭС), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", fact=$calculatedValue");
                    }
                }
                // Для param_id = 38 (Экономия топлива) для всех блоков - сохраняем в fact
                elseif ($paramId == 38) {
                    if ($calculatedValue !== null) {
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'shift_id' => $shiftId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => $calculatedValue,
                            'db3' => null
                        ];
                        error_log("URT: Добавлено значение для param_id=$paramId (экономия топлива), blockId=$blockId, shiftId=" . ($shiftId ?? 'NULL') . ", fact=$calculatedValue");
                    }
                } else {
                    if ($calculatedValue !== null) {
                        // Для остальных блоков сохраняем значение в fact
                        $values[] = [
                            'param_id' => $paramId,
                            'block_id' => $blockId,
                            'value' => $calculatedValue,
                            'norm' => null,
                            'fact' => ($paramId == 1 || $paramId == 2 || $paramId == 3 || $paramId == 4 || $paramId == 5 || $paramId == 6 || $paramId == 7 || $paramId == 8 || $paramId == 16 || $paramId == 17 || $paramId == 20 || $paramId == 27 || $paramId == 28 || $paramId == 29 || $paramId == 30 || $paramId == 31 || $paramId == 32 || $paramId == 33 || $paramId == 34 || $paramId == 35 || $paramId == 36 || $paramId == 37 || $paramId == 38 ? $calculatedValue : null),
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
                elseif ($blockId == 4) {
                    // Для ФЭС (blockId == 4) берем значение из счетчиков выработки
                    // Ищем equipment_id для ФЭС по названию оборудования
                    $stmt = $db->prepare('
                        SELECT id FROM equipment 
                        WHERE name LIKE ? OR name LIKE ?
                        LIMIT 1
                    ');
                    $stmt->execute(['%ФЭС%', '%FES%']);
                    $equipment = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$equipment) {
                        // Если не нашли по названию, пробуем equipment_id = 6 (часто используется для ФЭС)
                        $equipmentId = 6;
                        error_log("URT calculateUrtParameterValue: Не найдено equipment для ФЭС по названию, используем equipment_id=6");
                    } else {
                        $equipmentId = $equipment['id'];
                        error_log("URT calculateUrtParameterValue: Найдено equipment для ФЭС, equipment_id=$equipmentId");
                    }
                    
                    // Получаем показания счетчиков выработки для ФЭС (meter_type_id = 1)
                    $stmt = $db->prepare('
                        SELECT mr.shift1, mr.shift2, mr.shift3, mr.total
                        FROM meters m
                        LEFT JOIN meter_readings mr ON m.id = mr.meter_id AND mr.date = ?
                        WHERE m.equipment_id = ? AND m.meter_type_id = 1 AND m.is_active = 1
                    ');
                    $stmt->execute([$date, $equipmentId]);
                    $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Суммируем выработку по всем счетчикам ФЭС
                    $totalGeneration = 0;
                    if (!empty($readings)) {
                        foreach ($readings as $reading) {
                            if ($shiftId !== null) {
                                // Для смены
                                $shiftField = 'shift' . $shiftId;
                                $totalGeneration += (float)($reading[$shiftField] ?? 0);
                            } else {
                                // Для суточного/периодного расчета
                                $totalGeneration += (float)($reading['total'] ?? 0);
                            }
                        }
                    }
                    
                    error_log("URT calculateUrtParameterValue: Выработка ФЭС для blockId=$blockId, equipment_id=$equipmentId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", найдено счетчиков=" . count($readings) . ", значение=$totalGeneration");
                    
                    return $totalGeneration > 0 ? $totalGeneration : null;
                }
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
                    // Для ТГ7 и ТГ8: отпуск = выработка - расход на с.н.
                    $generation = calculateUrtParameterValue(1, $blockId, $date, $shiftId, $rowNum);
                    $ownNeeds = calculateUrtParameterValue(2, $blockId, $date, $shiftId, $rowNum);
                    
                    if ($generation !== null && $ownNeeds !== null) {
                        $release = $generation - $ownNeeds;
                        error_log("URT calculateUrtParameterValue: Отпуск для blockId=$blockId: выработка=$generation, расход на с.н.=$ownNeeds, отпуск=$release");
                        return max(0, $release); // Не может быть отрицательным
                    } elseif ($generation !== null) {
                        // Если нет расхода на с.н., возвращаем выработку
                        return $generation;
                    }
                    return null;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2: отпуск = выработка - расход на с.н.
                    $generation = calculateUrtParameterValue(1, $blockId, $date, $shiftId, $rowNum);
                    $ownNeeds = calculateUrtParameterValue(2, $blockId, $date, $shiftId, $rowNum);
                    
                    if ($generation !== null && $ownNeeds !== null) {
                        $release = $generation - $ownNeeds;
                        error_log("URT calculateUrtParameterValue: Отпуск для ПГУ blockId=$blockId: выработка=$generation, расход на с.н.=$ownNeeds, отпуск=$release");
                        return max(0, $release); // Не может быть отрицательным
                    } elseif ($generation !== null) {
                        // Если нет расхода на с.н., возвращаем выработку
                        return $generation;
                    }
                    return null;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам" - сумма отпуска ТГ7 и ТГ8
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
                    // Для "по ПГУ" - сумма отпуска ПГУ1 и ПГУ2
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
                    // Для "по Станции" - сумма отпуска всех источников
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
                elseif ($blockId == 4) {
                    // Для ФЭС (blockId == 4): отпуск = выработка - расход на собственные нужды
                    // Выработка ФЭС
                    $generation = calculateUrtParameterValue(1, 4, $date, $shiftId, $rowNum);
                    // Расход на собственные нужды ФЭС (если есть)
                    $ownNeeds = calculateUrtParameterValue(2, 4, $date, $shiftId, $rowNum);
                    
                    if ($generation !== null) {
                        $release = $generation - ($ownNeeds ?? 0);
                        error_log("URT calculateUrtParameterValue: Отпуск ФЭС для blockId=$blockId, выработка=$generation, расход на с.н.=" . ($ownNeeds ?? 0) . ", отпуск=$release");
                        return $release >= 0 ? $release : null;
                    }
                    return null;
                }
                else {
                    return null;
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
                
            // Температура охлаждающей воды (вход)
            case 7:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8 берем значение из tg_result_values с param_id = 32 (Средняя температура охлаждающей воды на входе в конденсатор)
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 32 AND tg_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Температура охлаждающей воды (вход) для blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                // Для остальных блоков не рассчитывается
                else {
                    return null;
                }
                break;
                
            // Отпуск тепла
            case 4:
                if ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2 берем значение из pgu_fullparam_values с fullparam_id = 25 (row_num = 34, Тепловая нагрузка)
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_fullparam_values 
                        WHERE fullparam_id = 25 AND pgu_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Отпуск тепла для ПГУ blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 5) {
                    // Для "по Станции" - сумма отпуска тепла ПГУ1 и ПГУ2
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
                // Для остальных блоков отпуск тепла не рассчитывается
                else {
                    return null;
                }
                break;
            
            // Число часов работы (факт)
            case 5:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8 берем значение из tg_result_values с param_id = 28
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 28 AND tg_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Часы работы для ТГ blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2 берем значение из pgu_fullparam_values с row_num = 16 (τрабПГУ)
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_fullparam_values 
                        WHERE fullparam_id = (
                            SELECT id FROM pgu_fullparams WHERE row_num = 16 LIMIT 1
                        ) AND pgu_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Часы работы для ПГУ blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам" - сумма часов работы ТГ7 и ТГ8
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
                    // Для "по ПГУ" - сумма часов работы ПГУ1 и ПГУ2
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
                // Для остальных блоков (включая "по станции") часы работы не рассчитываются
                else {
                    return null;
                }
                break;

            // Средняя электрическая нагрузка
            case 6:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8 берем значение из tg_result_values с param_id = 35
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 35 AND tg_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Ср. эл. нагрузка (ТГ) blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2 берем значение из pgu_result_values с param_id = 38 (Электрическая нагрузка ПГУ, MW, брутто)
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_result_values 
                        WHERE param_id = 38 AND pgu_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Ср. эл. нагрузка (ПГУ) blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам" - сумма средних нагрузок ТГ7 и ТГ8
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
                    // Для "по ПГУ" - сумма нагрузок ПГУ1 и ПГУ2
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
                    // Для "по станции" - сумма нагрузок ТГ7, ТГ8, ПГУ1 и ПГУ2
                    $tg7Value = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tg8Value = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    $pgu1Value = calculateUrtParameterValue($paramId, 1, $date, $shiftId, $rowNum);
                    $pgu2Value = calculateUrtParameterValue($paramId, 2, $date, $shiftId, $rowNum);
                    
                    $sum = 0;
                    $hasValue = false;
                    foreach ([$tg7Value, $tg8Value, $pgu1Value, $pgu2Value] as $val) {
                        if ($val !== null) {
                            $sum += $val;
                            $hasValue = true;
                        }
                    }
                    return $hasValue ? $sum : null;
                }
                else {
                    return null;
                }
                break;
                
            // Температура охлаждающей воды (выход)
            case 8:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8 берем значение из tg_result_values с param_id = 33 (Средняя температура охлаждающей воды на выходе из конденсатора)
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 33 AND tg_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Температура охлаждающей воды (выход) для blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                // Для остальных блоков не рассчитывается
                else {
                    return null;
                }
                break;
                
            // Расход электроэнергии на с.н. (норма)
            case 11:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: F51 (param_id = 52, row_num = 51, category = '3a') + F45 (param_id = 48, row_num = 45, category = '3b')
                    // Получаем F51 (param_id = 52)
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 52 AND tg_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result51 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value51 = $result51 ? (float)$result51['value'] : 0;
                    
                    // Получаем F45 (param_id = 48)
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 48 AND tg_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result45 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value45 = $result45 ? (float)$result45['value'] : 0;
                    
                    $normValue = $value51 + $value45;
                    error_log("URT calculateUrtParameterValue: Расход на с.н. (норма) для blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", F51=$value51, F45=$value45, норма=" . $normValue);
                    return $normValue;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J15=(D15*E7+G15*H7)/J7
                    // D15 - норма для ТГ7, G15 - норма для ТГ8
                    // E7 - выработка ТГ7, H7 - выработка ТГ8, J7 - выработка "по Блокам"
                    $normTg7 = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $normTg8 = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    $genTg7 = calculateUrtParameterValue(1, 7, $date, $shiftId, $rowNum);
                    $genTg8 = calculateUrtParameterValue(1, 8, $date, $shiftId, $rowNum);
                    $genBlocks = calculateUrtParameterValue(1, 9, $date, $shiftId, $rowNum);
                    
                    if ($genBlocks == 0 || $genBlocks === null) {
                        return null;
                    }
                    
                    $result = ($normTg7 * $genTg7 + $normTg8 * $genTg8) / $genBlocks;
                    error_log("URT calculateUrtParameterValue: Расход на с.н. (норма) для 'по Блокам', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", норма_ТГ7=$normTg7, норма_ТГ8=$normTg8, выработка_ТГ7=$genTg7, выработка_ТГ8=$genTg8, выработка_по_блокам=$genBlocks, результат=$result");
                    return $result;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2: F78/G78 (fullparam_id = 68, row_num = 78)
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_fullparam_values 
                        WHERE fullparam_id = 68 AND pgu_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Расход на с.н. (норма) для ПГУ blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ": Q15='Норма ПГУ - 283 1(а) стр.'!H78
                    // Это агрегированное значение для обоих ПГУ, берем из pgu_fullparam_values для pgu_id = 3
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_fullparam_values 
                        WHERE fullparam_id = 68 AND pgu_id = 3 AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$date, $shiftId]);
                    } else {
                        $stmt->execute([$date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Расход на с.н. (норма) для 'по ПГУ', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 5) {
                    // Для "по станции": S15=(J15*J7+Q15*Q7)/S7
                    // J15 - норма "по Блокам", Q15 - норма "по ПГУ"
                    // J7 - выработка "по Блокам", Q7 - выработка "по ПГУ", S7 - выработка "по станции"
                    $normBlocks = calculateUrtParameterValue($paramId, 9, $date, $shiftId, $rowNum);
                    $normPgu = calculateUrtParameterValue($paramId, 3, $date, $shiftId, $rowNum);
                    
                    $genBlocks = calculateUrtParameterValue(1, 9, $date, $shiftId, $rowNum);
                    $genPgu = calculateUrtParameterValue(1, 3, $date, $shiftId, $rowNum);
                    $genStation = calculateUrtParameterValue(1, 5, $date, $shiftId, $rowNum);
                    
                    if ($genStation == 0 || $genStation === null) {
                        return null;
                    }
                    
                    $result = ($normBlocks * $genBlocks + $normPgu * $genPgu) / $genStation;
                    error_log("URT calculateUrtParameterValue: Расход на с.н. (норма) для 'по станции', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", норма_по_блокам=$normBlocks, норма_по_ПГУ=$normPgu, выработка_по_блокам=$genBlocks, выработка_по_ПГУ=$genPgu, выработка_по_станции=$genStation, результат=$result");
                    return $result;
                }
                else {
                    return null;
                }
                break;
                
            // Расход электроэнергии на с.н. (факт)
            case 12:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E16=IF(E7=0,0,E6/E7*100)
                    // E6 - расход на с.н. (param_id = 2), E7 - выработка (param_id = 1)
                    $ownNeeds = calculateUrtParameterValue(2, $blockId, $date, $shiftId, $rowNum);
                    $generation = calculateUrtParameterValue(1, $blockId, $date, $shiftId, $rowNum);
                    
                    if ($generation == 0 || $generation === null) {
                        return 0;
                    }
                    
                    if ($ownNeeds === null) {
                        return null;
                    }
                    
                    $result = ($ownNeeds / $generation) * 100;
                    error_log("URT calculateUrtParameterValue: Расход на с.н. (факт) для blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", расход_на_сн=$ownNeeds, выработка=$generation, процент=" . $result);
                    return $result;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J16=(E16*E7+H16*H7)/J7
                    // E16 - процент для ТГ7, H16 - процент для ТГ8
                    // E7 - выработка ТГ7, H7 - выработка ТГ8, J7 - выработка "по Блокам"
                    $factTg7 = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $factTg8 = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    $genTg7 = calculateUrtParameterValue(1, 7, $date, $shiftId, $rowNum);
                    $genTg8 = calculateUrtParameterValue(1, 8, $date, $shiftId, $rowNum);
                    $genBlocks = calculateUrtParameterValue(1, 9, $date, $shiftId, $rowNum);
                    
                    if ($genBlocks == 0 || $genBlocks === null) {
                        return null;
                    }
                    
                    $result = ($factTg7 * $genTg7 + $factTg8 * $genTg8) / $genBlocks;
                    error_log("URT calculateUrtParameterValue: Расход на с.н. (факт) для 'по Блокам', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", факт_ТГ7=$factTg7, факт_ТГ8=$factTg8, выработка_ТГ7=$genTg7, выработка_ТГ8=$genTg8, выработка_по_блокам=$genBlocks, результат=$result");
                    return $result;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2: L16/O16='Норма ПГУ - 283 1(а) стр.'!F15/G15
                    // F15/G15 - это row_num = 15 (Эсн, СН блока э.э., %)
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_fullparam_values 
                        WHERE fullparam_id = 6 AND pgu_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Расход на с.н. (факт) для ПГУ blockId=$blockId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ": Q16='Норма ПГУ - 283 1(а) стр.'!H15
                    // H15 - это row_num = 15 для pgu_id = 3
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_fullparam_values 
                        WHERE fullparam_id = 6 AND pgu_id = 3 AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$date, $shiftId]);
                    } else {
                        $stmt->execute([$date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Расход на с.н. (факт) для 'по ПГУ', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 5) {
                    // Для "по станции": S16=(J16*J7+Q16*Q7)/S7
                    // J16 - факт "по Блокам", Q16 - факт "по ПГУ"
                    // J7 - выработка "по Блокам", Q7 - выработка "по ПГУ", S7 - выработка "по станции"
                    $factBlocks = calculateUrtParameterValue($paramId, 9, $date, $shiftId, $rowNum);
                    $factPgu = calculateUrtParameterValue($paramId, 3, $date, $shiftId, $rowNum);
                    
                    $genBlocks = calculateUrtParameterValue(1, 9, $date, $shiftId, $rowNum);
                    $genPgu = calculateUrtParameterValue(1, 3, $date, $shiftId, $rowNum);
                    $genStation = calculateUrtParameterValue(1, 5, $date, $shiftId, $rowNum);
                    
                    if ($genStation == 0 || $genStation === null) {
                        return null;
                    }
                    
                    $result = ($factBlocks * $genBlocks + $factPgu * $genPgu) / $genStation;
                    error_log("URT calculateUrtParameterValue: Расход на с.н. (факт) для 'по станции', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", факт_по_блокам=$factBlocks, факт_по_ПГУ=$factPgu, выработка_по_блокам=$genBlocks, выработка_по_ПГУ=$genPgu, выработка_по_станции=$genStation, результат=$result");
                    return $result;
                }
                else {
                    return null;
                }
                break;
                
            // Температура острого пара
            case 13:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E18/H18 - температура острого пара из parameter_values с parameter_id = 30
                    // ТГ7 -> equipment_id = 1, ТГ8 -> equipment_id = 2
                    $equipmentId = $blockId == 7 ? 1 : 2;
                    
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 30 AND equipment_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Температура острого пара для blockId=$blockId, equipment_id=$equipmentId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J18=(E18*E10+H18*H10)/(E10+H10)
                    // E18 - температура ТГ7, H18 - температура ТГ8
                    // E10/H10 - средняя электрическая нагрузка (param_id = 6)
                    $tempTg7 = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tempTg8 = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    $loadTg7 = calculateUrtParameterValue(6, 7, $date, $shiftId, $rowNum); // param_id = 6 (средняя электрическая нагрузка)
                    $loadTg8 = calculateUrtParameterValue(6, 8, $date, $shiftId, $rowNum);
                    
                    if (($loadTg7 === null || $loadTg7 == 0) && ($loadTg8 === null || $loadTg8 == 0)) {
                        return null;
                    }
                    
                    $totalLoad = ($loadTg7 ?? 0) + ($loadTg8 ?? 0);
                    if ($totalLoad == 0) {
                        return null;
                    }
                    
                    $result = (($tempTg7 ?? 0) * ($loadTg7 ?? 0) + ($tempTg8 ?? 0) * ($loadTg8 ?? 0)) / $totalLoad;
                    error_log("URT calculateUrtParameterValue: Температура острого пара для 'по Блокам', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", темп_ТГ7=$tempTg7, темп_ТГ8=$tempTg8, нагрузка_ТГ7=$loadTg7, нагрузка_ТГ8=$loadTg8, результат=$result");
                    return $result;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2: берем из pgu_fullparam_values или parameter_values
                    // Нужно найти соответствующий параметр для температуры острого пара ПГУ
                    // Пока возвращаем null, так как источник данных неясен
                    return null;
                }
                else {
                    return null;
                }
                break;
                
            // Давление острого пара
            case 14:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E19/H19 - давление острого пара из parameter_values с parameter_id = 29
                    // ТГ7 -> equipment_id = 1, ТГ8 -> equipment_id = 2
                    $equipmentId = $blockId == 7 ? 1 : 2;
                    
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 29 AND equipment_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Давление острого пара для blockId=$blockId, equipment_id=$equipmentId, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J19=(E19*E10+H19*H10)/(E10+H10)
                    // E19 - давление ТГ7, H19 - давление ТГ8
                    // E10/H10 - средняя электрическая нагрузка (param_id = 6)
                    $pressureTg7 = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $pressureTg8 = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    $loadTg7 = calculateUrtParameterValue(6, 7, $date, $shiftId, $rowNum); // param_id = 6 (средняя электрическая нагрузка)
                    $loadTg8 = calculateUrtParameterValue(6, 8, $date, $shiftId, $rowNum);
                    
                    if (($loadTg7 === null || $loadTg7 == 0) && ($loadTg8 === null || $loadTg8 == 0)) {
                        return null;
                    }
                    
                    $totalLoad = ($loadTg7 ?? 0) + ($loadTg8 ?? 0);
                    if ($totalLoad == 0) {
                        return null;
                    }
                    
                    $result = (($pressureTg7 ?? 0) * ($loadTg7 ?? 0) + ($pressureTg8 ?? 0) * ($loadTg8 ?? 0)) / $totalLoad;
                    error_log("URT calculateUrtParameterValue: Давление острого пара для 'по Блокам', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", давление_ТГ7=$pressureTg7, давление_ТГ8=$pressureTg8, нагрузка_ТГ7=$loadTg7, нагрузка_ТГ8=$loadTg8, результат=$result");
                    return $result;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2: берем из pgu_fullparam_values или parameter_values
                    // Нужно найти соответствующий параметр для давления острого пара ПГУ
                    // Пока возвращаем null, так как источник данных неясен
                    return null;
                }
                else {
                    return null;
                }
                break;
                
            // Температура пара промперегрева
            case 15:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E20/H20 - температура пара промперегрева из parameter_values с parameter_id = 38 (t пара после промперегрева)
                    // ТГ7 -> equipment_id = 1, ТГ8 -> equipment_id = 2
                    $equipmentId = $blockId == 7 ? 1 : 2;
                    
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 38 AND equipment_id = ? AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Температура пара промперегрева для blockId=$blockId, equipment_id=$equipmentId, parameter_id=39, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J20=(E20*E10+H20*H10)/(E10+H10)
                    // E20 - температура ТГ7, H20 - температура ТГ8
                    // E10/H10 - средняя электрическая нагрузка (param_id = 6)
                    $tempTg7 = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tempTg8 = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    $loadTg7 = calculateUrtParameterValue(6, 7, $date, $shiftId, $rowNum); // param_id = 6 (средняя электрическая нагрузка)
                    $loadTg8 = calculateUrtParameterValue(6, 8, $date, $shiftId, $rowNum);
                    
                    if (($loadTg7 === null || $loadTg7 == 0) && ($loadTg8 === null || $loadTg8 == 0)) {
                        return null;
                    }
                    
                    $totalLoad = ($loadTg7 ?? 0) + ($loadTg8 ?? 0);
                    if ($totalLoad == 0) {
                        return null;
                    }
                    
                    $result = (($tempTg7 ?? 0) * ($loadTg7 ?? 0) + ($tempTg8 ?? 0) * ($loadTg8 ?? 0)) / $totalLoad;
                    error_log("URT calculateUrtParameterValue: Температура пара промперегрева для 'по Блокам', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", темп_ТГ7=$tempTg7, темп_ТГ8=$tempTg8, нагрузка_ТГ7=$loadTg7, нагрузка_ТГ8=$loadTg8, результат=$result");
                    return $result;
                }
                else {
                    return null;
                }
                break;
                
            // Температура питательной воды
            case 16:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E21/H21 - температура питательной воды из parameter_values с parameter_id = 33, cell = C29/D29
                    // ТГ7 -> equipment_id = 1, ТГ8 -> equipment_id = 2
                    $equipmentId = $blockId == 7 ? 1 : 2;
                    $cell = $blockId == 7 ? 'C18' : 'D18';
                    
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 33 AND equipment_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $cell, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date, $cell]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Температура питательной воды для blockId=$blockId, equipment_id=$equipmentId, parameter_id=33, cell=$cell, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J21=(E21*E10+H21*H10)/(E10+H10)
                    // E21 - температура ТГ7, H21 - температура ТГ8
                    // E10/H10 - средняя электрическая нагрузка (param_id = 6)
                    $tempTg7 = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tempTg8 = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    $loadTg7 = calculateUrtParameterValue(6, 7, $date, $shiftId, $rowNum); // param_id = 6 (средняя электрическая нагрузка)
                    $loadTg8 = calculateUrtParameterValue(6, 8, $date, $shiftId, $rowNum);
                    
                    if (($loadTg7 === null || $loadTg7 == 0) && ($loadTg8 === null || $loadTg8 == 0)) {
                        return null;
                    }
                    
                    $totalLoad = ($loadTg7 ?? 0) + ($loadTg8 ?? 0);
                    if ($totalLoad == 0) {
                        return null;
                    }
                    
                    $result = (($tempTg7 ?? 0) * ($loadTg7 ?? 0) + ($tempTg8 ?? 0) * ($loadTg8 ?? 0)) / $totalLoad;
                    error_log("URT calculateUrtParameterValue: Температура питательной воды для 'по Блокам', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", темп_ТГ7=$tempTg7, темп_ТГ8=$tempTg8, нагрузка_ТГ7=$loadTg7, нагрузка_ТГ8=$loadTg8, результат=$result");
                    return $result;
                }
                else {
                    return null;
                }
                break;
                
            // Вакуум (по темпер. охлажд. воды)
            case 17:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: D22 = (C14*1.01972/1000 - [сложная формула с IF])*100
                    // ТГ7 -> equipment_id = 1, ТГ8 -> equipment_id = 2
                    $equipmentId = $blockId == 7 ? 1 : 2;
                    
                    // Получаем C14 (parameter_id = 29, "P острого пара перед т/а")
                    $cell14 = $blockId == 7 ? 'C14' : 'D14';
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 29 AND equipment_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $cell14, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date, $cell14]);
                    }
                    
                    $result14 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $c14 = $result14 ? (float)$result14['value'] : 0;
                    
                    // Получаем C20 (parameter_id = 31, "t в циркводы на входе", cell = C16/D16)
                    $cell16 = $blockId == 7 ? 'C16' : 'D16';
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 31 AND equipment_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $cell16, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date, $cell16]);
                    }
                    
                    $result16 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $c20 = $result16 ? (float)$result16['value'] : 0;
                    
                    // Получаем E32/F32 (param_id = 288, row_num = 32, "КПД брутто котла")
                    $cell32 = $blockId == 7 ? 'E32' : 'F32';
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 288 AND tg_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell32, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell32]);
                    }
                    
                    $result32 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $e32 = $result32 ? (float)$result32['value'] : 0;
                    
                    // Расчет сложной формулы с вложенными IF
                    // IF(E32<=0,0,IF(C20>=25,формула1,IF(C20>=20,формула2,IF(C20>=15,формула3,IF(C20>=10,формула4,IF(C20>=5,формула5,формула_для_меньше_5))))))
                    $complexFormula = 0;
                    if ($e32 > 0) {
                        if ($c20 >= 25) {
                            // C20 >= 25
                            $base = 0.0000001555001365 * pow($e32, 2) + 0.0000570281133977 * $e32 + 0.0411625795353308;
                            $next = 0.0000001624225409 * pow($e32, 2) + 0.0000894332069861 * $e32 + 0.0508191003648813;
                            $complexFormula = $base + (($next - $base) / 5) * ($c20 - 25);
                        } elseif ($c20 >= 20) {
                            // 20 <= C20 < 25
                            $base = 0.0000001060723544 * pow($e32, 2) + 0.0000576558099885 * $e32 + 0.0296345306558854;
                            $next = 0.0000001555001365 * pow($e32, 2) + 0.0000570281133977 * $e32 + 0.0411625795353308;
                            $complexFormula = $base + (($next - $base) / 5) * ($c20 - 20);
                        } elseif ($c20 >= 15) {
                            // 15 <= C20 < 20
                            $base = 0.0000001124200844 * pow($e32, 2) + 0.0000359745446275 * $e32 + 0.0228091712212537;
                            $next = 0.0000001060723544 * pow($e32, 2) + 0.0000576558099885 * $e32 + 0.0296345306558854;
                            $complexFormula = $base + (($next - $base) / 5) * ($c20 - 15);
                        } elseif ($c20 >= 10) {
                            // 10 <= C20 < 15
                            $base = 0.0000000886772486 * pow($e32, 2) + 0.0000387112032651 * $e32 + 0.0156630705289885;
                            $next = 0.0000001124200844 * pow($e32, 2) + 0.0000359745446275 * $e32 + 0.0228091712212537;
                            $complexFormula = $base + (($next - $base) / 5) * ($c20 - 10);
                        } elseif ($c20 >= 5) {
                            // 5 <= C20 < 10
                            $base = 1.01123574841005E-07 * pow($e32, 2) + 0.0000196742058774438 * $e32 + 0.0141170673428909;
                            $next = 0.0000000886772486 * pow($e32, 2) + 0.0000387112032651 * $e32 + 0.0156630705289885;
                            $complexFormula = $base + (($next - $base) / 5) * ($c20 - 5);
                        } else {
                            // C20 < 5 - используем формулу для 5 как базовую
                            $complexFormula = 1.01123574841005E-07 * pow($e32, 2) + 0.0000196742058774438 * $e32 + 0.0141170673428909;
                        }
                    }
                    
                    // Итоговый расчет: D22 = (C14*1.01972/1000 - complexFormula)*100
                    $value = ($c14 * 1.01972 / 1000 - $complexFormula) * 100;
                    
                    error_log("URT calculateUrtParameterValue: Вакуум для blockId=$blockId, C14=$c14, C20=$c20, E32=$e32, complexFormula=$complexFormula, значение=$value");
                    return $value;
                }
                else {
                    return null;
                }
                break;
                
            // Температура уходящих газов
            case 18:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: D23/G23=норма (0.2523*E10/H10+99.234), E23/H23=факт, F23/I23=dbэ
                    // ТГ7 -> equipment_id = 1, ТГ8 -> equipment_id = 2
                    $equipmentId = $blockId == 7 ? 1 : 2;
                    
                    // Получаем E10/H10 (средняя электрическая нагрузка, param_id = 6) для расчета нормы
                    $load = calculateUrtParameterValue(6, $blockId, $date, $shiftId, $rowNum);
                    
                    // Расчет нормы: D23/G23 = 0.2523*E10/H10+99.234
                    $norm = null;
                    if ($load !== null) {
                        $norm = 0.2523 * $load + 99.234;
                    }
                    
                    // Получаем E23/H23 (факт) - температура уходящих газов из parameter_values с parameter_id = 47, cell = C32/D32
                    $cell32 = $blockId == 7 ? 'C32' : 'D32';
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 47 AND equipment_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $cell32, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date, $cell32]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $fact = $result ? (float)$result['value'] : null;
                    
                    // Получаем E21/F21 (param_id = 33, row_num = 21, "Средняя температура охлаждающей воды на выходе из конденсатора") для расчета db3
                    $cell21 = $blockId == 7 ? 'E21' : 'F21';
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 33 AND tg_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell21, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell21]);
                    }
                    
                    $result21 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $e21 = $result21 ? (float)$result21['value'] : 0;
                    
                    // Получаем E32/F32 (param_id = 288, row_num = 32, category = '3b', "Исходно-нормативное значение") для расчета db3
                    $cell32_tg = $blockId == 7 ? 'E32' : 'F32';
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 288 AND tg_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell32_tg, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell32_tg]);
                    }
                    
                    $result32 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $e32 = $result32 ? (float)$result32['value'] : 0;
                    
                    // Расчет db3: F23/I23 = IFERROR((E23-D23)*0.048/100*E21*1000/E10/7/(E32/100),0)
                    $db3 = null;
                    if ($fact !== null && $norm !== null && $load !== null && $load > 0 && $e21 > 0 && $e32 > 0) {
                        $db3 = ($fact - $norm) * 0.048 / 100 * $e21 * 1000 / $load / 7 / ($e32 / 100);
                    } else {
                        $db3 = 0; // IFERROR возвращает 0 при ошибке
                    }
                    
                    error_log("URT calculateUrtParameterValue: Температура уходящих газов для blockId=$blockId, load=$load, norm=$norm, fact=$fact, E21=$e21, E32=$e32, db3=$db3");
                    return $fact; // Возвращаем fact для использования в calculateUrtAnalysisValues
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J23=(E23*E10+H23*H10)/(E10+H10)
                    // E23 - температура ТГ7, H23 - температура ТГ8
                    // E10/H10 - средняя электрическая нагрузка (param_id = 6)
                    $tempTg7 = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $tempTg8 = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    $loadTg7 = calculateUrtParameterValue(6, 7, $date, $shiftId, $rowNum); // param_id = 6 (средняя электрическая нагрузка)
                    $loadTg8 = calculateUrtParameterValue(6, 8, $date, $shiftId, $rowNum);
                    
                    if (($loadTg7 === null || $loadTg7 == 0) && ($loadTg8 === null || $loadTg8 == 0)) {
                        return null;
                    }
                    
                    $totalLoad = ($loadTg7 ?? 0) + ($loadTg8 ?? 0);
                    if ($totalLoad == 0) {
                        return null;
                    }
                    
                    $result = (($tempTg7 ?? 0) * ($loadTg7 ?? 0) + ($tempTg8 ?? 0) * ($loadTg8 ?? 0)) / $totalLoad;
                    error_log("URT calculateUrtParameterValue: Температура уходящих газов для 'по Блокам', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", темп_ТГ7=$tempTg7, темп_ТГ8=$tempTg8, нагрузка_ТГ7=$loadTg7, нагрузка_ТГ8=$loadTg8, результат=$result");
                    return $result;
                }
                else {
                    return null;
                }
                break;
                
            // Избыток воздуха в уходящих газах
            case 19:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: D24/G24=норма (-0.00072*E10/H10+1.41415), E24/H24=факт, F24/I24=dbэ
                    // ТГ7 -> equipment_id = 1, ТГ8 -> equipment_id = 2
                    $equipmentId = $blockId == 7 ? 1 : 2;
                    
                    // Получаем E10/H10 (средняя электрическая нагрузка, param_id = 6) для расчета нормы
                    $load = calculateUrtParameterValue(6, $blockId, $date, $shiftId, $rowNum);
                    
                    // Расчет нормы: D24/G24 = -0.00072*E10/H10+1.41415
                    $norm = null;
                    if ($load !== null) {
                        $norm = -0.00072 * $load + 1.41415;
                    }
                    
                    // Получаем E24/H24 (факт) - избыток воздуха в уходящих газах из parameter_values с parameter_id = 48, cell = C33/D33
                    $cell33 = $blockId == 7 ? 'C33' : 'D33';
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 48 AND equipment_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $cell33, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date, $cell33]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $fact = $result ? (float)$result['value'] : null;
                    
                    // Получаем E21/F21 (param_id = 33, row_num = 21, "Средняя температура охлаждающей воды на выходе из конденсатора") для расчета db3
                    $cell21 = $blockId == 7 ? 'E21' : 'F21';
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 33 AND tg_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell21, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell21]);
                    }
                    
                    $result21 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $e21 = $result21 ? (float)$result21['value'] : 0;
                    
                    // Получаем E32/F32 (param_id = 288, row_num = 32, category = '3b', "Исходно-нормативное значение") для расчета db3
                    $cell32_tg = $blockId == 7 ? 'E32' : 'F32';
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 288 AND tg_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell32_tg, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell32_tg]);
                    }
                    
                    $result32 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $e32 = $result32 ? (float)$result32['value'] : 0;
                    
                    // Расчет db3: F24/I24 = IFERROR((E24-D24)*0.052/100*E21*1000/E10/7/(E32/100),0)
                    $db3 = null;
                    if ($fact !== null && $norm !== null && $load !== null && $load > 0 && $e21 > 0 && $e32 > 0) {
                        $db3 = ($fact - $norm) * 0.052 / 100 * $e21 * 1000 / $load / 7 / ($e32 / 100);
                    } else {
                        $db3 = 0; // IFERROR возвращает 0 при ошибке
                    }
                    
                    error_log("URT calculateUrtParameterValue: Избыток воздуха в уходящих газах для blockId=$blockId, load=$load, norm=$norm, fact=$fact, E21=$e21, E32=$e32, db3=$db3");
                    return $fact; // Возвращаем fact для использования в calculateUrtAnalysisValues
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J24=(E24*E10+H24*H10)/(E10+H10)
                    // E24 - избыток воздуха ТГ7, H24 - избыток воздуха ТГ8
                    // E10/H10 - средняя электрическая нагрузка (param_id = 6)
                    $airTg7 = calculateUrtParameterValue($paramId, 7, $date, $shiftId, $rowNum);
                    $airTg8 = calculateUrtParameterValue($paramId, 8, $date, $shiftId, $rowNum);
                    
                    $loadTg7 = calculateUrtParameterValue(6, 7, $date, $shiftId, $rowNum); // param_id = 6 (средняя электрическая нагрузка)
                    $loadTg8 = calculateUrtParameterValue(6, 8, $date, $shiftId, $rowNum);
                    
                    if (($loadTg7 === null || $loadTg7 == 0) && ($loadTg8 === null || $loadTg8 == 0)) {
                        return null;
                    }
                    
                    $totalLoad = ($loadTg7 ?? 0) + ($loadTg8 ?? 0);
                    if ($totalLoad == 0) {
                        return null;
                    }
                    
                    $result = (($airTg7 ?? 0) * ($loadTg7 ?? 0) + ($airTg8 ?? 0) * ($loadTg8 ?? 0)) / $totalLoad;
                    error_log("URT calculateUrtParameterValue: Избыток воздуха в уходящих газах для 'по Блокам', date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", избыток_ТГ7=$airTg7, избыток_ТГ8=$airTg8, нагрузка_ТГ7=$loadTg7, нагрузка_ТГ8=$loadTg8, результат=$result");
                    return $result;
                }
                else {
                    return null;
                }
                break;
                
            // Пуски
            case 20:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: D27/G27=норма (C13/D13*50), E27/H27=факт (C13/D13*50), F27/I27=dbэ
                    // C13/D13 - "Количество пусков т/агрегатов по диспетчерскому графику" из parameter_values (equipment_id = 7, cell = C13/D13)
                    $cell13 = $blockId == 7 ? 'C13' : 'D13';
                    
                    // Получаем C13/D13 из parameter_values для equipment_id = 7 (ОЧ-130)
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE equipment_id = 7 AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$date, $cell13, $shiftId]);
                    } else {
                        $stmt->execute([$date, $cell13]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $c13 = $result ? (float)$result['value'] : 0;
                    
                    // Если не нашли в parameter_values, пробуем из tg_result_values с param_id = 30
                    if ($c13 == 0) {
                        // Пробуем получить из tg_result_values без указания cell (может быть E18/F18)
                        $stmt = $db->prepare('
                            SELECT value FROM tg_result_values 
                            WHERE param_id = 30 AND tg_id = ? AND date = ?
                            ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                        );
                        
                        if ($shiftId) {
                            $stmt->execute([$blockId, $date, $shiftId]);
                        } else {
                            $stmt->execute([$blockId, $date]);
                        }
                        
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        $c13 = $result ? (float)$result['value'] : 0;
                    }
                    
                    // Норма и факт: D27/G27 = E27/H27 = C13/D13*50
                    $value = $c13 * 50;
                    
                    error_log("URT calculateUrtParameterValue: Пуски для blockId=$blockId, C13=$c13, значение=$value");
                    return $value; // Возвращаем fact для использования в calculateUrtAnalysisValues
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J27 = E27+H27 (сумма пусков ТГ7 и ТГ8)
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
                    // Для ПГУ1 и ПГУ2: L27/O27 = F82/G82*L7/O7/1000
                    // F82/G82 - "Номинальный удельный расход топлива на отпуск электроэнергии с учётом пусков" из pgu_result_values (param_id = 72, row_num = 82)
                    // L7/O7 - выработка электроэнергии ПГУ1/ПГУ2 (param_id = 1)
                    $cell82 = $blockId == 1 ? 'F82' : 'G82';
                    
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_result_values 
                        WHERE param_id = 72 AND pgu_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell82, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell82]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $f82 = $result ? (float)$result['value'] : 0;
                    
                    // Получаем L7/O7 (выработка электроэнергии, param_id = 1)
                    $generation = calculateUrtParameterValue(1, $blockId, $date, $shiftId, $rowNum);
                    
                    if ($f82 > 0 && $generation !== null && $generation > 0) {
                        $value = $f82 * $generation / 1000;
                        error_log("URT calculateUrtParameterValue: Пуски для ПГУ blockId=$blockId, F82=$f82, выработка=$generation, значение=$value");
                        return $value;
                    }
                    return null;
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ": Q27 = L27+O27 (сумма пусков ПГУ1 и ПГУ2)
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
                    // Для "по Станции": S27 = J27+Q27 (сумма пусков "по Блокам" и "по ПГУ")
                    $blocksValue = calculateUrtParameterValue($paramId, 9, $date, $shiftId, $rowNum);
                    $pguValue = calculateUrtParameterValue($paramId, 3, $date, $shiftId, $rowNum);
                    
                    $sum = 0;
                    $hasValue = false;
                    if ($blocksValue !== null) { $sum += $blocksValue; $hasValue = true; }
                    if ($pguValue !== null) { $sum += $pguValue; $hasValue = true; }
                    
                    return $hasValue ? $sum : null;
                }
                else {
                    return null;
                }
                break;
                
            // Расход газа
            case 27:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E30/H30 = C34/D34 из parameter_values
                    // ТГ7 -> equipment_id = 1, ТГ8 -> equipment_id = 2
                    $equipmentId = $blockId == 7 ? 1 : 2;
                    $cell = $blockId == 7 ? 'C30' : 'D30';
                    
                    // Используем parameter_id = 45 ("В топлива за месяц (газ)")
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 45 AND equipment_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $cell, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date, $cell]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Расход газа для blockId=$blockId, equipment_id=$equipmentId, parameter_id=45, cell=$cell, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J30 = E30 + H30 (сумма ТГ7 и ТГ8)
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
                    // Для ПГУ1 и ПГУ2: L30/O30 = D27/G27 из 'Исх данные ПГУ'
                    // Нужно найти, что это за параметр в pgu_fullparam_values или parameter_values
                    // Предполагаем, что это parameter_id = 24 ("Расход топлива на ГТУ") для ПГУ
                    // Для ПГУ1: equipment_id = 3 (ГТ1), cell = D27
                    // Для ПГУ2: equipment_id = 5 (ГТ2), cell = G27
                    $equipmentId = $blockId == 1 ? 3 : 5; // ПГУ1 -> ГТ1 (equipment_id = 3), ПГУ2 -> ГТ2 (equipment_id = 5)
                    $cell = $blockId == 1 ? 'D27' : 'G27';
                    
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 24 AND equipment_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $cell, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date, $cell]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Расход газа для ПГУ blockId=$blockId, equipment_id=$equipmentId, parameter_id=24, cell=$cell, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ": Q30 = L30 + O30 (сумма ПГУ1 и ПГУ2)
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
                    // Для "по Станции": S30 = J30 + Q30 (сумма по Блокам и по ПГУ)
                    $blocksValue = calculateUrtParameterValue($paramId, 9, $date, $shiftId, $rowNum);
                    $pguValue = calculateUrtParameterValue($paramId, 3, $date, $shiftId, $rowNum);
                    
                    if ($blocksValue !== null && $pguValue !== null) {
                        return $blocksValue + $pguValue;
                    } elseif ($blocksValue !== null) {
                        return $blocksValue;
                    } elseif ($pguValue !== null) {
                        return $pguValue;
                    }
                    return null;
                }
                else {
                    return null;
                }
                break;
                
            // Расход мазута
            case 28:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E31/H31 = C35/D35 из parameter_values
                    // ТГ7 -> equipment_id = 1, ТГ8 -> equipment_id = 2
                    $equipmentId = $blockId == 7 ? 1 : 2;
                    $cell = $blockId == 7 ? 'C31' : 'D31';
                    
                    // Используем parameter_id = 46 ("В топлива за месяц (мазут)")
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 46 AND equipment_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$equipmentId, $date, $cell, $shiftId]);
                    } else {
                        $stmt->execute([$equipmentId, $date, $cell]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Расход мазута для blockId=$blockId, equipment_id=$equipmentId, parameter_id=46, cell=$cell, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J31 = E31 + H31 (сумма ТГ7 и ТГ8)
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
                    // Для ПГУ1 и ПГУ2: мазут обычно не используется, возвращаем 0 или null
                    // Если нужно, можно добавить логику для получения значения из parameter_values
                    return 0; // ПГУ обычно работает на газе, мазут = 0
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ": Q31 = L31 + O31 (сумма ПГУ1 и ПГУ2)
                    $pgu1Value = calculateUrtParameterValue($paramId, 1, $date, $shiftId, $rowNum);
                    $pgu2Value = calculateUrtParameterValue($paramId, 2, $date, $shiftId, $rowNum);
                    
                    if ($pgu1Value !== null && $pgu2Value !== null) {
                        return $pgu1Value + $pgu2Value;
                    } elseif ($pgu1Value !== null) {
                        return $pgu1Value;
                    } elseif ($pgu2Value !== null) {
                        return $pgu2Value;
                    }
                    return 0; // Если оба null, возвращаем 0
                }
                elseif ($blockId == 5) {
                    // Для "по Станции": S31 = J31 + Q31 (сумма по Блокам и по ПГУ)
                    $blocksValue = calculateUrtParameterValue($paramId, 9, $date, $shiftId, $rowNum);
                    $pguValue = calculateUrtParameterValue($paramId, 3, $date, $shiftId, $rowNum);
                    
                    if ($blocksValue !== null && $pguValue !== null) {
                        return $blocksValue + $pguValue;
                    } elseif ($blocksValue !== null) {
                        return $blocksValue;
                    } elseif ($pguValue !== null) {
                        return $pguValue;
                    }
                    return null;
                }
                else {
                    return null;
                }
                break;
                
            // Калорийность газа
            case 29:
                // Только для "по Станции" (block_id = 5): S32 = E32 из 'Исх. данные оч.130'
                if ($blockId == 5) {
                    // E32 - это parameter_id = 43 ("Факт Qнр (газ)") для ОЧ-130 (equipment_id = 7)
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 43 AND equipment_id = 7 AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$date, 'E28', $shiftId]);
                    } else {
                        $stmt->execute([$date, 'E28']);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Калорийность газа для blockId=$blockId (по Станции), parameter_id=43, equipment_id=7, cell=E32, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                else {
                    return null;
                }
                break;
                
            // Калорийность мазута
            case 30:
                // Только для "по Станции" (block_id = 5): S33 = E33 из 'Исх. данные оч.130'
                if ($blockId == 5) {
                    // E33 - это parameter_id = 44 ("Факт Qнр (мазут)") для ОЧ-130 (equipment_id = 7)
                    $stmt = $db->prepare('
                        SELECT value FROM parameter_values 
                        WHERE parameter_id = 44 AND equipment_id = 7 AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$date, 'E29', $shiftId]);
                    } else {
                        $stmt->execute([$date, 'E29']);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Калорийность мазута для blockId=$blockId (по Станции), parameter_id=44, equipment_id=7, cell=E33, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                else {
                    return null;
                }
                break;
                
            // Расход топлива на электроэнергию
            case 31:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E34/H34 = E30/H30 * S32 / 7000
                    // E30/H30 - расход газа (param_id = 27)
                    // S32 - калорийность газа для "по Станции" (param_id = 29, block_id = 5)
                    $gasFlow = calculateUrtParameterValue(27, $blockId, $date, $shiftId, $rowNum);
                    $gasHeat = calculateUrtParameterValue(29, 5, $date, $shiftId, $rowNum); // S32 - калорийность газа для "по Станции"
                    
                    if ($gasFlow !== null && $gasHeat !== null && $gasHeat > 0) {
                        $value = $gasFlow * $gasHeat / 7000;
                        error_log("URT calculateUrtParameterValue: Расход топлива на электроэнергию для blockId=$blockId, расход_газа=$gasFlow, калорийность_газа=$gasHeat, значение=$value");
                        return $value;
                    }
                    return null;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J34 = E34 + H34 (сумма ТГ7 и ТГ8)
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
                    // Для ПГУ1 и ПГУ2: L34/O34 = L30/O30 * S32 / 7000 - L35/O35
                    // L30/O30 - расход газа (param_id = 27)
                    // S32 - калорийность газа для "по Станции" (param_id = 29, block_id = 5)
                    // L35/O35 - расход топлива на тепловую нагрузку из pgu_result_values (param_id = 64, row_num = 74, cell = F74/G74)
                    $gasFlow = calculateUrtParameterValue(27, $blockId, $date, $shiftId, $rowNum);
                    $gasHeat = calculateUrtParameterValue(29, 5, $date, $shiftId, $rowNum); // S32 - калорийность газа для "по Станции"
                    
                    // Получаем L35/O35 из pgu_result_values (param_id = 64, row_num = 74)
                    $cell74 = $blockId == 1 ? 'F74' : 'G74';
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_result_values 
                        WHERE param_id = 64 AND pgu_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell74, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell74]);
                    }
                    
                    $result74 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $fuelHeat = $result74 ? (float)$result74['value'] : 0; // L35/O35 - расход топлива на тепловую нагрузку
                    
                    if ($gasFlow !== null && $gasHeat !== null && $gasHeat > 0) {
                        $value = ($gasFlow * $gasHeat / 7000) - $fuelHeat;
                        error_log("URT calculateUrtParameterValue: Расход топлива на электроэнергию для ПГУ blockId=$blockId, расход_газа=$gasFlow, калорийность_газа=$gasHeat, расход_на_тепло=$fuelHeat, значение=$value");
                        return $value;
                    }
                    return null;
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ": Q34 = L34 + O34 (сумма ПГУ1 и ПГУ2)
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
                    // Для "по Станции": S34 = J34 + Q34 (сумма по Блокам и по ПГУ)
                    $blocksValue = calculateUrtParameterValue($paramId, 9, $date, $shiftId, $rowNum);
                    $pguValue = calculateUrtParameterValue($paramId, 3, $date, $shiftId, $rowNum);
                    
                    if ($blocksValue !== null && $pguValue !== null) {
                        return $blocksValue + $pguValue;
                    } elseif ($blocksValue !== null) {
                        return $blocksValue;
                    } elseif ($pguValue !== null) {
                        return $pguValue;
                    }
                    return null;
                }
                else {
                    return null;
                }
                break;
                
            // Расход топлива на тепло
            case 32:
                if ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2: L35/O35 = F74/G74 из pgu_result_values (param_id = 64, row_num = 74, "Расход топлива на тепловую нагрузку")
                    $cell74 = $blockId == 1 ? 'F74' : 'G74';
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_result_values 
                        WHERE param_id = 64 AND pgu_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell74, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell74]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Расход топлива на тепло для ПГУ blockId=$blockId, param_id=64, cell=$cell74, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ": Q35 = L35 + O35 (сумма ПГУ1 и ПГУ2)
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
                    // Для "по Станции": S35 = Q35 (равно "по ПГУ")
                    return calculateUrtParameterValue($paramId, 3, $date, $shiftId, $rowNum);
                }
                else {
                    return null;
                }
                break;
                
            // Доля газа в балансе топлива
            case 33:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E36/H36 = E17/F17 * 100 из tg_result_values (param_id = 276, row_num = 17, category = '3b')
                    $cell17 = $blockId == 7 ? 'E17' : 'F17';
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 276 AND tg_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell17, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell17]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result && $result['value'] !== null) {
                        $value = (float)$result['value'] * 100;
                        error_log("URT calculateUrtParameterValue: Доля газа в балансе топлива для blockId=$blockId, param_id=276, cell=$cell17, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=$value");
                        return $value;
                    }
                    // IFERROR: если значение null или ошибка, возвращаем 0
                    return 0;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J36 = G17 * 100 из tg_result_values для ОЧ-130 (tg_id = 9)
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 276 AND tg_id = 9 AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$date, 'G17', $shiftId]);
                    } else {
                        $stmt->execute([$date, 'G17']);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result && $result['value'] !== null) {
                        $value = (float)$result['value'] * 100;
                        error_log("URT calculateUrtParameterValue: Доля газа в балансе топлива для 'по Блокам', param_id=276, cell=G17, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=$value");
                        return $value;
                    }
                    return null;
                }
                else {
                    return null;
                }
                break;
                
            // Номинальный УРТ
            case 34:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E37/H37 = E17/F17 из tg_result_values (param_id = 11, row_num = 17, category = '4')
                    $cell17 = $blockId == 7 ? 'E17' : 'F17';
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 11 AND tg_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell17, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell17]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Номинальный УРТ для blockId=$blockId, param_id=11, cell=$cell17, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J37 = G17 из tg_result_values для ОЧ-130 (tg_id = 9)
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 11 AND tg_id = 9 AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$date, 'G17', $shiftId]);
                    } else {
                        $stmt->execute([$date, 'G17']);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Номинальный УРТ для 'по Блокам', param_id=11, cell=G17, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2: L37/O37 = F83/G83 из pgu_result_values (param_id = 72, row_num = 82, "Номинальный удельный расход топлива на отпуск электроэнергии с учётом пусков")
                    $cell83 = $blockId == 1 ? 'F83' : 'G83';
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_result_values 
                        WHERE param_id = 72 AND pgu_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell83, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell83]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Номинальный УРТ для ПГУ blockId=$blockId, param_id=72, cell=$cell83, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ": Q37 = H83 из pgu_result_values для ОЧ-130 (pgu_id = 3) или сумма ПГУ1 и ПГУ2
                    // Проверяем, есть ли значение для ОЧ-130
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_result_values 
                        WHERE param_id = 72 AND pgu_id = 3 AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$date, 'H83', $shiftId]);
                    } else {
                        $stmt->execute([$date, 'H83']);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result && $result['value'] !== null) {
                        $value = (float)$result['value'];
                        error_log("URT calculateUrtParameterValue: Номинальный УРТ для 'по ПГУ', param_id=72, cell=H83, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=$value");
                        return $value;
                    }
                    
                    // Если нет значения для ОЧ-130, берем сумму ПГУ1 и ПГУ2
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
                    // Для "по Станции": S37 = (J37*J7+Q37*Q7)/S7
                    // J37 - номинальный УРТ для "по Блокам" (param_id = 34, block_id = 9)
                    // J7 - выработка электроэнергии для "по Блокам" (param_id = 1, block_id = 9)
                    // Q37 - номинальный УРТ для "по ПГУ" (param_id = 34, block_id = 3)
                    // Q7 - выработка электроэнергии для "по ПГУ" (param_id = 1, block_id = 3)
                    // S7 - выработка электроэнергии для "по Станции" (param_id = 1, block_id = 5)
                    $j37 = calculateUrtParameterValue(34, 9, $date, $shiftId, $rowNum); // Номинальный УРТ "по Блокам"
                    $j7 = calculateUrtParameterValue(1, 9, $date, $shiftId, $rowNum); // Выработка "по Блокам"
                    $q37 = calculateUrtParameterValue(34, 3, $date, $shiftId, $rowNum); // Номинальный УРТ "по ПГУ"
                    $q7 = calculateUrtParameterValue(1, 3, $date, $shiftId, $rowNum); // Выработка "по ПГУ"
                    $s7 = calculateUrtParameterValue(1, 5, $date, $shiftId, $rowNum); // Выработка "по Станции"
                    
                    if ($j37 !== null && $j7 !== null && $q37 !== null && $q7 !== null && $s7 !== null && $s7 > 0) {
                        $value = ($j37 * $j7 + $q37 * $q7) / $s7;
                        error_log("URT calculateUrtParameterValue: Номинальный УРТ для 'по Станции', J37=$j37, J7=$j7, Q37=$q37, Q7=$q7, S7=$s7, значение=$value");
                        return $value;
                    }
                    return null;
                }
                else {
                    return null;
                }
                break;
                
            // Нормативное значение (равно номинальному УРТ)
            case 35:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E38/H38 = E37/H37 (номинальный УРТ, param_id = 34)
                    return calculateUrtParameterValue(34, $blockId, $date, $shiftId, $rowNum);
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": J38 = (E38*E7+H7*H38)/J7
                    // E38/H38 - нормативное значение для ТГ7/ТГ8 (param_id = 35)
                    // E7/H7/J7 - выработка электроэнергии (param_id = 1)
                    $e38 = calculateUrtParameterValue(35, 7, $date, $shiftId, $rowNum);
                    $h38 = calculateUrtParameterValue(35, 8, $date, $shiftId, $rowNum);
                    $e7 = calculateUrtParameterValue(1, 7, $date, $shiftId, $rowNum);
                    $h7 = calculateUrtParameterValue(1, 8, $date, $shiftId, $rowNum);
                    $j7 = calculateUrtParameterValue(1, 9, $date, $shiftId, $rowNum);
                    
                    if ($e38 !== null && $h38 !== null && $e7 !== null && $h7 !== null && $j7 !== null && $j7 > 0) {
                        $value = ($e38 * $e7 + $h7 * $h38) / $j7;
                        error_log("URT calculateUrtParameterValue: Нормативное значение для 'по Блокам', E38=$e38, E7=$e7, H38=$h38, H7=$h7, J7=$j7, значение=$value");
                        return $value;
                    }
                    return null;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2: L38/O38 = L37/O37 (номинальный УРТ, param_id = 34)
                    return calculateUrtParameterValue(34, $blockId, $date, $shiftId, $rowNum);
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ": Q38 = Q37 (номинальный УРТ, param_id = 34)
                    return calculateUrtParameterValue(34, $blockId, $date, $shiftId, $rowNum);
                }
                elseif ($blockId == 5) {
                    // Для "по Станции": S38 = (J38*J7+Q7*Q38)/S7
                    // J38 - нормативное значение для "по Блокам" (param_id = 35, block_id = 9)
                    // J7 - выработка электроэнергии для "по Блокам" (param_id = 1, block_id = 9)
                    // Q38 - нормативное значение для "по ПГУ" (param_id = 35, block_id = 3)
                    // Q7 - выработка электроэнергии для "по ПГУ" (param_id = 1, block_id = 3)
                    // S7 - выработка электроэнергии для "по Станции" (param_id = 1, block_id = 5)
                    $j38 = calculateUrtParameterValue(35, 9, $date, $shiftId, $rowNum);
                    $j7 = calculateUrtParameterValue(1, 9, $date, $shiftId, $rowNum);
                    $q38 = calculateUrtParameterValue(35, 3, $date, $shiftId, $rowNum);
                    $q7 = calculateUrtParameterValue(1, 3, $date, $shiftId, $rowNum);
                    $s7 = calculateUrtParameterValue(1, 5, $date, $shiftId, $rowNum);
                    
                    if ($j38 !== null && $j7 !== null && $q38 !== null && $q7 !== null && $s7 !== null && $s7 > 0) {
                        $value = ($j38 * $j7 + $q7 * $q38) / $s7;
                        error_log("URT calculateUrtParameterValue: Нормативное значение для 'по Станции', J38=$j38, J7=$j7, Q38=$q38, Q7=$q7, S7=$s7, значение=$value");
                        return $value;
                    }
                    return null;
                }
                else {
                    return null;
                }
                break;
                
            // Фактический УРТ
            case 36:
                if ($blockId == 7 || $blockId == 8) {
                    // Для ТГ7 и ТГ8: E39/F39 = param_id = 13 из tg_result_values (Фактическое значение, category = '4')
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 13 AND tg_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    $cell19 = $blockId == 7 ? 'E19' : 'F19';
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell19, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell19]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Фактический УРТ для blockId=$blockId, param_id=13, cell=$cell19, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 9) {
                    // Для "по Блокам": G39 = (E39*E11+F11*F39)/G11 (взвешенное среднее)
                    $e39 = calculateUrtParameterValue(36, 7, $date, $shiftId, $rowNum);
                    $f39 = calculateUrtParameterValue(36, 8, $date, $shiftId, $rowNum);
                    
                    // Получаем E11/F11/G11 (часы работы) - param_id = 28
                    $e11 = calculateUrtParameterValue(5, 7, $date, $shiftId, $rowNum); // Число часов работы для ТГ7
                    $f11 = calculateUrtParameterValue(5, 8, $date, $shiftId, $rowNum); // Число часов работы для ТГ8
                    $g11 = calculateUrtParameterValue(5, 9, $date, $shiftId, $rowNum); // Число часов работы для ОЧ-130
                    
                    if ($e39 !== null && $f39 !== null && $e11 !== null && $f11 !== null && $g11 !== null && $g11 > 0) {
                        $value = ($e39 * $e11 + $f11 * $f39) / $g11;
                        error_log("URT calculateUrtParameterValue: Фактический УРТ для 'по Блокам', E39=$e39, E11=$e11, F39=$f39, F11=$f11, G11=$g11, значение=$value");
                        return $value;
                    }
                    return null;
                }
                elseif ($blockId == 1 || $blockId == 2) {
                    // Для ПГУ1 и ПГУ2: L39/O39 = F83/G83 из pgu_result_values (param_id = 73, row_num = 83, "Фактический удельный расход топлива на отпуск электроэнергии с учётом пусков")
                    $cell83 = $blockId == 1 ? 'F83' : 'G83';
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_result_values 
                        WHERE param_id = 73 AND pgu_id = ? AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$blockId, $date, $cell83, $shiftId]);
                    } else {
                        $stmt->execute([$blockId, $date, $cell83]);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $value = $result ? (float)$result['value'] : null;
                    error_log("URT calculateUrtParameterValue: Фактический УРТ для ПГУ blockId=$blockId, param_id=73, cell=$cell83, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=" . ($value ?? 'NULL'));
                    return $value;
                }
                elseif ($blockId == 3) {
                    // Для "по ПГУ": Q39 = H83 из pgu_result_values для ОЧ-130 (pgu_id = 3) или сумма ПГУ1 и ПГУ2
                    $stmt = $db->prepare('
                        SELECT value FROM pgu_result_values 
                        WHERE param_id = 73 AND pgu_id = 3 AND date = ? AND cell = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    
                    if ($shiftId) {
                        $stmt->execute([$date, 'H83', $shiftId]);
                    } else {
                        $stmt->execute([$date, 'H83']);
                    }
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result && $result['value'] !== null) {
                        $value = (float)$result['value'];
                        error_log("URT calculateUrtParameterValue: Фактический УРТ для 'по ПГУ', param_id=73, cell=H83, date=$date, shiftId=" . ($shiftId ?? 'NULL') . ", значение=$value");
                        return $value;
                    }
                    
                    // Если нет значения для ОЧ-130, берем сумму ПГУ1 и ПГУ2
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
                    // Для "по Станции": S39 = (J39*J7+Q7*Q39)/S7
                    // J39 - фактический УРТ для "по Блокам" (param_id = 36, block_id = 9)
                    // J7 - выработка электроэнергии для "по Блокам" (param_id = 1, block_id = 9)
                    // Q39 - фактический УРТ для "по ПГУ" (param_id = 36, block_id = 3)
                    // Q7 - выработка электроэнергии для "по ПГУ" (param_id = 1, block_id = 3)
                    // S7 - выработка электроэнергии для "по Станции" (param_id = 1, block_id = 5)
                    $j39 = calculateUrtParameterValue(36, 9, $date, $shiftId, $rowNum);
                    $j7 = calculateUrtParameterValue(1, 9, $date, $shiftId, $rowNum);
                    $q39 = calculateUrtParameterValue(36, 3, $date, $shiftId, $rowNum);
                    $q7 = calculateUrtParameterValue(1, 3, $date, $shiftId, $rowNum);
                    $s7 = calculateUrtParameterValue(1, 5, $date, $shiftId, $rowNum);
                    
                    if ($j39 !== null && $j7 !== null && $q39 !== null && $q7 !== null && $s7 !== null && $s7 > 0) {
                        $value = ($j39 * $j7 + $q7 * $q39) / $s7;
                        error_log("URT calculateUrtParameterValue: Фактический УРТ для 'по Станции', J39=$j39, J7=$j7, Q39=$q39, Q7=$q7, S7=$s7, значение=$value");
                        return $value;
                    }
                    return null;
                }
                else {
                    return null;
                }
                break;
                
            // Фактический УРТ с учётом ФЭС
            case 37:
                if ($blockId == 7 || $blockId == 8 || $blockId == 9 || $blockId == 1 || $blockId == 2 || $blockId == 3) {
                    // Для ТГ7, ТГ8, "по Блокам", ПГУ1, ПГУ2, "по ПГУ" - равен фактическому УРТ (без учета ФЭС)
                    return calculateUrtParameterValue(36, $blockId, $date, $shiftId, $rowNum);
                }
                elseif ($blockId == 5) {
                    // Для "по Станции": S40 = S39 * (1 - R7/S7)
                    // S39 - фактический УРТ для "по Станции" (param_id = 36, block_id = 5)
                    // R7 - выработка электроэнергии ФЭС (param_id = 1, block_id = 4)
                    // S7 - выработка электроэнергии для "по Станции" (param_id = 1, block_id = 5)
                    $s39 = calculateUrtParameterValue(36, 5, $date, $shiftId, $rowNum);
                    $r7 = calculateUrtParameterValue(1, 4, $date, $shiftId, $rowNum); // Выработка ФЭС
                    $s7 = calculateUrtParameterValue(1, 5, $date, $shiftId, $rowNum); // Выработка "по Станции"
                    
                    error_log("URT calculateUrtParameterValue: Расчет param_id=37 для 'по Станции', S39=" . ($s39 ?? 'NULL') . ", R7=" . ($r7 ?? 'NULL') . ", S7=" . ($s7 ?? 'NULL'));
                    
                    if ($s39 !== null && $s7 !== null && $s7 > 0) {
                        // Если R7 равно null или 0, то формула упрощается до S39
                        if ($r7 === null || $r7 == 0) {
                            $value = $s39;
                            error_log("URT calculateUrtParameterValue: Фактический УРТ с учётом ФЭС для 'по Станции' (R7=0 или null), S39=$s39, значение=$value");
                        } else {
                            $value = $s39 * (1 - $r7 / $s7);
                            error_log("URT calculateUrtParameterValue: Фактический УРТ с учётом ФЭС для 'по Станции', S39=$s39, R7=$r7, S7=$s7, значение=$value");
                        }
                        return $value;
                    }
                    error_log("URT calculateUrtParameterValue: Не удалось рассчитать param_id=37 для 'по Станции', S39=" . ($s39 ?? 'NULL') . ", S7=" . ($s7 ?? 'NULL'));
                    return null;
                }
                else {
                    return null;
                }
                break;
            
            // Экономия топлива (-) / Пережог топлива (+)
            case 38:
                // Формула: E41/H41/J41/L41/O41/Q41/S41 = E39/H39/J39/L39/O39/Q39/S39 - E38/H38/J38/L38/O38/Q38/S38
                // E39/H39/J39/L39/O39/Q39/S39 - фактический УРТ (param_id = 36)
                // E38/H38/J38/L38/O38/Q38/S38 - нормативное значение (param_id = 35)
                $factValue = calculateUrtParameterValue(36, $blockId, $date, $shiftId, $rowNum); // Фактический УРТ
                $normValue = calculateUrtParameterValue(35, $blockId, $date, $shiftId, $rowNum); // Нормативное значение
                
                if ($factValue !== null && $normValue !== null) {
                    $value = $factValue - $normValue;
                    error_log("URT calculateUrtParameterValue: Экономия топлива для blockId=$blockId, фактический УРТ=$factValue, нормативное значение=$normValue, экономия=$value");
                    return $value;
                }
                error_log("URT calculateUrtParameterValue: Не удалось рассчитать param_id=38 для blockId=$blockId, фактический УРТ=" . ($factValue ?? 'NULL') . ", нормативное значение=" . ($normValue ?? 'NULL'));
                return null;
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