<?php
/**
 * Blocks (TG) Calculations Controller - Skeleton
 */

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/blocks_results_controller.php';

/**
 * Main entry point for Blocks calculations
 */
function performBlocksFullCalculation() {
    requireAuth();

    try {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['periodType']) || !isset($data['dates'])) {
            sendError('Необходимо указать тип периода и даты', 400);
        }

        $periodType = $data['periodType'];
        $calculatedParams = 0;
        
        if ($periodType === 'shift') {
            $date = $data['dates']['selectedDate'];
            $shifts = $data['shifts'] ?? [1, 2, 3];
            error_log("Shifts received: " . json_encode($shifts));
            // Убеждаемся, что смены - это числа
            $shifts = array_map(function($shift) {
                if (is_string($shift) && preg_match('/shift(\d+)/', $shift, $matches)) {
                    return (int)$matches[1];
                }
                return (int)$shift;
            }, $shifts);
            error_log("Shifts after conversion: " . json_encode($shifts));
            $values = calculateBlocksValues($date, 'shift', $shifts);
            $calculatedParams = count($values);
            
            if (!empty($values)) {
                saveBlocksResultValues([
                    'date' => $date,
                    'periodType' => 'shift',
                    'values' => $values
                ]);
            }
        } elseif ($periodType === 'day') {
            $date = $data['dates']['selectedDate'];
            $values = calculateBlocksValues($date, 'day');
            $calculatedParams = count($values);
            
            if (!empty($values)) {
                saveBlocksResultValues([
                    'date' => $date,
                    'periodType' => 'day',
                    'values' => $values
                ]);
            }
        } else { // period
            $start = $data['dates']['startDate'];
            $end = $data['dates']['endDate'];
            $values = calculateBlocksValues($start, 'period', null, $end);
            $calculatedParams = count($values);
            
            if (!empty($values)) {
                saveBlocksResultValues([
                    'date' => $start,
                    'periodType' => 'period',
                    'period_start' => $start,
                    'period_end' => $end,
                    'values' => $values
                ]);
            }
        }

        sendSuccess([
            'message' => 'Расчеты Блоков выполнены',
            'results' => $calculatedParams,
            'calculatedParams' => $calculatedParams
        ]);
    } catch (Exception $e) {
        sendError('Ошибка при выполнении расчетов Блоков: ' . $e->getMessage());
    }
}

/**
 * Расчет значений для блоков
 */
function calculateBlocksValues($date, $periodType, $shifts = null, $endDate = null) {
    $values = [];
    
    // Блоки: 7 (ТГ7), 8 (ТГ8), 9 (ОЧ-130)
    $blockIds = [7, 8, 9];
    
    if ($periodType === 'shift') {
        foreach ($shifts as $shiftId) {
            foreach ($blockIds as $blockId) {
                // 1. Выработка электроэнергии (param_id = 26)
                $generation = getElectricityGeneration($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 11); // row_num 11
                $values[] = [
                    'param_id' => 26, // Выработка электроэнергии
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $generation,
                    'cell' => $cell
                ];
                
                // 2. Отпуск электроэнергии (param_id = 27)
                $electricityRelease = calculateElectricityRelease($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 13); // row_num 13
                $values[] = [
                    'param_id' => 27, // Отпуск электроэнергии с шин
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $electricityRelease,
                    'cell' => $cell
                ];
                
                // 3. Число часов работы (param_id = 28)
                $workingHours = getWorkingHours($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 15); // row_num 15
                $values[] = [
                    'param_id' => 28, // Число часов работы
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $workingHours,
                    'cell' => $cell
                ];
                
                // 4. Расход питательной воды (param_id = 29)
                $feedwaterFlow = getFeedwaterFlow($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 17); // row_num 17
                $values[] = [
                    'param_id' => 29, // Расход питательной воды
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $feedwaterFlow,
                    'cell' => $cell
                ];
                
                // 5. Количество пусков (param_id = 30)
                $startCount = getStartCount($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 18); // row_num 18
                $values[] = [
                    'param_id' => 30, // Количество пусков
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $startCount,
                    'cell' => $cell
                ];
                
                // 6. Исходно-нормативный расход пара в конденсатор (param_id = 37) - ПЕРЕМЕЩЕН ВПЕРЕД
                $condenserSteamFlow = calculateCondenserSteamFlow($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 30); // row_num 30
                $values[] = [
                    'param_id' => 37, // Исходно-нормативный расход пара в конденсатор
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $condenserSteamFlow,
                    'cell' => $cell
                ];
                
                // 7. Средний расход охлаждающей воды (param_id = 31)
                $coolingWaterFlow = calculateCoolingWaterFlow($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 19); // row_num 19
                $values[] = [
                    'param_id' => 31, // Средний расход охлаждающей воды
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $coolingWaterFlow,
                    'cell' => $cell
                ];
                
                // 7. Средняя температура охлаждающей воды на входе в конденсатор (param_id = 32)
                $coolingWaterTemp = calculateCoolingWaterTemperature($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 20); // row_num 20
                $values[] = [
                    'param_id' => 32, // Средняя температура охлаждающей воды на входе в конденсатор
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $coolingWaterTemp,
                    'cell' => $cell
                ];
                
                // 8. Следующий параметр (param_id = 33)
                $nextParam = calculateNextParameter($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 21); // row_num 21
                $values[] = [
                    'param_id' => 33, // Следующий параметр
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $nextParam,
                    'cell' => $cell
                ];
                
                // 9. Продолжительность работы турбоагрегата от даты разработки нормативной характеристики (param_id = 34)
                $operationDuration = getOperationDuration($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 22); // row_num 22
                $values[] = [
                    'param_id' => 34, // Продолжительность работы турбоагрегата от даты разработки нормативной характеристики
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $operationDuration,
                    'cell' => $cell
                ];
                
                // 10. Средняя электрическая нагрузка турбоагрегата (факт) (param_id = 35)
                $avgElectricLoad = calculateAvgElectricLoad($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 26); // row_num 26
                $values[] = [
                    'param_id' => 35, // Средняя электрическая нагрузка турбоагрегата (факт)
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $avgElectricLoad,
                    'cell' => $cell
                ];
                
                // 11. Исходно-нормативный расход свежего пара (param_id = 36)
                $freshSteamFlow = calculateFreshSteamFlow($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 29); // row_num 29
                $values[] = [
                    'param_id' => 36, // Исходно-нормативный расход свежего пара
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $freshSteamFlow,
                    'cell' => $cell
                ];
                
                // 12. Исходно-нормативный расход пара в конденсатор (param_id = 37) - УДАЛЕН, ПЕРЕМЕЩЕН В 6-е место
                
                // 13. Исходно-нормативное значение удельного расхода тепла брутто на турбоагрегат (param_id = 38)
                $specificHeatConsumption = calculateSpecificHeatConsumption($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 31); // row_num 31
                $values[] = [
                    'param_id' => 38, // Исходно-нормативное значение удельного расхода тепла брутто на турбоагрегат
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $specificHeatConsumption,
                    'cell' => $cell
                ];
                
                // 14. Температуры охлаждающей воды (param_id = 39)
                $coolingWaterTemperature = calculateCoolingWaterTemperatureEffect($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 33); // row_num 33
                $values[] = [
                    'param_id' => 39, // Температуры охлаждающей воды
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $coolingWaterTemperature,
                    'cell' => $cell
                ];
                
                // 15. Изменения расхода охлаждающей воды (param_id = 40)
                $coolingWaterFlowChange = calculateCoolingWaterFlowChange($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 34); // row_num 34
                $values[] = [
                    'param_id' => 40, // Изменения расхода охлаждающей воды
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $coolingWaterFlowChange,
                    'cell' => $cell
                ];
                
                // 16. Параметр E35/F35 (param_id = 41)
                $parameter35 = calculateParameter35($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 35); // row_num 35
                $values[] = [
                    'param_id' => 41, // Параметр E35/F35
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter35,
                    'cell' => $cell
                ];
                
                // 17. Параметр E36 (param_id = 42)
                $parameter36 = calculateParameter36($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 36); // row_num 36
                $values[] = [
                    'param_id' => 42, // Параметр E36
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter36,
                    'cell' => $cell
                ];
                
                // 18. Константа E37/F37 (param_id = 43)
                $constant37 = calculateConstant37($blockId);
                $cell = getCellForBlock($blockId, 37); // row_num 37
                $values[] = [
                    'param_id' => 43, // Константа E37/F37
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $constant37,
                    'cell' => $cell
                ];
                
                // 19. Параметр E39/F39/G39 (param_id = 44)
                $parameter39 = calculateParameter39($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 39); // row_num 39
                $values[] = [
                    'param_id' => 44, // Параметр E39/F39/G39
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter39,
                    'cell' => $cell
                ];
                
                // 20. Параметр E41/F41/G41 (param_id = 45)
                $parameter41 = calculateParameter41($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 41); // row_num 41
                $values[] = [
                    'param_id' => 45, // Параметр E41/F41/G41
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter41,
                    'cell' => $cell
                ];
                
                // 21. Параметр E42/F42 (param_id = 46)
                $parameter42 = calculateParameter42($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 42); // row_num 42
                $values[] = [
                    'param_id' => 46, // Параметр E42/F42
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter42,
                    'cell' => $cell
                ];
                
                // 22. Константа E44/F44 (param_id = 47)
                $constant44 = calculateConstant44($blockId);
                $cell = getCellForBlock($blockId, 44); // row_num 44
                $values[] = [
                    'param_id' => 47, // Константа E44/F44
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $constant44,
                    'cell' => $cell
                ];
                
                // 23. Параметр E45/F45 (param_id = 48)
                $parameter45 = calculateParameter45($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 45); // row_num 45
                $values[] = [
                    'param_id' => 48, // Параметр E45/F45
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter45,
                    'cell' => $cell
                ];
                
                // 24. Параметр E48/F48/G48 (param_id = 49)
                $parameter48 = calculateParameter48($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 48); // row_num 48
                $values[] = [
                    'param_id' => 49, // Параметр E48/F48/G48
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter48,
                    'cell' => $cell
                ];
                
                // 25. Параметр E49/F49/G49 (param_id = 50)
                $parameter49 = calculateParameter49($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 49); // row_num 49
                $values[] = [
                    'param_id' => 50, // Параметр E49/F49/G49
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter49,
                    'cell' => $cell
                ];
                
                // 26. Параметр E50/F50/G50 (param_id = 51)
                $parameter50 = calculateParameter50($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 50); // row_num 50
                $values[] = [
                    'param_id' => 51, // Параметр E50/F50/G50
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter50,
                    'cell' => $cell
                ];
                
                // 27. Параметр E51/F51/G51 (param_id = 52)
                $parameter51 = calculateParameter51($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 51); // row_num 51
                $values[] = [
                    'param_id' => 52, // Параметр E51/F51/G51
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter51,
                    'cell' => $cell
                ];
                
                // 28. Параметр E52/F52/G52 (param_id = 53)
                $parameter52 = calculateParameter52($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 52); // row_num 52
                $values[] = [
                    'param_id' => 53, // Параметр E52/F52/G52
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $parameter52,
                    'cell' => $cell
                ];
            }
        }
    } else {
        foreach ($blockIds as $blockId) {
            // 1. Выработка электроэнергии (param_id = 26)
            $generation = getElectricityGeneration($date, null, $blockId);
            $cell = getCellForBlock($blockId, 11); // row_num 11
            $values[] = [
                'param_id' => 26, // Выработка электроэнергии
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $generation,
                'cell' => $cell
            ];
            
            // 2. Отпуск электроэнергии (param_id = 27)
            $electricityRelease = calculateElectricityRelease($date, null, $blockId);
            $cell = getCellForBlock($blockId, 13); // row_num 13
            $values[] = [
                'param_id' => 27, // Отпуск электроэнергии с шин
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $electricityRelease,
                'cell' => $cell
            ];
            
            // 3. Число часов работы (param_id = 28)
            $workingHours = getWorkingHours($date, null, $blockId);
            $cell = getCellForBlock($blockId, 15); // row_num 15
            $values[] = [
                'param_id' => 28, // Число часов работы
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $workingHours,
                'cell' => $cell
            ];
            
            // 4. Расход питательной воды (param_id = 29)
            $feedwaterFlow = getFeedwaterFlow($date, null, $blockId);
            $cell = getCellForBlock($blockId, 17); // row_num 17
            $values[] = [
                'param_id' => 29, // Расход питательной воды
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $feedwaterFlow,
                'cell' => $cell
            ];
            
            // 5. Количество пусков (param_id = 30)
            $startCount = getStartCount($date, null, $blockId);
            $cell = getCellForBlock($blockId, 18); // row_num 18
            $values[] = [
                'param_id' => 30, // Количество пусков
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $startCount,
                'cell' => $cell
            ];
            
            // 6. Исходно-нормативный расход пара в конденсатор (param_id = 37) - ПЕРЕМЕЩЕН ВПЕРЕД
            $condenserSteamFlow = calculateCondenserSteamFlow($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 30); // row_num 30
            $values[] = [
                'param_id' => 37, // Исходно-нормативный расход пара в конденсатор
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $condenserSteamFlow,
                'cell' => $cell
            ];
            
            // 7. Средний расход охлаждающей воды (param_id = 31)
            $coolingWaterFlow = calculateCoolingWaterFlow($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 19); // row_num 19
            $values[] = [
                'param_id' => 31, // Средний расход охлаждающей воды
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $coolingWaterFlow,
                'cell' => $cell
            ];
            
            // 7. Средняя температура охлаждающей воды на входе в конденсатор (param_id = 32)
            $coolingWaterTemp = calculateCoolingWaterTemperature($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 20); // row_num 20
            $values[] = [
                'param_id' => 32, // Средняя температура охлаждающей воды на входе в конденсатор
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $coolingWaterTemp,
                'cell' => $cell
            ];
            
            // 8. Следующий параметр (param_id = 33)
            $nextParam = calculateNextParameter($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 21); // row_num 21
            $values[] = [
                'param_id' => 33, // Следующий параметр
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $nextParam,
                'cell' => $cell
            ];
            
            // 9. Продолжительность работы турбоагрегата от даты разработки нормативной характеристики (param_id = 34)
            $operationDuration = getOperationDuration($date, null, $blockId);
            $cell = getCellForBlock($blockId, 22); // row_num 22
            $values[] = [
                'param_id' => 34, // Продолжительность работы турбоагрегата от даты разработки нормативной характеристики
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $operationDuration,
                'cell' => $cell
            ];
            
            // 10. Средняя электрическая нагрузка турбоагрегата (факт) (param_id = 35)
            $avgElectricLoad = calculateAvgElectricLoad($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 26); // row_num 26
            $values[] = [
                'param_id' => 35, // Средняя электрическая нагрузка турбоагрегата (факт)
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $avgElectricLoad,
                'cell' => $cell
            ];
            
            // 11. Исходно-нормативный расход свежего пара (param_id = 36)
            $freshSteamFlow = calculateFreshSteamFlow($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 29); // row_num 29
            $values[] = [
                'param_id' => 36, // Исходно-нормативный расход свежего пара
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $freshSteamFlow,
                'cell' => $cell
            ];
            
            // 12. Исходно-нормативный расход пара в конденсатор (param_id = 37) - УДАЛЕН, ПЕРЕМЕЩЕН В 6-е место
            
            // 13. Исходно-нормативное значение удельного расхода тепла брутто на турбоагрегат (param_id = 38)
            $specificHeatConsumption = calculateSpecificHeatConsumption($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 31); // row_num 31
            $values[] = [
                'param_id' => 38, // Исходно-нормативное значение удельного расхода тепла брутто на турбоагрегат
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $specificHeatConsumption,
                'cell' => $cell
            ];
            
            // 14. Температуры охлаждающей воды (param_id = 39)
            $coolingWaterTemperature = calculateCoolingWaterTemperatureEffect($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 33); // row_num 33
            $values[] = [
                'param_id' => 39, // Температуры охлаждающей воды
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $coolingWaterTemperature,
                'cell' => $cell
            ];
            
            // 15. Изменения расхода охлаждающей воды (param_id = 40)
            $coolingWaterFlowChange = calculateCoolingWaterFlowChange($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 34); // row_num 34
            $values[] = [
                'param_id' => 40, // Изменения расхода охлаждающей воды
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $coolingWaterFlowChange,
                'cell' => $cell
            ];
            
            // 16. Параметр E35/F35 (param_id = 41)
            $parameter35 = calculateParameter35($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 35); // row_num 35
            $values[] = [
                'param_id' => 41, // Параметр E35/F35
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter35,
                'cell' => $cell
            ];
            
            // 17. Параметр E36 (param_id = 42)
            $parameter36 = calculateParameter36($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 36); // row_num 36
            $values[] = [
                'param_id' => 42, // Параметр E36
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter36,
                'cell' => $cell
            ];
            
            // 18. Константа E37/F37 (param_id = 43)
            $constant37 = calculateConstant37($blockId);
            $cell = getCellForBlock($blockId, 37); // row_num 37
            $values[] = [
                'param_id' => 43, // Константа E37/F37
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $constant37,
                'cell' => $cell
            ];
            
            // 19. Параметр E39/F39/G39 (param_id = 44)
            $parameter39 = calculateParameter39($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 39); // row_num 39
            $values[] = [
                'param_id' => 44, // Параметр E39/F39/G39
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter39,
                'cell' => $cell
            ];
            
            // 20. Параметр E41/F41/G41 (param_id = 45)
            $parameter41 = calculateParameter41($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 41); // row_num 41
            $values[] = [
                'param_id' => 45, // Параметр E41/F41/G41
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter41,
                'cell' => $cell
            ];
            
            // 21. Параметр E42/F42 (param_id = 46)
            $parameter42 = calculateParameter42($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 42); // row_num 42
            $values[] = [
                'param_id' => 46, // Параметр E42/F42
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter42,
                'cell' => $cell
            ];
            
            // 22. Константа E44/F44 (param_id = 47)
            $constant44 = calculateConstant44($blockId);
            $cell = getCellForBlock($blockId, 44); // row_num 44
            $values[] = [
                'param_id' => 47, // Константа E44/F44
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $constant44,
                'cell' => $cell
            ];
            
            // 23. Параметр E45/F45 (param_id = 48)
            $parameter45 = calculateParameter45($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 45); // row_num 45
            $values[] = [
                'param_id' => 48, // Параметр E45/F45
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter45,
                'cell' => $cell
            ];
            
            // 24. Параметр E48/F48/G48 (param_id = 49)
            $parameter48 = calculateParameter48($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 48); // row_num 48
            $values[] = [
                'param_id' => 49, // Параметр E48/F48/G48
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter48,
                'cell' => $cell
            ];
            
            // 25. Параметр E49/F49/G49 (param_id = 50)
            $parameter49 = calculateParameter49($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 49); // row_num 49
            $values[] = [
                'param_id' => 50, // Параметр E49/F49/G49
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter49,
                'cell' => $cell
            ];
            
            // 26. Параметр E50/F50/G50 (param_id = 51)
            $parameter50 = calculateParameter50($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 50); // row_num 50
            $values[] = [
                'param_id' => 51, // Параметр E50/F50/G50
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter50,
                'cell' => $cell
            ];
            
            // 27. Параметр E51/F51/G51 (param_id = 52)
            $parameter51 = calculateParameter51($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 51); // row_num 51
            $values[] = [
                'param_id' => 52, // Параметр E51/F51/G51
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter51,
                'cell' => $cell
            ];
            
            // 28. Параметр E52/F52/G52 (param_id = 53)
            $parameter52 = calculateParameter52($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 52); // row_num 52
            $values[] = [
                'param_id' => 53, // Параметр E52/F52/G52
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $parameter52,
                'cell' => $cell
            ];
        }
    }
    
    return $values;
}

/**
 * Расчет отпуска электроэнергии: Эот = Эта - Эсн - Эхн
 * Где:
 * Эот - Отпуск электроэнергии с шин
 * Эта - Выработка электроэнергии турбоагрегатом
 * Эсн - Расход на собственные нужды
 * Эхн - Расход на хозяйственные нужды
 */
function calculateElectricityRelease($date, $shiftId, $blockId) {
    try {
        $db = getDbConnection();
        
        // Получаем выработку электроэнергии (Эта)
        $generation = getElectricityGeneration($date, $shiftId, $blockId);
        if ($generation === null) {
            error_log("Нет данных о выработке для блока $blockId, смена $shiftId, дата $date");
            return 0; // Возвращаем 0 вместо null
        }
        
        // Получаем расход на собственные нужды (Эсн)
        $ownNeeds = getOwnNeedsConsumption($date, $shiftId, $blockId);
        
        // Получаем расход на хозяйственные нужды (Эхн)
        $householdNeeds = getHouseholdNeedsConsumption($date, $shiftId, $blockId);
        
        // Рассчитываем отпуск: Эот = Эта - Эсн - Эхн
        $release = $generation - $ownNeeds - $householdNeeds;
        
        error_log("Расчет отпуска для блока $blockId, смена $shiftId: выработка=$generation, собственные нужды=$ownNeeds, хозяйственные нужды=$householdNeeds, отпуск=$release");
        
        return max(0, $release); // Не может быть отрицательным
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете отпуска электроэнергии: ' . $e->getMessage());
        return null;
    }
}

/**
 * Получение выработки электроэнергии (Эта)
 */
function getElectricityGeneration($date, $shiftId, $blockId) {
    // Маппинг blockId на equipment_id
    $equipmentMap = [7 => 1, 8 => 2, 9 => 1]; // ТГ7->1, ТГ8->2, ОЧ-130->1 (сумма ТГ7+ТГ8)
    
    if (!isset($equipmentMap[$blockId])) {
        return null;
    }
    
    $equipmentId = $equipmentMap[$blockId];
    
    if ($blockId == 9) {
        // ОЧ-130 = ТГ7 + ТГ8
        $tg7Generation = getElectricityGenerationForBlock($date, $shiftId, 1); // equipment_id = 1 для ТГ7
        $tg8Generation = getElectricityGenerationForBlock($date, $shiftId, 2); // equipment_id = 2 для ТГ8
        return ($tg7Generation ?? 0) + ($tg8Generation ?? 0);
    }
    
    return getElectricityGenerationForBlock($date, $shiftId, $equipmentId);
}

/**
 * Получение выработки электроэнергии для конкретного оборудования
 */
function getElectricityGenerationForBlock($date, $shiftId, $equipmentId) {
    try {
        $db = getDbConnection();
        
        // Получаем показания счетчиков выработки для блока
        $stmt = $db->prepare('
            SELECT mr.shift1, mr.shift2, mr.shift3, mr.total
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE m.equipment_id = ? AND m.meter_type_id = 1 AND mr.date = ?
        ');
        $stmt->execute([$equipmentId, $date]);
        $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($readings)) {
            return 0; // Возвращаем 0 вместо null, если нет данных
        }
        
        // Суммируем выработку по всем счетчикам блока
        $totalGeneration = 0;
        foreach ($readings as $reading) {
            if ($shiftId !== null) {
                // Для смены
                $shiftField = 'shift' . $shiftId;
                $value = $reading[$shiftField] ?? 0;
                if ($value !== null && $value > 0) {
                    $totalGeneration += (float)$value;
                }
            } else {
                // Для суточного/периодного расчета
                $value = $reading['total'] ?? 0;
                if ($value !== null && $value > 0) {
                    $totalGeneration += (float)$value;
                }
            }
        }
        
        return $totalGeneration;
        
    } catch (Exception $e) {
        error_log('Ошибка при получении выработки электроэнергии: ' . $e->getMessage());
        return null;
    }
}

/**
 * Получение расхода на собственные нужды (Эсн)
 */
function getOwnNeedsConsumption($date, $shiftId, $blockId) {
    try {
        $db = getDbConnection();
        
        // Маппинг blockId на equipment_id для собственных нужд
        $equipmentMap = [7 => 1, 8 => 2, 9 => null]; // ОЧ-130 не имеет собственных нужд
        
        if (!isset($equipmentMap[$blockId]) || $equipmentMap[$blockId] === null) {
            return 0;
        }
        
        $equipmentId = $equipmentMap[$blockId];
        
        // Получаем показания счетчиков собственных нужд для блока
        $stmt = $db->prepare('
            SELECT mr.shift1, mr.shift2, mr.shift3, mr.total
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE m.equipment_id = ? AND m.meter_type_id = 2 AND mr.date = ?
        ');
        $stmt->execute([$equipmentId, $date]);
        $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($readings)) {
            return 0;
        }
        
        // Суммируем расход по всем счетчикам собственных нужд блока
        $totalConsumption = 0;
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
        
        return $totalConsumption;
        
    } catch (Exception $e) {
        error_log('Ошибка при получении расхода на собственные нужды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Получение числа часов работы блока
 */
function getWorkingHours($date, $shiftId, $blockId) {
    try {
        $db = getDbConnection();
        
        // Маппинг blockId на equipment_id
        $equipmentMap = [7 => 1, 8 => 2, 9 => 1]; // ТГ7->1, ТГ8->2, ОЧ-130->1 (сумма ТГ7+ТГ8)
        
        if (!isset($equipmentMap[$blockId])) {
            return 0;
        }
        
        $equipmentId = $equipmentMap[$blockId];
        
        if ($blockId == 9) {
            // ОЧ-130 = ТГ7 + ТГ8
            $tg7Hours = getWorkingHoursForBlock($date, $shiftId, 1);
            $tg8Hours = getWorkingHoursForBlock($date, $shiftId, 2);
            return $tg7Hours + $tg8Hours;
        }
        
        return getWorkingHoursForBlock($date, $shiftId, $equipmentId);
        
    } catch (Exception $e) {
        error_log('Ошибка при получении числа часов работы: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Получение числа часов работы для конкретного оборудования
 */
function getWorkingHoursForBlock($date, $shiftId, $equipmentId) {
    try {
        $db = getDbConnection();
        
        // Определяем временные границы для выбранного периода
        if ($shiftId !== null) {
            // Для смены
            $shiftStartTimes = [1 => '00:00:00', 2 => '08:00:00', 3 => '16:00:00'];
            $shiftEndTimes = [1 => '08:00:00', 2 => '16:00:00', 3 => '24:00:00'];
            $periodStart = strtotime($date . ' ' . $shiftStartTimes[$shiftId]);
            $periodEnd = strtotime($date . ' ' . $shiftEndTimes[$shiftId]);
        } else {
            // Для суточного расчета
            $periodStart = strtotime($date . ' 00:00:00');
            $periodEnd = strtotime($date . ' 23:59:59');
        }
        
        // Находим последний запуск ДО или В начале выбранного периода
        $stmt = $db->prepare('
            SELECT event_time
            FROM equipment_events 
            WHERE equipment_id = ? AND event_type = "pusk" AND event_time <= ?
            ORDER BY event_time DESC
            LIMIT 1
        ');
        $stmt->execute([$equipmentId, date('Y-m-d H:i:s', $periodStart)]);
        $lastStart = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lastStart) {
            return 0; // Нет запусков
        }
        
        $startTime = strtotime($lastStart['event_time']);
        
        // Находим первую остановку ПОСЛЕ начала выбранного периода
        $stmt = $db->prepare('
            SELECT event_time
            FROM equipment_events 
            WHERE equipment_id = ? AND event_type = "ostanov" AND event_time > ?
            ORDER BY event_time ASC
            LIMIT 1
        ');
        $stmt->execute([$equipmentId, date('Y-m-d H:i:s', $periodStart)]);
        $firstStop = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($firstStop) {
            $endTime = strtotime($firstStop['event_time']);
        } else {
            // Если остановки не было, считаем до конца периода или до текущего времени
            $currentTime = time();
            $endTime = min($periodEnd, $currentTime);
        }
        
        // Рассчитываем время работы в выбранном периоде
        $workStart = max($startTime, $periodStart); // Начало работы в периоде
        $workEnd = min($endTime, $periodEnd); // Конец работы в периоде
        
        if ($workStart >= $workEnd) {
            return 0; // Нет работы в выбранном периоде
        }
        
        $hours = ($workEnd - $workStart) / 3600; // Переводим в часы
        
        error_log("Расчет часов работы для оборудования $equipmentId: период " . date('Y-m-d H:i:s', $periodStart) . " - " . date('Y-m-d H:i:s', $periodEnd) . ", работа " . date('Y-m-d H:i:s', $workStart) . " - " . date('Y-m-d H:i:s', $workEnd) . ", часов: $hours");
        
        return round($hours, 2);
        
    } catch (Exception $e) {
        error_log('Ошибка при получении числа часов работы для оборудования: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Получение расхода питательной воды
 */
function getFeedwaterFlow($date, $shiftId, $blockId) {
    try {
        $db = getDbConnection();
        
        // Маппинг blockId на equipment_id
        $equipmentMap = [7 => 1, 8 => 2, 9 => 1]; // ТГ7->1, ТГ8->2, ОЧ-130->1 (сумма ТГ7+ТГ8)
        
        if (!isset($equipmentMap[$blockId])) {
            return 0;
        }
        
        $equipmentId = $equipmentMap[$blockId];
        
        if ($blockId == 9) {
            // ОЧ-130 = ТГ7 + ТГ8
            $tg7Flow = getFeedwaterFlowForBlock($date, $shiftId, 1);
            $tg8Flow = getFeedwaterFlowForBlock($date, $shiftId, 2);
            return $tg7Flow + $tg8Flow;
        }
        
        return getFeedwaterFlowForBlock($date, $shiftId, $equipmentId);
        
    } catch (Exception $e) {
        error_log('Ошибка при получении расхода питательной воды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Получение расхода питательной воды для конкретного оборудования
 */
function getFeedwaterFlowForBlock($date, $shiftId, $equipmentId) {
    try {
        $db = getDbConnection();
        
        // Получаем значение расхода питательной воды из parameter_values
        // parameter_id = 37 (row_num C22)
        if ($shiftId !== null) {
            $stmt = $db->prepare('
                SELECT value
                FROM parameter_values 
                WHERE parameter_id = 37 AND equipment_id = ? AND date = ? AND shift_id = ?
            ');
            $stmt->execute([$equipmentId, $date, $shiftId]);
        } else {
            $stmt = $db->prepare('
                SELECT value
                FROM parameter_values 
                WHERE parameter_id = 37 AND equipment_id = ? AND date = ?
            ');
            $stmt->execute([$equipmentId, $date]);
        }
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return (float)$result['value'];
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при получении расхода питательной воды для оборудования: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Получение cell для блока и row_num
 */
function getCellForBlock($blockId, $rowNum) {
    $blockCells = [7 => 'E', 8 => 'F', 9 => 'G']; // ТГ7->E, ТГ8->F, ОЧ-130->G (категория 3a)
    if (!isset($blockCells[$blockId])) {
        return null;
    }
    return $blockCells[$blockId] . $rowNum;
}

/**
 * Получение количества пусков блока
 */
function getStartCount($date, $shiftId, $blockId) {
    try {
        $db = getDbConnection();
        
        // Маппинг blockId на equipment_id
        $equipmentMap = [7 => 1, 8 => 2, 9 => 1]; // ТГ7->1, ТГ8->2, ОЧ-130->1 (сумма ТГ7+ТГ8)
        
        if (!isset($equipmentMap[$blockId])) {
            return 0;
        }
        
        $equipmentId = $equipmentMap[$blockId];
        
        if ($blockId == 9) {
            // ОЧ-130 = ТГ7 + ТГ8
            $tg7Count = getStartCountForBlock($date, $shiftId, 1);
            $tg8Count = getStartCountForBlock($date, $shiftId, 2);
            return $tg7Count + $tg8Count;
        }
        
        return getStartCountForBlock($date, $shiftId, $equipmentId);
        
    } catch (Exception $e) {
        error_log('Ошибка при получении количества пусков: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет среднего расхода охлаждающей воды
 * Формула: IF(C17=0,0,E30*540/(C17-C16))
 * Для ТГ7: IF(C17=0,0,C30*540/(C17-C16))
 * Для ТГ8: IF(D17=0,0,D30*540/(D17-D16))
 * Для ОЧ-130: IF(E17=0,0,E30*540/(E17-E16))
 */
function calculateCoolingWaterFlow($date, $shiftId, $blockId, &$values) {
    try {
        $db = getDbConnection();
        
        // Определяем cell для блока
        $blockCells = [7 => 'C', 8 => 'D', 9 => 'E'];
        if (!isset($blockCells[$blockId])) {
            return 0;
        }
        
        $cellPrefix = $blockCells[$blockId];
        
        // Получаем значения из parameter_values
        // C17 (или D17, E17) - это один параметр
        // C16 (или D16, E16) - это другой параметр
        $cell17 = $cellPrefix . '17';
        $cell16 = $cellPrefix . '16';
        
        // Получаем значение из C17 (D17, E17)
        $stmt = $db->prepare('
            SELECT value
            FROM parameter_values 
            WHERE cell = ? AND date = ?
        ');
        if ($shiftId !== null) {
            $stmt = $db->prepare('
                SELECT value
                FROM parameter_values 
                WHERE cell = ? AND date = ? AND shift_id = ?
            ');
            $stmt->execute([$cell17, $date, $shiftId]);
        } else {
            $stmt->execute([$cell17, $date]);
        }
        
        $result17 = $stmt->fetch(PDO::FETCH_ASSOC);
        $value17 = $result17 ? (float)$result17['value'] : 0;
        
        // Получаем значение из C16 (D16, E16)
        if ($shiftId !== null) {
            $stmt = $db->prepare('
                SELECT value
                FROM parameter_values 
                WHERE cell = ? AND date = ? AND shift_id = ?
            ');
            $stmt->execute([$cell16, $date, $shiftId]);
        } else {
            $stmt = $db->prepare('
                SELECT value
                FROM parameter_values 
                WHERE cell = ? AND date = ?
            ');
            $stmt->execute([$cell16, $date]);
        }
        
        $result16 = $stmt->fetch(PDO::FETCH_ASSOC);
        $value16 = $result16 ? (float)$result16['value'] : 0;
        
        // Если C17 = 0, возвращаем 0
        if ($value17 == 0) {
            return 0;
        }
        
        // Получаем E30/D30/C30 (Исходно-нормативный расход пара в конденсатор, t/h) - это параметр 37
        $e30Value = getParameterValue($date, $shiftId, $blockId, 37, $values); // Параметр 37 - Исходно-нормативный расход пара в конденсатор
        
        // Отладочная информация
        error_log("calculateCoolingWaterFlow для блока $blockId, смена $shiftId, дата $date:");
        error_log("  C17/D17/E17 ($cell17) = $value17");
        error_log("  C16/D16/E16 ($cell16) = $value16");
        error_log("  E30/D30/C30 (параметр 37) = $e30Value");
        
        // Применяем формулу: E30*540/(C17-C16)
        $denominator = $value17 - $value16;
        if ($denominator == 0) {
            error_log("  Результат: 0 (C17-C16 = 0)");
            return 0;
        }
        
        $result = ($e30Value * 540) / $denominator;
        error_log("  Результат: $result (E30*540/(C17-C16) = $e30Value*540/($value17-$value16))");
        
        return round($result, 2);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете среднего расхода охлаждающей воды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет средней температуры охлаждающей воды на входе в конденсатор
 * ТГ7: копирует значение из C16 (t1в циркводы на входе)
 * ТГ8: копирует значение из D16 (t1в циркводы на входе)
 * ОЧ-130: =(E20*E26+F20*F26)/(E26+F26)
 */
function calculateCoolingWaterTemperature($date, $shiftId, $blockId, &$values) {
    try {
        $db = getDbConnection();
        
        if ($blockId == 7 || $blockId == 8) {
            // ТГ7 и ТГ8: копируем значение из C16 или D16
            $blockCells = [7 => 'C', 8 => 'D'];
            $cell16 = $blockCells[$blockId] . '16';
            
            // Получаем значение из parameter_values
            $stmt = $db->prepare('
                SELECT value
                FROM parameter_values 
                WHERE cell = ? AND date = ?
            ');
            if ($shiftId !== null) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM parameter_values 
                    WHERE cell = ? AND date = ? AND shift_id = ?
                ');
                $stmt->execute([$cell16, $date, $shiftId]);
            } else {
                $stmt->execute([$cell16, $date]);
            }
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (float)$result['value'] : 0;
            
        } elseif ($blockId == 9) {
            // ОЧ-130: =(E20*E26+F20*F26)/(E26+F26)
            
            // Получаем E20, E26, F20, F26 из parameter_values
            $cells = ['E20', 'E26', 'F20', 'F26'];
            $values_cells = [];
            
            foreach ($cells as $cell) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM parameter_values 
                    WHERE cell = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM parameter_values 
                        WHERE cell = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([$cell, $date, $shiftId]);
                } else {
                    $stmt->execute([$cell, $date]);
                }
                
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $values_cells[$cell] = $result ? (float)$result['value'] : 0;
            }
            
            $E20 = $values_cells['E20'];
            $E26 = $values_cells['E26'];
            $F20 = $values_cells['F20'];
            $F26 = $values_cells['F26'];
            
            // Применяем формулу: (E20*E26+F20*F26)/(E26+F26)
            $denominator = $E26 + $F26;
            if ($denominator == 0) {
                return 0;
            }
            
            $result = ($E20 * $E26 + $F20 * $F26) / $denominator;
            return round($result, 2);
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете средней температуры охлаждающей воды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет следующего параметра (param_id = 33)
 * ТГ7: копирует значение из C17
 * ТГ8: копирует значение из D17
 * ОЧ-130: =(E21*E26+F21*F26)/(E26+F26)
 */
function calculateNextParameter($date, $shiftId, $blockId, &$values) {
    try {
        $db = getDbConnection();
        
        if ($blockId == 7 || $blockId == 8) {
            // ТГ7 и ТГ8: копируем значение из C17 или D17
            $blockCells = [7 => 'C', 8 => 'D'];
            $cell17 = $blockCells[$blockId] . '17';
            
            // Получаем значение из parameter_values
            $stmt = $db->prepare('
                SELECT value
                FROM parameter_values 
                WHERE cell = ? AND date = ?
            ');
            if ($shiftId !== null) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM parameter_values 
                    WHERE cell = ? AND date = ? AND shift_id = ?
                ');
                $stmt->execute([$cell17, $date, $shiftId]);
            } else {
                $stmt->execute([$cell17, $date]);
            }
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (float)$result['value'] : 0;
            
        } elseif ($blockId == 9) {
            // ОЧ-130: =(E21*E26+F21*F26)/(E26+F26)
            
            // Получаем E21, E26, F21, F26 из parameter_values
            $cells = ['E21', 'E26', 'F21', 'F26'];
            $values_cells = [];
            
            foreach ($cells as $cell) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM parameter_values 
                    WHERE cell = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM parameter_values 
                        WHERE cell = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([$cell, $date, $shiftId]);
                } else {
                    $stmt->execute([$cell, $date]);
                }
                
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $values_cells[$cell] = $result ? (float)$result['value'] : 0;
            }
            
            $E21 = $values_cells['E21'];
            $E26 = $values_cells['E26'];
            $F21 = $values_cells['F21'];
            $F26 = $values_cells['F26'];
            
            // Применяем формулу: (E21*E26+F21*F26)/(E26+F26)
            $denominator = $E26 + $F26;
            if ($denominator == 0) {
                return 0;
            }
            
            $result = ($E21 * $E26 + $F21 * $F26) / $denominator;
            return round($result, 2);
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете следующего параметра: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Получение продолжительности работы турбоагрегата от даты разработки нормативной характеристики
 * ТГ7: получает значение из parameter_values где parameter_id = 49 и equipment_id = 1
 * ТГ8: получает значение из parameter_values где parameter_id = 49 и equipment_id = 2
 * ОЧ-130: не рассчитывается (возвращает 0)
 */
function getOperationDuration($date, $shiftId, $blockId) {
    try {
        // ОЧ-130 не рассчитывается
        if ($blockId == 9) {
            return 0;
        }
        
        $db = getDbConnection();
        
        // Маппинг blockId на equipment_id
        $equipmentMap = [7 => 1, 8 => 2]; // ТГ7->1, ТГ8->2
        $equipmentId = $equipmentMap[$blockId];
        
        // Получаем значение из parameter_values где parameter_id = 49
        if ($shiftId !== null) {
            $stmt = $db->prepare('
                SELECT value
                FROM parameter_values 
                WHERE parameter_id = ? AND equipment_id = ? AND date = ? AND shift_id = ?
            ');
            $stmt->execute([49, $equipmentId, $date, $shiftId]);
            error_log("getOperationDuration SQL: parameter_id=49, equipment_id=$equipmentId, date=$date, shift_id=$shiftId");
        } else {
            $stmt = $db->prepare('
                SELECT value
                FROM parameter_values 
                WHERE parameter_id = ? AND equipment_id = ? AND date = ?
            ');
            $stmt->execute([49, $equipmentId, $date]);
            error_log("getOperationDuration SQL: parameter_id=49, equipment_id=$equipmentId, date=$date, shift_id=null");
        }
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $value = 0;
        if (!empty($results)) {
            $value = (float)$results[0]['value'];
        }
        error_log("getOperationDuration: blockId=$blockId, equipmentId=$equipmentId, results=" . json_encode($results) . ", value=$value");
        return $value;
        
    } catch (Exception $e) {
        error_log('Ошибка при получении продолжительности работы турбоагрегата: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет средней электрической нагрузки турбоагрегата (факт)
 * E26=IF(E15=0,0,E11/E15)
 * F26=IF(F15=0,0,F11/F15)
 * G26=IF(G15=0,0,G11/G15)
 */
function calculateAvgElectricLoad($date, $shiftId, $blockId, &$values) {
    try {
        // Получаем значения E11 (выработка) и E15 (часы работы) из уже рассчитанных значений
        $generationValue = 0; // E11
        $workingHoursValue = 0; // E15
        
        // Ищем значения в уже рассчитанных значениях
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId) {
                if ($value['param_id'] == 26) { // Выработка электроэнергии
                    $generationValue = $value['value'];
                } elseif ($value['param_id'] == 28) { // Число часов работы
                    $workingHoursValue = $value['value'];
                }
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($generationValue == 0 || $workingHoursValue == 0) {
            $db = getDbConnection();
            
            // Получаем выработку (param_id = 26)
            if ($generationValue == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([26, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([26, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $generationValue = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем часы работы (param_id = 28)
            if ($workingHoursValue == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([28, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([28, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $workingHoursValue = $result ? (float)$result['value'] : 0;
            }
        }
        
        // Применяем формулу: IF(E15=0,0,E11/E15)
        if ($workingHoursValue == 0) {
            return 0;
        }
        
        $result = $generationValue / $workingHoursValue;
        return round($result, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете средней электрической нагрузки турбоагрегата: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет исходно-нормативного расхода свежего пара
 * E29=IF(E26=0,0,(55.6+3.0686*E26)*1.008)
 * F29=IF(F26=0,0,(55.6+3.0686*F26)*1.008)
 * G29=(E29*E15+F29*F15)/G15
 */
function calculateFreshSteamFlow($date, $shiftId, $blockId, &$values) {
    try {
        if ($blockId == 7 || $blockId == 8) {
            // ТГ7 и ТГ8: E29=IF(E26=0,0,(55.6+3.0686*E26)*1.008)
            
            // Получаем E26 (средняя электрическая нагрузка) из уже рассчитанных значений
            $avgElectricLoad = 0;
            foreach ($values as $value) {
                if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId && $value['param_id'] == 35) {
                    $avgElectricLoad = $value['value'];
                    break;
                }
            }
            
            // Если не нашли в текущих значениях, ищем в базе
            if ($avgElectricLoad == 0) {
                $db = getDbConnection();
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([35, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([35, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $avgElectricLoad = $result ? (float)$result['value'] : 0;
            }
            
            // Применяем формулу: IF(E26=0,0,(55.6+3.0686*E26)*1.008)
            if ($avgElectricLoad == 0) {
                return 0;
            }
            
            $result = (55.6 + 3.0686 * $avgElectricLoad) * 1.008;
            return round($result, 4);
            
        } elseif ($blockId == 9) {
            // ОЧ-130: G29=(E29*E15+F29*F15)/G15
            
            // Получаем E29, E15, F29, F15, G15
            $e29 = 0; // Исходно-нормативный расход свежего пара ТГ7
            $e15 = 0; // Часы работы ТГ7
            $f29 = 0; // Исходно-нормативный расход свежего пара ТГ8
            $f15 = 0; // Часы работы ТГ8
            $g15 = 0; // Часы работы ОЧ-130
            
            // Ищем значения в уже рассчитанных значениях
            foreach ($values as $value) {
                if ($value['shift_id'] == $shiftId) {
                    if ($value['tg_id'] == 7 && $value['param_id'] == 36) { // E29
                        $e29 = $value['value'];
                    } elseif ($value['tg_id'] == 7 && $value['param_id'] == 28) { // E15
                        $e15 = $value['value'];
                    } elseif ($value['tg_id'] == 8 && $value['param_id'] == 36) { // F29
                        $f29 = $value['value'];
                    } elseif ($value['tg_id'] == 8 && $value['param_id'] == 28) { // F15
                        $f15 = $value['value'];
                    } elseif ($value['tg_id'] == 9 && $value['param_id'] == 28) { // G15
                        $g15 = $value['value'];
                    }
                }
            }
            
            // Если не нашли в текущих значениях, ищем в базе
            if ($e29 == 0 || $e15 == 0 || $f29 == 0 || $f15 == 0 || $g15 == 0) {
                $db = getDbConnection();
                
                // Получаем E29
                if ($e29 == 0) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ?
                    ');
                    if ($shiftId !== null) {
                        $stmt = $db->prepare('
                            SELECT value
                            FROM tg_result_values 
                            WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                        ');
                        $stmt->execute([36, 7, $date, $shiftId]);
                    } else {
                        $stmt->execute([36, 7, $date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $e29 = $result ? (float)$result['value'] : 0;
                }
                
                // Получаем E15
                if ($e15 == 0) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ?
                    ');
                    if ($shiftId !== null) {
                        $stmt = $db->prepare('
                            SELECT value
                            FROM tg_result_values 
                            WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                        ');
                        $stmt->execute([28, 7, $date, $shiftId]);
                    } else {
                        $stmt->execute([28, 7, $date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $e15 = $result ? (float)$result['value'] : 0;
                }
                
                // Получаем F29
                if ($f29 == 0) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ?
                    ');
                    if ($shiftId !== null) {
                        $stmt = $db->prepare('
                            SELECT value
                            FROM tg_result_values 
                            WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                        ');
                        $stmt->execute([36, 8, $date, $shiftId]);
                    } else {
                        $stmt->execute([36, 8, $date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $f29 = $result ? (float)$result['value'] : 0;
                }
                
                // Получаем F15
                if ($f15 == 0) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ?
                    ');
                    if ($shiftId !== null) {
                        $stmt = $db->prepare('
                            SELECT value
                            FROM tg_result_values 
                            WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                        ');
                        $stmt->execute([28, 8, $date, $shiftId]);
                    } else {
                        $stmt->execute([28, 8, $date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $f15 = $result ? (float)$result['value'] : 0;
                }
                
                // Получаем G15
                if ($g15 == 0) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ?
                    ');
                    if ($shiftId !== null) {
                        $stmt = $db->prepare('
                            SELECT value
                            FROM tg_result_values 
                            WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                        ');
                        $stmt->execute([28, 9, $date, $shiftId]);
                    } else {
                        $stmt->execute([28, 9, $date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $g15 = $result ? (float)$result['value'] : 0;
                }
            }
            
            // Применяем формулу: G29=(E29*E15+F29*F15)/G15
            if ($g15 == 0) {
                return 0;
            }
            
            $result = ($e29 * $e15 + $f29 * $f15) / $g15;
            return round($result, 4);
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете исходно-нормативного расхода свежего пара: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет исходно-нормативного расхода пара в конденсатор
 * E30=IF(E15=0,0,20+0.6666667*E29)
 * F30=IF(F15=0,0,20+0.6666667*F29)
 * G30=IF(G15=0,0,20+0.6666667*G29)
 */
function calculateCondenserSteamFlow($date, $shiftId, $blockId, &$values) {
    try {
        // Получаем E15/F15/G15 (часы работы) и E29/F29/G29 (исходно-нормативный расход свежего пара)
        $workingHours = 0;
        $freshSteamFlow = 0;
        
        // Ищем значения в уже рассчитанных значениях
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId) {
                if ($value['param_id'] == 28) { // Часы работы
                    $workingHours = $value['value'];
                } elseif ($value['param_id'] == 36) { // Исходно-нормативный расход свежего пара
                    $freshSteamFlow = $value['value'];
                }
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($workingHours == 0 || $freshSteamFlow == 0) {
            $db = getDbConnection();
            
            // Получаем часы работы
            if ($workingHours == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([28, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([28, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $workingHours = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем исходно-нормативный расход свежего пара
            if ($freshSteamFlow == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([36, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([36, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $freshSteamFlow = $result ? (float)$result['value'] : 0;
            }
        }
        
        // Применяем формулу: IF(E15=0,0,20+0.6666667*E29)
        if ($workingHours == 0) {
            return 0;
        }
        
        $result = 20 + 0.6666667 * $freshSteamFlow;
        return round($result, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете исходно-нормативного расхода пара в конденсатор: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет исходно-нормативного значения удельного расхода тепла брутто на турбоагрегат
 * E31=IF(E26>0,1000*(19.54+2.112*E26)*1.008/E26,0)
 * F31=IF(F26>0,1000*(19.54+2.112*F26)*1.008/F26,0)
 * ОЧ-130: не рассчитывается
 */
function calculateSpecificHeatConsumption($date, $shiftId, $blockId, &$values) {
    try {
        // ОЧ-130 не рассчитывается
        if ($blockId == 9) {
            return 0;
        }
        
        // Получаем E26/F26 (средняя электрическая нагрузка)
        $avgElectricLoad = 0;
        
        // Ищем значение в уже рассчитанных значениях
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId && $value['param_id'] == 35) {
                $avgElectricLoad = $value['value'];
                break;
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($avgElectricLoad == 0) {
            $db = getDbConnection();
            $stmt = $db->prepare('
                SELECT value
                FROM tg_result_values 
                WHERE param_id = ? AND tg_id = ? AND date = ?
            ');
            if ($shiftId !== null) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                ');
                $stmt->execute([35, $blockId, $date, $shiftId]);
            } else {
                $stmt->execute([35, $blockId, $date]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $avgElectricLoad = $result ? (float)$result['value'] : 0;
        }
        
        // Применяем формулу: IF(E26>0,1000*(19.54+2.112*E26)*1.008/E26,0)
        if ($avgElectricLoad <= 0) {
            return 0;
        }
        
        $result = 1000 * (19.54 + 2.112 * $avgElectricLoad) * 1.008 / $avgElectricLoad;
        return round($result, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете исходно-нормативного значения удельного расхода тепла брутто: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет температуры охлаждающей воды
 * E33=IF(E20=10,0,IF(E20>10,IF(E26=0,0,IF(E26<=160,((0.0689+0.009463*E20*E20-0.098587*E20)-(E26-140)*((0.0689+0.009463*E20*E20-0.098587*E20)-(0.2132+0.009408*E20*E20-0.116607*E20))/20),IF(E26<=180,((0.2132+0.009408*E20*E20-0.116607*E20)-(E26-160)*((0.2132+0.009408*E20*E20-0.116607*E20)-(0.4263+0.009841*E20*E20-0.150246*E20))/20),IF(E26<=200,((0.4263+0.009841*E20*E20-0.150246*E20)-(E26-180)*((0.4263+0.009841*E20*E20-0.150246*E20)-(0.4528+0.009336*E20*E20-0.147758*E20))/20),0))))))*E31/100
 * F33=IF(F20=10,0,IF(F20>10,IF(F26=0,0,IF(F26<=160,((0.0689+0.009463*F20*F20-0.098587*F20)-(F26-140)*((0.0689+0.009463*F20*F20-0.098587*F20)-(0.2132+0.009408*F20*F20-0.116607*F20))/20),IF(F26<=180,((0.2132+0.009408*F20*F20-0.116607*F20)-(F26-160)*((0.2132+0.009408*F20*F20-0.116607*F20)-(0.4263+0.009841*F20*F20-0.150246*F20))/20),IF(F26<=200,((0.4263+0.009841*F20*F20-0.150246*F20)-(F26-180)*((0.4263+0.009841*F20*F20-0.150246*F20)-(0.4528+0.009336*F20*F20-0.147758*F20))/20),0))))))*F31/100
 * ОЧ-130: не рассчитывается
 */
function calculateCoolingWaterTemperatureEffect($date, $shiftId, $blockId, &$values) {
    try {
        // ОЧ-130 не рассчитывается
        if ($blockId == 9) {
            return 0;
        }
        
        // Получаем E20/F20 (средняя температура охлаждающей воды на входе в конденсатор)
        $coolingWaterTemp = 0;
        // Получаем E26/F26 (средняя электрическая нагрузка)
        $avgElectricLoad = 0;
        // Получаем E31/F31 (исходно-нормативное значение удельного расхода тепла брутто)
        $specificHeatConsumption = 0;
        
        // Ищем значения в уже рассчитанных значениях
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId) {
                if ($value['param_id'] == 32) { // Средняя температура охлаждающей воды на входе в конденсатор
                    $coolingWaterTemp = $value['value'];
                } elseif ($value['param_id'] == 35) { // Средняя электрическая нагрузка
                    $avgElectricLoad = $value['value'];
                } elseif ($value['param_id'] == 38) { // Исходно-нормативное значение удельного расхода тепла брутто
                    $specificHeatConsumption = $value['value'];
                }
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($coolingWaterTemp == 0 || $avgElectricLoad == 0 || $specificHeatConsumption == 0) {
            $db = getDbConnection();
            
            // Получаем температуру охлаждающей воды
            if ($coolingWaterTemp == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([32, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([32, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $coolingWaterTemp = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем электрическую нагрузку
            if ($avgElectricLoad == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([35, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([35, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $avgElectricLoad = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем удельный расход тепла брутто
            if ($specificHeatConsumption == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([38, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([38, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $specificHeatConsumption = $result ? (float)$result['value'] : 0;
            }
        }
        
        // Применяем сложную формулу
        // IF(E20=10,0,IF(E20>10,IF(E26=0,0,IF(E26<=160,...))))
        if ($coolingWaterTemp == 10) {
            return 0;
        }
        
        if ($coolingWaterTemp <= 10) {
            return 0;
        }
        
        if ($avgElectricLoad == 0) {
            return 0;
        }
        
        // Вычисляем коэффициенты для разных диапазонов нагрузки
        $coeff1 = 0.0689 + 0.009463 * $coolingWaterTemp * $coolingWaterTemp - 0.098587 * $coolingWaterTemp;
        $coeff2 = 0.2132 + 0.009408 * $coolingWaterTemp * $coolingWaterTemp - 0.116607 * $coolingWaterTemp;
        $coeff3 = 0.4263 + 0.009841 * $coolingWaterTemp * $coolingWaterTemp - 0.150246 * $coolingWaterTemp;
        $coeff4 = 0.4528 + 0.009336 * $coolingWaterTemp * $coolingWaterTemp - 0.147758 * $coolingWaterTemp;
        
        $result = 0;
        
        if ($avgElectricLoad <= 160) {
            // ((0.0689+0.009463*E20*E20-0.098587*E20)-(E26-140)*((0.0689+0.009463*E20*E20-0.098587*E20)-(0.2132+0.009408*E20*E20-0.116607*E20))/20)
            $result = $coeff1 - ($avgElectricLoad - 140) * ($coeff1 - $coeff2) / 20;
        } elseif ($avgElectricLoad <= 180) {
            // ((0.2132+0.009408*E20*E20-0.116607*E20)-(E26-160)*((0.2132+0.009408*E20*E20-0.116607*E20)-(0.4263+0.009841*E20*E20-0.150246*E20))/20)
            $result = $coeff2 - ($avgElectricLoad - 160) * ($coeff2 - $coeff3) / 20;
        } elseif ($avgElectricLoad <= 200) {
            // ((0.4263+0.009841*E20*E20-0.150246*E20)-(E26-180)*((0.4263+0.009841*E20*E20-0.150246*E20)-(0.4528+0.009336*E20*E20-0.147758*E20))/20)
            $result = $coeff3 - ($avgElectricLoad - 180) * ($coeff3 - $coeff4) / 20;
        } else {
            $result = 0;
        }
        
        // Умножаем на E31/100
        $finalResult = $result * $specificHeatConsumption / 100;
        return round($finalResult, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете температуры охлаждающей воды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет изменений расхода охлаждающей воды
 * E34=IF(E19>17500,0,(E31*0.11*0.01*(17500-E19)/1000))
 * F34=IF(F19>17500,0,(F31*0.11*0.01*(17500-F19)/1000))
 * ОЧ-130: не рассчитывается
 */
function calculateCoolingWaterFlowChange($date, $shiftId, $blockId, &$values) {
    try {
        // ОЧ-130 не рассчитывается
        if ($blockId == 9) {
            return 0;
        }
        
        // Получаем E19/F19 (средний расход охлаждающей воды)
        $coolingWaterFlow = 0;
        // Получаем E31/F31 (исходно-нормативное значение удельного расхода тепла брутто)
        $specificHeatConsumption = 0;
        
        // Ищем значения в уже рассчитанных значениях
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId) {
                if ($value['param_id'] == 31) { // Средний расход охлаждающей воды
                    $coolingWaterFlow = $value['value'];
                } elseif ($value['param_id'] == 38) { // Исходно-нормативное значение удельного расхода тепла брутто
                    $specificHeatConsumption = $value['value'];
                }
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($coolingWaterFlow == 0 || $specificHeatConsumption == 0) {
            $db = getDbConnection();
            
            // Получаем расход охлаждающей воды
            if ($coolingWaterFlow == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([31, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([31, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $coolingWaterFlow = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем удельный расход тепла брутто
            if ($specificHeatConsumption == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([38, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([38, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $specificHeatConsumption = $result ? (float)$result['value'] : 0;
            }
        }
        
        // Применяем формулу: IF(E19>17500,0,(E31*0.11*0.01*(17500-E19)/1000))
        if ($coolingWaterFlow > 17500) {
            return 0;
        }
        
        $result = $specificHeatConsumption * 0.11 * 0.01 * (17500 - $coolingWaterFlow) / 1000;
        return round($result, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете изменений расхода охлаждающей воды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E35/F35
 * E35=E31*0.0085*E22/100000
 * F35=F31*0.0085*F22/100000
 * G35: не рассчитывается
 */
function calculateParameter35($date, $shiftId, $blockId, &$values) {
    try {
        // ОЧ-130 не рассчитывается
        if ($blockId == 9) {
            return 0;
        }
        
        // Получаем E31/F31 (исходно-нормативное значение удельного расхода тепла брутто)
        $specificHeatConsumption = 0;
        // Получаем E22/F22 (продолжительность работы турбоагрегата от даты разработки нормативной характеристики)
        $operationDuration = 0;
        
        // Ищем значения в уже рассчитанных значениях
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId) {
                if ($value['param_id'] == 38) { // Исходно-нормативное значение удельного расхода тепла брутто
                    $specificHeatConsumption = $value['value'];
                } elseif ($value['param_id'] == 34) { // Продолжительность работы турбоагрегата от даты разработки нормативной характеристики
                    $operationDuration = $value['value'];
                }
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($specificHeatConsumption == 0 || $operationDuration == 0) {
            $db = getDbConnection();
            
            // Получаем удельный расход тепла брутто
            if ($specificHeatConsumption == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([38, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([38, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $specificHeatConsumption = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем продолжительность работы
            if ($operationDuration == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([34, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([34, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $operationDuration = $result ? (float)$result['value'] : 0;
            }
        }
        
        // Применяем формулу: E31*0.0085*E22/100000
        $result = $specificHeatConsumption * 0.0085 * $operationDuration / 100000;
        return round($result, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E35/F35: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E36
 * E36=IF(E15=0,0,(245-'Исх. данные оч.130'!C18))
 * F36 и G36: не рассчитываются
 */
function calculateParameter36($date, $shiftId, $blockId, &$values) {
    try {
        // Только для ТГ7
        if ($blockId != 7) {
            return 0;
        }
        
        // Получаем E15 (часы работы)
        $workingHours = 0;
        
        // Ищем значение в уже рассчитанных значениях
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId && $value['param_id'] == 28) {
                $workingHours = $value['value'];
                break;
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($workingHours == 0) {
            $db = getDbConnection();
            $stmt = $db->prepare('
                SELECT value
                FROM tg_result_values 
                WHERE param_id = ? AND tg_id = ? AND date = ?
            ');
            if ($shiftId !== null) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                ');
                $stmt->execute([28, $blockId, $date, $shiftId]);
            } else {
                $stmt->execute([28, $blockId, $date]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $workingHours = $result ? (float)$result['value'] : 0;
        }
        
        // Применяем формулу: IF(E15=0,0,(245-'Исх. данные оч.130'!C18))
        if ($workingHours == 0) {
            return 0;
        }
        
        // Получаем значение из 'Исх. данные оч.130'!C18
        // Это значение из parameter_values с parameter_id = 50 (предполагаем)
        $c18Value = getC18Value($date);
        
        $result = 245 - $c18Value;
        return round($result, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E36: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Получение значения C18 из 'Исх. данные оч.130'
 * Это значение из parameter_values с parameter_id = 50
 */
function getC18Value($date) {
    try {
        $db = getDbConnection();
        $stmt = $db->prepare('
            SELECT value
            FROM parameter_values 
            WHERE parameter_id = ? AND date = ?
        ');
        $stmt->execute([50, $date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (float)$result['value'] : 0;
    } catch (Exception $e) {
        error_log('Ошибка при получении значения C18: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет константы E37/F37
 * E37=46.9, F37=46.9
 * G37: не рассчитывается
 */
function calculateConstant37($blockId) {
    try {
        // Только для ТГ7 и ТГ8
        if ($blockId == 7 || $blockId == 8) {
            return 46.9;
        }
        
        // ОЧ-130 не рассчитывается
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете константы E37/F37: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E39/F39/G39
 * E39=E31+E33+E34+E35+E36+E37
 * F39=F31+F33+F34+F35+F36+F37
 * G39=(E39*E11+F11*F39)/G11
 */
function calculateParameter39($date, $shiftId, $blockId, &$values) {
    try {
        if ($blockId == 7 || $blockId == 8) {
            // ТГ7 и ТГ8: E39=E31+E33+E34+E35+E36+E37
            
            // Получаем значения из уже рассчитанных значений или рассчитываем заново
            $e31 = getParameterValue($date, $shiftId, $blockId, 38, $values); // Исходно-нормативное значение удельного расхода тепла брутто
            $e33 = getParameterValue($date, $shiftId, $blockId, 39, $values); // Температуры охлаждающей воды
            $e34 = getParameterValue($date, $shiftId, $blockId, 40, $values); // Изменения расхода охлаждающей воды
            $e35 = getParameterValue($date, $shiftId, $blockId, 41, $values); // Параметр E35
            $e36 = getParameterValue($date, $shiftId, $blockId, 42, $values); // Параметр E36
            $e37 = getParameterValue($date, $shiftId, $blockId, 43, $values); // Константа E37
            
            // Применяем формулу: E31+E33+E34+E35+E36+E37
            $result = $e31 + $e33 + $e34 + $e35 + $e36 + $e37;
            return round($result, 4);
            
        } elseif ($blockId == 9) {
            // ОЧ-130: G39=(E39*E11+F11*F39)/G11
            
            // Получаем значения из уже рассчитанных значений или рассчитываем заново
            $e39 = getParameterValue($date, $shiftId, 7, 44, $values); // Параметр E39 для ТГ7
            $e11 = getParameterValue($date, $shiftId, 7, 26, $values); // Выработка ТГ7
            $f39 = getParameterValue($date, $shiftId, 8, 44, $values); // Параметр F39 для ТГ8
            $f11 = getParameterValue($date, $shiftId, 8, 26, $values); // Выработка ТГ8
            $g11 = getParameterValue($date, $shiftId, 9, 26, $values); // Выработка ОЧ-130
            
            // Применяем формулу: G39=(E39*E11+F11*F39)/G11
            if ($g11 == 0) {
                return 0;
            }
            
            $result = ($e39 * $e11 + $f11 * $f39) / $g11;
            return round($result, 4);
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E39/F39/G39: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E41/F41/G41
 * E41=1.115+0.0023*E26
 * F41=1.115+0.0023*F26
 * G41=E41+F41
 */
function calculateParameter41($date, $shiftId, $blockId, &$values) {
    try {
        if ($blockId == 7 || $blockId == 8) {
            // ТГ7 и ТГ8: E41=1.115+0.0023*E26
            
            // Получаем E26/F26 (средняя электрическая нагрузка)
            $avgElectricLoad = 0;
            
            // Ищем значение в уже рассчитанных значениях
            foreach ($values as $value) {
                if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId && $value['param_id'] == 35) {
                    $avgElectricLoad = $value['value'];
                    break;
                }
            }
            
            // Если не нашли в текущих значениях, ищем в базе
            if ($avgElectricLoad == 0) {
                $db = getDbConnection();
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([35, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([35, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $avgElectricLoad = $result ? (float)$result['value'] : 0;
            }
            
            // Применяем формулу: 1.115+0.0023*E26
            $result = 1.115 + 0.0023 * $avgElectricLoad;
            return round($result, 4);
            
        } elseif ($blockId == 9) {
            // ОЧ-130: G41=E41+F41
            
            // Получаем E41 и F41
            $e41 = 0; // Параметр E41 для ТГ7
            $f41 = 0; // Параметр F41 для ТГ8
            
            // Ищем значения в уже рассчитанных значениях
            foreach ($values as $value) {
                if ($value['shift_id'] == $shiftId) {
                    if ($value['tg_id'] == 7 && $value['param_id'] == 45) { // E41
                        $e41 = $value['value'];
                    } elseif ($value['tg_id'] == 8 && $value['param_id'] == 45) { // F41
                        $f41 = $value['value'];
                    }
                }
            }
            
            // Если не нашли в текущих значениях, ищем в базе
            if ($e41 == 0 || $f41 == 0) {
                $db = getDbConnection();
                
                // Получаем E41
                if ($e41 == 0) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ?
                    ');
                    if ($shiftId !== null) {
                        $stmt = $db->prepare('
                            SELECT value
                            FROM tg_result_values 
                            WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                        ');
                        $stmt->execute([45, 7, $date, $shiftId]);
                    } else {
                        $stmt->execute([45, 7, $date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $e41 = $result ? (float)$result['value'] : 0;
                }
                
                // Получаем F41
                if ($f41 == 0) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ?
                    ');
                    if ($shiftId !== null) {
                        $stmt = $db->prepare('
                            SELECT value
                            FROM tg_result_values 
                            WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                        ');
                        $stmt->execute([45, 8, $date, $shiftId]);
                    } else {
                        $stmt->execute([45, 8, $date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $f41 = $result ? (float)$result['value'] : 0;
                }
            }
            
            // Применяем формулу: G41=E41+F41
            $result = $e41 + $f41;
            return round($result, 4);
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E41/F41/G41: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E42/F42
 * E42=IF(E26=0,0,100*E41/E26)
 * F42=IF(F26=0,0,100*F41/F26)
 * G42: не рассчитывается
 */
function calculateParameter42($date, $shiftId, $blockId, &$values) {
    try {
        // ОЧ-130 не рассчитывается
        if ($blockId == 9) {
            return 0;
        }
        
        // Получаем E41/F41 (параметр E41/F41)
        $parameter41 = 0;
        // Получаем E26/F26 (средняя электрическая нагрузка)
        $avgElectricLoad = 0;
        
        // Ищем значения в уже рассчитанных значениях
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId) {
                if ($value['param_id'] == 45) { // E41/F41
                    $parameter41 = $value['value'];
                } elseif ($value['param_id'] == 35) { // E26/F26
                    $avgElectricLoad = $value['value'];
                }
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($parameter41 == 0 || $avgElectricLoad == 0) {
            $db = getDbConnection();
            
            // Получаем E41/F41
            if ($parameter41 == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([45, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([45, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $parameter41 = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем E26/F26
            if ($avgElectricLoad == 0) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([35, $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([35, $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $avgElectricLoad = $result ? (float)$result['value'] : 0;
            }
        }
        
        // Применяем формулу: IF(E26=0,0,100*E41/E26)
        if ($avgElectricLoad == 0) {
            return 0;
        }
        
        $result = 100 * $parameter41 / $avgElectricLoad;
        return round($result, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E42/F42: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет константы E44/F44
 * E44=0.45, F44=0.45
 * G44: не рассчитывается
 */
function calculateConstant44($blockId) {
    try {
        // Только для ТГ7 и ТГ8
        if ($blockId == 7 || $blockId == 8) {
            return 0.45;
        }
        
        // ОЧ-130 не рассчитывается
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете константы E44/F44: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E45/F45
 * E45=IF(E26>0,(E44*100)/E26,0)
 * F45=IF(F26>0,(F44*100)/F26,0)
 * G45: не рассчитывается
 */
function calculateParameter45($date, $shiftId, $blockId, &$values) {
    try {
        // ОЧ-130 не рассчитывается
        if ($blockId == 9) {
            return 0;
        }
        
        // Получаем E44/F44 (константа E44/F44)
        $constant44 = 0.45;
        // Получаем E26/F26 (средняя электрическая нагрузка)
        $avgElectricLoad = 0;
        
        // Ищем значение E26/F26 в уже рассчитанных значениях
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId && $value['param_id'] == 35) {
                $avgElectricLoad = $value['value'];
                break;
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($avgElectricLoad == 0) {
            $db = getDbConnection();
            $stmt = $db->prepare('
                SELECT value
                FROM tg_result_values 
                WHERE param_id = ? AND tg_id = ? AND date = ?
            ');
            if ($shiftId !== null) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                ');
                $stmt->execute([35, $blockId, $date, $shiftId]);
            } else {
                $stmt->execute([35, $blockId, $date]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $avgElectricLoad = $result ? (float)$result['value'] : 0;
        }
        
        // Применяем формулу: IF(E26>0,(E44*100)/E26,0)
        if ($avgElectricLoad <= 0) {
            return 0;
        }
        
        $result = ($constant44 * 100) / $avgElectricLoad;
        return round($result, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E45/F45: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E48/F48/G48
 * E48=(E41+E44+E46/1000)*1.043
 * F48=(F41+F44+F46/1000)*1.043
 * G48=SUM(E48:F48)
 */
function calculateParameter48($date, $shiftId, $blockId, &$values) {
    try {
        if ($blockId == 7 || $blockId == 8) {
            // ТГ7 и ТГ8: E48=(E41+E44+E46/1000)*1.043
            
            // Получаем значения из уже рассчитанных значений или рассчитываем заново
            $parameter41 = getParameterValue($date, $shiftId, $blockId, 45, $values); // E41/F41
            $constant44 = 0.45; // E44/F44 (константа)
            $parameter46 = 0; // E46/F46 (пока не определен)
            
            // Применяем формулу: (E41+E44+E46/1000)*1.043
            $result = ($parameter41 + $constant44 + $parameter46 / 1000) * 1.043;
            return round($result, 4);
            
        } elseif ($blockId == 9) {
            // ОЧ-130: G48=SUM(E48:F48)
            
            // Получаем значения из уже рассчитанных значений или рассчитываем заново
            $e48 = getParameterValue($date, $shiftId, 7, 49, $values); // Параметр E48 для ТГ7
            $f48 = getParameterValue($date, $shiftId, 8, 49, $values); // Параметр F48 для ТГ8
            
            // Применяем формулу: G48=SUM(E48:F48)
            $result = $e48 + $f48;
            return round($result, 4);
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E48/F48/G48: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E49/F49/G49
 * E49=IF(E15=0,0,100*((E48*E15))/E11)
 * F49=IF(F15=0,0,100*((F48*F15))/F11)
 * G49=100*((E48*E15+F15*F48))/G11
 */
function calculateParameter49($date, $shiftId, $blockId, &$values) {
    try {
        if ($blockId == 7 || $blockId == 8) {
            // ТГ7 и ТГ8: E49=IF(E15=0,0,100*((E48*E15))/E11)
            
            // Получаем значения из уже рассчитанных значений или рассчитываем заново
            $parameter48 = getParameterValue($date, $shiftId, $blockId, 49, $values); // E48/F48
            $workingHours = getParameterValue($date, $shiftId, $blockId, 28, $values); // E15/F15
            $generation = getParameterValue($date, $shiftId, $blockId, 26, $values); // E11/F11
            
            // Применяем формулу: IF(E15=0,0,100*((E48*E15))/E11)
            if ($workingHours == 0) {
                return 0;
            }
            
            if ($generation == 0) {
                return 0;
            }
            
            $result = 100 * (($parameter48 * $workingHours)) / $generation;
            return round($result, 4);
            
        } elseif ($blockId == 9) {
            // ОЧ-130: G49=100*((E48*E15+F15*F48))/G11
            
            // Получаем значения из уже рассчитанных значений или рассчитываем заново
            $e48 = getParameterValue($date, $shiftId, 7, 49, $values); // E48
            $e15 = getParameterValue($date, $shiftId, 7, 28, $values); // E15
            $f48 = getParameterValue($date, $shiftId, 8, 49, $values); // F48
            $f15 = getParameterValue($date, $shiftId, 8, 28, $values); // F15
            $g11 = getParameterValue($date, $shiftId, 9, 26, $values); // G11
            
            // Применяем формулу: G49=100*((E48*E15+F15*F48))/G11
            if ($g11 == 0) {
                return 0;
            }
            
            $result = 100 * (($e48 * $e15 + $f15 * $f48)) / $g11;
            return round($result, 4);
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E49/F49/G49: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Получение количества пусков для конкретного оборудования
 */
function getStartCountForBlock($date, $shiftId, $equipmentId) {
    try {
        $db = getDbConnection();
        
        // Определяем временные границы для выбранного периода
        if ($shiftId !== null) {
            // Для смены
            $shiftStartTimes = [1 => '00:00:00', 2 => '08:00:00', 3 => '16:00:00'];
            $shiftEndTimes = [1 => '08:00:00', 2 => '16:00:00', 3 => '24:00:00'];
            $periodStart = strtotime($date . ' ' . $shiftStartTimes[$shiftId]);
            $periodEnd = strtotime($date . ' ' . $shiftEndTimes[$shiftId]);
        } else {
            // Для суточного расчета
            $periodStart = strtotime($date . ' 00:00:00');
            $periodEnd = strtotime($date . ' 23:59:59');
        }
        
        // Считаем количество событий "pusk" в выбранном периоде
        $stmt = $db->prepare('
            SELECT COUNT(*) as count
            FROM equipment_events 
            WHERE equipment_id = ? AND event_type = "pusk" 
            AND event_time >= ? AND event_time <= ?
        ');
        $stmt->execute([$equipmentId, date('Y-m-d H:i:s', $periodStart), date('Y-m-d H:i:s', $periodEnd)]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)$result['count'];
        
    } catch (Exception $e) {
        error_log('Ошибка при получении количества пусков для оборудования: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E50/F50/G50
 * E50=E39*E26/1000
 * F50=F39*F26/1000
 * G50=G39*G26/1000
 */
function calculateParameter50($date, $shiftId, $blockId, &$values) {
    try {
        // Получаем значения из уже рассчитанных значений или рассчитываем заново
        $parameter39 = getParameterValue($date, $shiftId, $blockId, 44, $values); // E39/F39/G39
        $avgElectricLoad = getParameterValue($date, $shiftId, $blockId, 35, $values); // E26/F26/G26
        
        // Применяем формулу: E39*E26/1000
        $result = $parameter39 * $avgElectricLoad / 1000;
        return round($result, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E50/F50/G50: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E51/F51/G51
 * E51=IF(E15=0,0,100000*(1.24*E15)/(E39*E11))
 * F51=IF(F15=0,0,100000*(1.24*F15)/(F39*F11))
 * G51=100000*(1.24*G15)/(G39*G11)
 */
function calculateParameter51($date, $shiftId, $blockId, &$values) {
    try {
        if ($blockId == 7 || $blockId == 8) {
            // ТГ7 и ТГ8: E51=IF(E15=0,0,100000*(1.24*E15)/(E39*E11))
            
            // Получаем E15/F15, E39/F39, E11/F11
            $workingHours = 0;
            $parameter39 = 0;
            $generation = 0;
            
            // Ищем значения в уже рассчитанных значениях
            foreach ($values as $value) {
                if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId) {
                    if ($value['param_id'] == 28) { // E15/F15
                        $workingHours = $value['value'];
                    } elseif ($value['param_id'] == 44) { // E39/F39
                        $parameter39 = $value['value'];
                    } elseif ($value['param_id'] == 26) { // E11/F11
                        $generation = $value['value'];
                    }
                }
            }
            
            // Если не нашли в текущих значениях, ищем в базе
            if ($workingHours == 0 || $parameter39 == 0 || $generation == 0) {
                $db = getDbConnection();
                
                // Получаем все необходимые значения
                $params = [
                    ['param_id' => 28, 'var' => 'workingHours'],
                    ['param_id' => 44, 'var' => 'parameter39'],
                    ['param_id' => 26, 'var' => 'generation']
                ];
                
                foreach ($params as $param) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ?
                    ');
                    if ($shiftId !== null) {
                        $stmt = $db->prepare('
                            SELECT value
                            FROM tg_result_values 
                            WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                        ');
                        $stmt->execute([$param['param_id'], $blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$param['param_id'], $blockId, $date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    ${$param['var']} = $result ? (float)$result['value'] : 0;
                }
            }
            
            // Применяем формулу: IF(E15=0,0,100000*(1.24*E15)/(E39*E11))
            if ($workingHours == 0) {
                return 0;
            }
            
            if ($parameter39 == 0 || $generation == 0) {
                return 0;
            }
            
            $result = 100000 * (1.24 * $workingHours) / ($parameter39 * $generation);
            return round($result, 4);
            
        } elseif ($blockId == 9) {
            // ОЧ-130: G51=100000*(1.24*G15)/(G39*G11)
            
            // Получаем G15, G39, G11
            $g15 = 0; $g39 = 0; $g11 = 0;
            
            // Ищем значения в уже рассчитанных значениях
            foreach ($values as $value) {
                if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId) {
                    if ($value['param_id'] == 28) { // G15
                        $g15 = $value['value'];
                    } elseif ($value['param_id'] == 44) { // G39
                        $g39 = $value['value'];
                    } elseif ($value['param_id'] == 26) { // G11
                        $g11 = $value['value'];
                    }
                }
            }
            
            // Если не нашли в текущих значениях, ищем в базе
            if ($g15 == 0 || $g39 == 0 || $g11 == 0) {
                $db = getDbConnection();
                
                // Получаем все необходимые значения
                $params = [
                    ['param_id' => 28, 'var' => 'g15'],
                    ['param_id' => 44, 'var' => 'g39'],
                    ['param_id' => 26, 'var' => 'g11']
                ];
                
                foreach ($params as $param) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ?
                    ');
                    if ($shiftId !== null) {
                        $stmt = $db->prepare('
                            SELECT value
                            FROM tg_result_values 
                            WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                        ');
                        $stmt->execute([$param['param_id'], $blockId, $date, $shiftId]);
                    } else {
                        $stmt->execute([$param['param_id'], $blockId, $date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    ${$param['var']} = $result ? (float)$result['value'] : 0;
                }
            }
            
            // Применяем формулу: G51=100000*(1.24*G15)/(G39*G11)
            if ($g39 == 0 || $g11 == 0) {
                return 0;
            }
            
            $result = 100000 * (1.24 * $g15) / ($g39 * $g11);
            return round($result, 4);
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E51/F51/G51: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет параметра E52/F52/G52
 * E52=E39*(100+E51)/(100-E49)
 * F52=F39*(100+F51)/(100-F49)
 * G52=G39*(100+G51)/(100-G49)
 */
function calculateParameter52($date, $shiftId, $blockId, &$values) {
    try {
        // Получаем E39/F39/G39, E51/F51/G51, E49/F49/G49
        $parameter39 = 0; // E39/F39/G39
        $parameter51 = 0; // E51/F51/G51
        $parameter49 = 0; // E49/F49/G49
        
        // Ищем значения в уже рассчитанных значениях
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId) {
                if ($value['param_id'] == 44) { // E39/F39/G39
                    $parameter39 = $value['value'];
                } elseif ($value['param_id'] == 52) { // E51/F51/G51
                    $parameter51 = $value['value'];
                } elseif ($value['param_id'] == 50) { // E49/F49/G49
                    $parameter49 = $value['value'];
                }
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($parameter39 == 0 || $parameter51 == 0 || $parameter49 == 0) {
            $db = getDbConnection();
            
            // Получаем все необходимые значения
            $params = [
                ['param_id' => 44, 'var' => 'parameter39'],
                ['param_id' => 52, 'var' => 'parameter51'],
                ['param_id' => 50, 'var' => 'parameter49']
            ];
            
            foreach ($params as $param) {
                $stmt = $db->prepare('
                    SELECT value
                    FROM tg_result_values 
                    WHERE param_id = ? AND tg_id = ? AND date = ?
                ');
                if ($shiftId !== null) {
                    $stmt = $db->prepare('
                        SELECT value
                        FROM tg_result_values 
                        WHERE param_id = ? AND tg_id = ? AND date = ? AND shift_id = ?
                    ');
                    $stmt->execute([$param['param_id'], $blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([$param['param_id'], $blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                ${$param['var']} = $result ? (float)$result['value'] : 0;
            }
        }
        
        // Применяем формулу: E39*(100+E51)/(100-E49)
        if ($parameter39 == 0) {
            return 0;
        }
        
        if ($parameter49 >= 100) {
            return 0; // Избегаем деления на ноль
        }
        
        $result = $parameter39 * (100 + $parameter51) / (100 - $parameter49);
        return round($result, 4);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете параметра E52/F52/G52: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Получение значения параметра из уже рассчитанных значений или расчет заново
 * Приоритет: свежерассчитанные значения > расчет заново > 0
 */
function getParameterValue($date, $shiftId, $blockId, $paramId, &$values, $calculationFunction = null) {
    // Сначала ищем в уже рассчитанных значениях
    foreach ($values as $value) {
        if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId && $value['param_id'] == $paramId) {
            error_log("getParameterValue: найдено в текущих значениях - param_id=$paramId, blockId=$blockId, shiftId=$shiftId, value={$value['value']}");
            return $value['value'];
        }
    }
    
    error_log("getParameterValue: НЕ найдено в текущих значениях - param_id=$paramId, blockId=$blockId, shiftId=$shiftId");
    
    // Если не нашли и есть функция расчета, рассчитываем заново
    if ($calculationFunction && is_callable($calculationFunction)) {
        error_log("getParameterValue: вызываем функцию расчета для param_id=$paramId");
        return $calculationFunction($date, $shiftId, $blockId);
    }
    
    // Если нет функции расчета, возвращаем 0
    error_log("getParameterValue: возвращаем 0 для param_id=$paramId");
    return 0;
}

/**
 * Получение расхода на хозяйственные нужды (Эхн)
 */
function getHouseholdNeedsConsumption($date, $shiftId, $blockId) {
    try {
        $db = getDbConnection();
        
        // Хозяйственные нужды общие для всех блоков
        // Получаем показания счетчиков хозяйственных нужд
        $stmt = $db->prepare('
            SELECT mr.shift1, mr.shift2, mr.shift3, mr.total
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE m.meter_type_id = 4 AND mr.date = ?
        ');
        $stmt->execute([$date]);
        $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($readings)) {
            return 0;
        }
        
        // Суммируем расход по всем счетчикам хозяйственных нужд
        $totalConsumption = 0;
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
        
        // Для ОЧ-130 хозяйственные нужды не учитываются (это сумма ТГ7+ТГ8)
        if ($blockId == 9) {
            return 0;
        }
        
        return $totalConsumption;
        
    } catch (Exception $e) {
        error_log('Ошибка при получении расхода на хозяйственные нужды: ' . $e->getMessage());
        return 0;
    }
} 