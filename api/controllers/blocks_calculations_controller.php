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
        $rawInput = file_get_contents('php://input');
        error_log("Raw input received: " . $rawInput);
        $data = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            sendError('Ошибка декодирования JSON: ' . json_last_error_msg(), 400);
        }
        
        if ($data === null) {
            error_log("Decoded data is null");
            sendError('Получены некорректные данные', 400);
        }
        
        error_log("Decoded data: " . json_encode($data));
        
        if (!isset($data['periodType']) || !isset($data['dates'])) {
            error_log("Missing required fields. periodType: " . (isset($data['periodType']) ? $data['periodType'] : 'not set') . ", dates: " . (isset($data['dates']) ? json_encode($data['dates']) : 'not set'));
            sendError('Необходимо указать тип периода и даты', 400);
        }

        $periodType = $data['periodType'];
        $calculatedParams = 0;
        
        if ($periodType === 'shift') {
            if (!isset($data['dates']['selectedDate'])) {
                error_log("Missing selectedDate in dates for shift period type");
                sendError('Необходимо указать selectedDate в dates для типа периода "shift"', 400);
            }
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
            if (!isset($data['dates']['selectedDate'])) {
                error_log("Missing selectedDate in dates for day period type");
                sendError('Необходимо указать selectedDate в dates для типа периода "day"', 400);
            }
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
            if (!isset($data['dates']['startDate']) || !isset($data['dates']['endDate'])) {
                error_log("Missing startDate or endDate in dates for period type");
                sendError('Необходимо указать startDate и endDate в dates для типа периода "period"', 400);
            }
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
 * Ограничивает значение до допустимого диапазона для decimal(15,6)
 * 
 * @param float $value Значение для ограничения
 * @param string $context Контекст (для логирования)
 * @return float Ограниченное значение
 */
function limitDecimalValue($value, $context = '') {
    $maxValue = 999999999.999999;
    $minValue = -999999999.999999;
    
    if (!is_finite($value)) {
        error_log("WARNING: Non-finite value in $context: $value, returning 0");
        return 0;
    }
    
    if ($value > $maxValue) {
        error_log("WARNING: Value exceeds max in $context: $value > $maxValue, limiting to $maxValue");
        return $maxValue;
    } elseif ($value < $minValue) {
        error_log("WARNING: Value below min in $context: $value < $minValue, limiting to $minValue");
        return $minValue;
    }
    
    return round($value, 6);
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
                
                // === РАСЧЕТЫ КОТЛОВ (category 3b) ===
                
                // 29. Фактическое качество сожженного топлива (газ) (param_id = 269)
                $gasFuelQuality = calculateGasFuelQuality($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 10); // row_num 10
                $values[] = [
                    'param_id' => 269, // Фактическое качество сожженного топлива (газ)
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $gasFuelQuality,
                    'cell' => $cell
                ];
                
                // 30. Фактическое качество сожженного топлива (мазут) (param_id = 270)
                $oilFuelQuality = calculateOilFuelQuality($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 11); // row_num 11
                $values[] = [
                    'param_id' => 270, // Фактическое качество сожженного топлива (мазут)
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $oilFuelQuality,
                    'cell' => $cell
                ];
                
                // 31. Количество топлива (газ) в натуральном исчислении (param_id = 271)
                $gasFuelQuantity = calculateGasFuelQuantity($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 12); // row_num 12
                $values[] = [
                    'param_id' => 271, // Количество топлива (газ) в натуральном исчислении
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $gasFuelQuantity,
                    'cell' => $cell
                ];
                
                // 32. Количество топлива (мазут) в натуральном исчислении (param_id = 272)
                $oilFuelQuantity = calculateOilFuelQuantity($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 13); // row_num 13
                $values[] = [
                    'param_id' => 272, // Количество топлива (мазут) в натуральном исчислении
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $oilFuelQuantity,
                    'cell' => $cell
                ];
                
                // 33. Выработка тепла котлом (param_id = 278)
                $boilerHeatGeneration = calculateReheatSteamFlow($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 19); // row_num 19
                $values[] = [
                    'param_id' => 278, // Выработка тепла котлом
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $boilerHeatGeneration,
                    'cell' => $cell
                ];
                
            }
            
            // === РАСЧЕТЫ ЗАВИСИМЫХ ПАРАМЕТРОВ (после расчета всех базовых) ===
            
            // 33-35. Расчеты расхода топлива для всех блоков
            foreach ($blockIds as $blockId) {
                // 33. Фактический расход топлива на производство электроэнергии в пересчете на условное (газ) (param_id = 273)
                $gasFuelConsumption = calculateGasFuelConsumption($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 14); // row_num 14
                $values[] = [
                    'param_id' => 273, // Фактический расход топлива на производство электроэнергии в пересчете на условное (газ)
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $gasFuelConsumption,
                    'cell' => $cell
                ];
                
                // 34. Фактический расход топлива на производство электроэнергии в пересчете на условное (мазут) (param_id = 274)
                $oilFuelConsumption = calculateOilFuelConsumption($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 15); // row_num 15
                $values[] = [
                    'param_id' => 274, // Фактический расход топлива на производство электроэнергии в пересчете на условное (мазут)
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $oilFuelConsumption,
                    'cell' => $cell
                ];
                
                // 35. Полный расход условного топлива на выработку электроэнергии за месяц (param_id = 275)
                $totalFuelConsumption = calculateTotalFuelConsumption($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 16); // row_num 16
                $values[] = [
                    'param_id' => 275, // Полный расход условного топлива на выработку электроэнергии за месяц
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $totalFuelConsumption,
                    'cell' => $cell
                ];
                
                // 36. Доля газа в общем расходе топлива (param_id = 276)
                $gasFuelShare = calculateGasFuelShare($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 17); // row_num 17
                $values[] = [
                    'param_id' => 276, // Доля газа в общем расходе топлива
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $gasFuelShare,
                    'cell' => $cell
                ];
                
                // 37. Число часов работы группы котлов (param_id = 277)
                $boilerWorkingHours = calculateBoilerWorkingHours($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 18); // row_num 18
                $values[] = [
                    'param_id' => 277, // Число часов работы группы котлов
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $boilerWorkingHours,
                    'cell' => $cell
                ];
                
                // 38. Средняя тепловая нагрузка котлов (param_id = 279)
                $avgThermalLoad = calculateAvgThermalLoad($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 21); // row_num 21
                $values[] = [
                    'param_id' => 279, // Средняя тепловая нагрузка котлов
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $avgThermalLoad,
                    'cell' => $cell
                ];
                
                // 39. Средняя электрическая нагрузка (param_id = 280) - берет значение из 3a E26/F26
                $avgElectricLoad = calculateAvgElectricLoadFrom3a($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 22); // row_num 22
                $values[] = [
                    'param_id' => 280, // Средняя электрическая нагрузка
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $avgElectricLoad,
                    'cell' => $cell
                ];
                
                // 40. Расход питательной воды группой котлов за месяц (param_id = 281)
                $feedwaterFlowMonthly = getFeedwaterFlow($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 23); // row_num 23
                $values[] = [
                    'param_id' => 281, // Расход питательной воды группой котлов за месяц
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $feedwaterFlowMonthly,
                    'cell' => $cell
                ];
                
                // 41. Средний расход питательной воды (param_id = 282)
                $avgFeedwaterFlow = calculateAvgFeedwaterFlow($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 24); // row_num 24
                $values[] = [
                    'param_id' => 282, // Средний расход питательной воды
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $avgFeedwaterFlow,
                    'cell' => $cell
                ];

                // 5. Количество пусков (param_id = 30)
                $startCount = getStartCount($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 25); // row_num 18
                $values[] = [
                    'param_id' => 283, // Количество пусков
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $startCount,
                    'cell' => $cell
                ];
                
                // 6. Температура холодного воздуха на стороне всасывания дутьевого вентилятора (param_id = 284)
                $coldAirTemp = calculateColdAirTemperature($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 26); // row_num 26
                $values[] = [
                    'param_id' => 284, // Температура холодного воздуха на стороне всасывания дутьевого вентилятора
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $coldAirTemp,
                    'cell' => $cell
                ];
                
                // 7. Температура питательной воды (param_id = 285)
                $feedwaterTemp = calculateFeedwaterTemperature($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 27); // row_num 27
                $values[] = [
                    'param_id' => 285, // Температура питательной воды
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $feedwaterTemp,
                    'cell' => $cell
                ];
                
                // 8. Продолжительность работы котла от даты составления энергетических характеристик (param_id = 286)
                $boilerOperationDuration = calculateBoilerOperationDuration($date, $shiftId, $blockId);
                $cell = getCellForBlock($blockId, 28); // row_num 28
                $values[] = [
                    'param_id' => 286, // Продолжительность работы котла от даты составления энергетических характеристик
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $boilerOperationDuration,
                    'cell' => $cell
                ];
                
                // 9. КПД брутто котла (param_id = 288)
                $boilerEfficiency = calculateBoilerEfficiency($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 32); // row_num 32
                $values[] = [
                    'param_id' => 288, // КПД брутто котла
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $boilerEfficiency,
                    'cell' => $cell
                ];
                
                // 10. Поправка на температуру питательной воды (param_id = 289)
                $feedwaterTempCorrection = calculateFeedwaterTempCorrection($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 34); // row_num 34
                $values[] = [
                    'param_id' => 289, // Поправка на температуру питательной воды
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $feedwaterTempCorrection,
                    'cell' => $cell
                ];
                
                // 11. Поправка на продолжительность работы котла (param_id = 290)
                $operationDurationCorrection = calculateOperationDurationCorrection($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 35); // row_num 35
                $values[] = [
                    'param_id' => 290, // Поправка на продолжительность работы котла
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $operationDurationCorrection,
                    'cell' => $cell
                ];
                
                // 12. Поправка на температуру холодного воздуха (param_id = 291)
                $coldAirTempCorrection = calculateColdAirTempCorrection($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 36); // row_num 36
                $values[] = [
                    'param_id' => 291, // Поправка на температуру холодного воздуха
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $coldAirTempCorrection,
                    'cell' => $cell
                ];
                
                // 13. Поправка на температуру холодного воздуха в процентах (param_id = 292)
                $coldAirTempCorrectionPercent = calculateColdAirTempCorrectionPercent($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 37); // row_num 37
                $values[] = [
                    'param_id' => 292, // Поправка на температуру холодного воздуха в процентах
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $coldAirTempCorrectionPercent,
                    'cell' => $cell
                ];
                
                // 14. КПД брутто котла с поправками (param_id = 293)
                $boilerEfficiencyWithCorrections = calculateBoilerEfficiencyWithCorrections($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 38); // row_num 38
                $values[] = [
                    'param_id' => 293, // КПД брутто котла с поправками
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $boilerEfficiencyWithCorrections,
                    'cell' => $cell
                ];
                
                // 15. КПД нетто котла (param_id = 294)
                $boilerNetEfficiency = calculateBoilerNetEfficiency($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 39); // row_num 39
                $values[] = [
                    'param_id' => 294, // КПД нетто котла
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $boilerNetEfficiency,
                    'cell' => $cell
                ];
                
                // 16. Удельный расход топлива (param_id = 295)
                $specificFuelConsumption = calculateSpecificFuelConsumption($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 40); // row_num 40
                $values[] = [
                    'param_id' => 295, // Удельный расход топлива
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $specificFuelConsumption,
                    'cell' => $cell
                ];
                
                // 17. Поправка на КПД (param_id = 296)
                $efficiencyCorrection = calculateEfficiencyCorrection($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 41); // row_num 41
                $values[] = [
                    'param_id' => 296, // Поправка на КПД
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $efficiencyCorrection,
                    'cell' => $cell
                ];
                
                // 18. Расход топлива (param_id = 297)
                $fuelConsumption = calculateFuelConsumption($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 43); // row_num 43
                $values[] = [
                    'param_id' => 297, // Расход топлива
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $fuelConsumption,
                    'cell' => $cell
                ];
                
                // 19. Удельный расход топлива на выработку тепла (param_id = 298)
                $specificFuelConsumptionForHeat = calculateSpecificFuelConsumptionForHeat($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 44); // row_num 44
                $values[] = [
                    'param_id' => 298, // Удельный расход топлива на выработку тепла
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $specificFuelConsumptionForHeat,
                    'cell' => $cell
                ];
                
                // 20. Удельный расход топлива на выработку электроэнергии (param_id = 299)
                $specificFuelConsumptionForElectricity = calculateSpecificFuelConsumptionForElectricity($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 45); // row_num 45
                $values[] = [
                    'param_id' => 299, // Удельный расход топлива на выработку электроэнергии
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $specificFuelConsumptionForElectricity,
                    'cell' => $cell
                ];
                
                // 21. Расход топлива на собственные нужды (param_id = 300)
                $fuelConsumptionForOwnNeeds = calculateFuelConsumptionForOwnNeeds($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 47); // row_num 47
                $values[] = [
                    'param_id' => 300, // Расход топлива на собственные нужды
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $fuelConsumptionForOwnNeeds,
                    'cell' => $cell
                ];
                
                // 22. Удельный расход топлива на собственные нужды (param_id = 301)
                $specificFuelConsumptionForOwnNeeds = calculateSpecificFuelConsumptionForOwnNeeds($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 49); // row_num 49
                $values[] = [
                    'param_id' => 301, // Удельный расход топлива на собственные нужды
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $specificFuelConsumptionForOwnNeeds,
                    'cell' => $cell
                ];
                
                // 23. КПД нетто котла с учетом собственных нужд (param_id = 302)
                $netEfficiencyWithOwnNeeds = calculateNetEfficiencyWithOwnNeeds($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 50); // row_num 50
                $values[] = [
                    'param_id' => 302, // КПД нетто котла с учетом собственных нужд
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $netEfficiencyWithOwnNeeds,
                    'cell' => $cell
                ];
                
                // 24. Общий удельный расход топлива (param_id = 303)
                $totalSpecificFuelConsumption = calculateTotalSpecificFuelConsumption($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 51); // row_num 51
                $values[] = [
                    'param_id' => 303, // Общий удельный расход топлива
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $totalSpecificFuelConsumption,
                    'cell' => $cell
                ];
                
                // Category 4 calculations (Прочие параметры)
                // 1. Коэффициент теплового потока, % (param_id = 312)
                $heatFlowCoefficient = calculateHeatFlowCoefficient($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 7); // row_num 7
                $values[] = [
                    'param_id' => 1, // Коэффициент теплового потока, %
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $heatFlowCoefficient,
                    'cell' => $cell
                ];
                
                // 2. Коэффициент учитывающий влияние стабилизации тепловых процессов, % (param_id = 313)
                $stabilizationCoefficient = calculateStabilizationCoefficient($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 8); // row_num 8
                $values[] = [
                    'param_id' => 2, // Коэффициент учитывающий влияние стабилизации тепловых процессов, %
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $stabilizationCoefficient,
                    'cell' => $cell
                ];
                
                // 3. Удельный расход условного топлива на отпуск электроэнергии (param_id = 314)
                $specificFuelConsumptionForElectricity4 = calculateSpecificFuelConsumptionForElectricity4($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 9); // row_num 9
                $values[] = [
                    'param_id' => 3, // Удельный расход условного топлива на отпуск электроэнергии
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $specificFuelConsumptionForElectricity4,
                    'cell' => $cell
                ];
                
                // 4. Номинальное значение без учета работы ОИУ (param_id = 315)
                $nominalValueWithoutOIU = calculateNominalValueWithoutOIU($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 10); // row_num 10
                $values[] = [
                    'param_id' => 4, // Номинальное значение без учета работы ОИУ
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $nominalValueWithoutOIU,
                    'cell' => $cell
                ];
                
                // 5. Поправка к удельному расходу топлива на пуски (param_id = 316)
                $startupCorrection = calculateStartupCorrection($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 11); // row_num 11
                $values[] = [
                    'param_id' => 5, // Поправка к удельному расходу топлива на пуски
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $startupCorrection,
                    'cell' => $cell
                ];
                
                // 6. Поправка к удельному расходу топлива на cos φ (param_id = 317)
                $cosPhiCorrection = calculateCosPhiCorrection($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 12); // row_num 12
                $values[] = [
                    'param_id' => 6, // Поправка к удельному расходу топлива на cos φ
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $cosPhiCorrection,
                    'cell' => $cell
                ];
                
                // 7. Поправка к удельному расходу топлива на работу ОИУ (param_id = 318)
                $oiuCorrection = calculateOIUCorrection($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 13); // row_num 13
                $values[] = [
                    'param_id' => 7, // Поправка к удельному расходу топлива на работу ОИУ
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $oiuCorrection,
                    'cell' => $cell
                ];
                
                // 8. Поправка к удельному расходу топлива на карбонатный занос конденсатора (param_id = 319)
                $carbonateCorrection = calculateCarbonateCorrection($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 14); // row_num 14
                $values[] = [
                    'param_id' => 8, // Поправка к удельному расходу топлива на карбонатный занос конденсатора
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $carbonateCorrection,
                    'cell' => $cell
                ];
                
                // 9. Поправка к удельному расходу топлива на режимы работы (param_id = 320)
                $operationModeCorrection = calculateOperationModeCorrection($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 15); // row_num 15
                $values[] = [
                    'param_id' => 9, // Поправка к удельному расходу топлива на режимы работы
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $operationModeCorrection,
                    'cell' => $cell
                ];
                
                // 10. Поправка к удельному расходу топлива на работу БН (param_id = 321)
                $bnCorrection = calculateBNCorrection($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 16); // row_num 16
                $values[] = [
                    'param_id' => 10, // Поправка к удельному расходу топлива на работу БН
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $bnCorrection,
                    'cell' => $cell
                ];
                
                // 11. Номинальное значение с учетом работы ОИУ и других факторов (Блоки) (param_id = 322)
                $nominalValueWithOIU = calculateNominalValueWithOIU($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 17); // row_num 17
                $values[] = [
                    'param_id' => 11, // Номинальное значение с учетом работы ОИУ и других факторов (Блоки)
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $nominalValueWithOIU,
                    'cell' => $cell
                ];
                
                // 12. Номинальное значение, (для ПГУ) (param_id = 323)
                $nominalValueForPGU = calculateNominalValueForPGU($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 18); // row_num 18
                $values[] = [
                    'param_id' => 12, // Номинальное значение, (для ПГУ)
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $nominalValueForPGU,
                    'cell' => $cell
                ];
                
                // 13. Фактическое значение (param_id = 324)
                $actualValue = calculateActualValue($date, $shiftId, $blockId, $values);
                $cell = getCellForBlock($blockId, 19); // row_num 19
                $values[] = [
                    'param_id' => 13, // Фактическое значение
                    'tg_id' => $blockId,
                    'shift_id' => (int)$shiftId,
                    'value' => $actualValue,
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
            
            // === РАСЧЕТЫ КОТЛОВ (category 3b) ===
            
            // 29. Фактическое качество сожженного топлива (газ) (param_id = 269)
            $gasFuelQuality = calculateGasFuelQuality($date, null, $blockId);
            $cell = getCellForBlock($blockId, 10); // row_num 10
            $values[] = [
                'param_id' => 269, // Фактическое качество сожженного топлива (газ)
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $gasFuelQuality,
                'cell' => $cell
            ];
            
            // 30. Фактическое качество сожженного топлива (мазут) (param_id = 270)
            $oilFuelQuality = calculateOilFuelQuality($date, null, $blockId);
            $cell = getCellForBlock($blockId, 11); // row_num 11
            $values[] = [
                'param_id' => 270, // Фактическое качество сожженного топлива (мазут)
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $oilFuelQuality,
                'cell' => $cell
            ];
            
            // 31. Количество топлива (газ) в натуральном исчислении (param_id = 271)
            $gasFuelQuantity = calculateGasFuelQuantity($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 12); // row_num 12
            $values[] = [
                'param_id' => 271, // Количество топлива (газ) в натуральном исчислении
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $gasFuelQuantity,
                'cell' => $cell
            ];
            
            // 32. Количество топлива (мазут) в натуральном исчислении (param_id = 272)
            $oilFuelQuantity = calculateOilFuelQuantity($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 13); // row_num 13
            $values[] = [
                'param_id' => 272, // Количество топлива (мазут) в натуральном исчислении
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $oilFuelQuantity,
                'cell' => $cell
            ];
            
            // 33. Выработка тепла котлом (param_id = 278)
            $boilerHeatGeneration = calculateReheatSteamFlow($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 19); // row_num 19
            $values[] = [
                'param_id' => 278, // Выработка тепла котлом
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $boilerHeatGeneration,
                'cell' => $cell
            ];
            
        }
        
        // === РАСЧЕТЫ ЗАВИСИМЫХ ПАРАМЕТРОВ (после расчета всех базовых) ===
        
        // 33-35. Расчеты расхода топлива для всех блоков
        foreach ($blockIds as $blockId) {
            // 33. Фактический расход топлива на производство электроэнергии в пересчете на условное (газ) (param_id = 273)
            $gasFuelConsumption = calculateGasFuelConsumption($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 14); // row_num 14
            $values[] = [
                'param_id' => 273, // Фактический расход топлива на производство электроэнергии в пересчете на условное (газ)
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $gasFuelConsumption,
                'cell' => $cell
            ];
            
            // 34. Фактический расход топлива на производство электроэнергии в пересчете на условное (мазут) (param_id = 274)
            $oilFuelConsumption = calculateOilFuelConsumption($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 15); // row_num 15
            $values[] = [
                'param_id' => 274, // Фактический расход топлива на производство электроэнергии в пересчете на условное (мазут)
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $oilFuelConsumption,
                'cell' => $cell
            ];
            
            // 35. Полный расход условного топлива на выработку электроэнергии за месяц (param_id = 275)
            $totalFuelConsumption = calculateTotalFuelConsumption($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 16); // row_num 16
            $values[] = [
                'param_id' => 275, // Полный расход условного топлива на выработку электроэнергии за месяц
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $totalFuelConsumption,
                'cell' => $cell
            ];
            
            // 36. Доля газа в общем расходе топлива (param_id = 276)
            $gasFuelShare = calculateGasFuelShare($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 17); // row_num 17
            $values[] = [
                'param_id' => 276, // Доля газа в общем расходе топлива
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $gasFuelShare,
                'cell' => $cell
            ];
            
            // 37. Число часов работы группы котлов (param_id = 277)
            $boilerWorkingHours = calculateBoilerWorkingHours($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 18); // row_num 18
            $values[] = [
                'param_id' => 277, // Число часов работы группы котлов
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $boilerWorkingHours,
                'cell' => $cell
            ];
            
            // 38. Средняя тепловая нагрузка котлов (param_id = 279)
            $avgThermalLoad = calculateAvgThermalLoad($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 21); // row_num 21
            $values[] = [
                'param_id' => 279, // Средняя тепловая нагрузка котлов
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $avgThermalLoad,
                'cell' => $cell
            ];
            
            // 39. Средняя электрическая нагрузка (param_id = 280) - берет значение из 3a E26/F26
            $avgElectricLoad = calculateAvgElectricLoadFrom3a($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 22); // row_num 22
            $values[] = [
                'param_id' => 280, // Средняя электрическая нагрузка
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $avgElectricLoad,
                'cell' => $cell
            ];
            
            // 40. Расход питательной воды группой котлов за месяц (param_id = 281)
            $feedwaterFlowMonthly = getFeedwaterFlow($date, null, $blockId);
            $cell = getCellForBlock($blockId, 23); // row_num 23
            $values[] = [
                'param_id' => 281, // Расход питательной воды группой котлов за месяц
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $feedwaterFlowMonthly,
                'cell' => $cell
            ];
            
            // 41. Средний расход питательной воды (param_id = 282)
            $avgFeedwaterFlow = calculateAvgFeedwaterFlow($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 24); // row_num 24
            $values[] = [
                'param_id' => 282, // Средний расход питательной воды
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $avgFeedwaterFlow,
                'cell' => $cell
            ];
            
            // 5. Количество пусков (param_id = 30)
            $startCount = getStartCount($date, null, $blockId);
            $cell = getCellForBlock($blockId, 25); // row_num 18
            $values[] = [
                'param_id' => 283, // Количество пусков
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $startCount,
                'cell' => $cell
            ];
            
            // 6. Температура холодного воздуха на стороне всасывания дутьевого вентилятора (param_id = 284)
            $coldAirTemp = calculateColdAirTemperature($date, null, $blockId);
            $cell = getCellForBlock($blockId, 26); // row_num 26
            $values[] = [
                'param_id' => 284, // Температура холодного воздуха на стороне всасывания дутьевого вентилятора
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $coldAirTemp,
                'cell' => $cell
            ];
            
            // 7. Температура питательной воды (param_id = 285)
            $feedwaterTemp = calculateFeedwaterTemperature($date, null, $blockId);
            $cell = getCellForBlock($blockId, 27); // row_num 27
            $values[] = [
                'param_id' => 285, // Температура питательной воды
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $feedwaterTemp,
                'cell' => $cell
            ];
            
            // 8. Продолжительность работы котла от даты составления энергетических характеристик (param_id = 286)
            $boilerOperationDuration = calculateBoilerOperationDuration($date, null, $blockId);
            $cell = getCellForBlock($blockId, 28); // row_num 28
            $values[] = [
                'param_id' => 286, // Продолжительность работы котла от даты составления энергетических характеристик
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $boilerOperationDuration,
                'cell' => $cell
            ];
            
            // 9. КПД брутто котла (param_id = 288)
            $boilerEfficiency = calculateBoilerEfficiency($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 32); // row_num 32
            $values[] = [
                'param_id' => 288, // КПД брутто котла
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $boilerEfficiency,
                'cell' => $cell
            ];
            
            // 10. Поправка на температуру питательной воды (param_id = 289)
            $feedwaterTempCorrection = calculateFeedwaterTempCorrection($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 34); // row_num 34
            $values[] = [
                'param_id' => 289, // Поправка на температуру питательной воды
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $feedwaterTempCorrection,
                'cell' => $cell
            ];
            
            // 11. Поправка на продолжительность работы котла (param_id = 290)
            $operationDurationCorrection = calculateOperationDurationCorrection($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 35); // row_num 35
            $values[] = [
                'param_id' => 290, // Поправка на продолжительность работы котла
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $operationDurationCorrection,
                'cell' => $cell
            ];
            
            // 12. Поправка на температуру холодного воздуха (param_id = 291)
            $coldAirTempCorrection = calculateColdAirTempCorrection($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 36); // row_num 36
            $values[] = [
                'param_id' => 291, // Поправка на температуру холодного воздуха
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $coldAirTempCorrection,
                'cell' => $cell
            ];
            
            // 13. Поправка на температуру холодного воздуха в процентах (param_id = 292)
            $coldAirTempCorrectionPercent = calculateColdAirTempCorrectionPercent($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 37); // row_num 37
            $values[] = [
                'param_id' => 292, // Поправка на температуру холодного воздуха в процентах
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $coldAirTempCorrectionPercent,
                'cell' => $cell
            ];
            
            // 14. КПД брутто котла с поправками (param_id = 293)
            $boilerEfficiencyWithCorrections = calculateBoilerEfficiencyWithCorrections($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 38); // row_num 38
            $values[] = [
                'param_id' => 293, // КПД брутто котла с поправками
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $boilerEfficiencyWithCorrections,
                'cell' => $cell
            ];
            
            // 15. КПД нетто котла (param_id = 294)
            $boilerNetEfficiency = calculateBoilerNetEfficiency($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 39); // row_num 39
            $values[] = [
                'param_id' => 294, // КПД нетто котла
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $boilerNetEfficiency,
                'cell' => $cell
            ];
            
            // 16. Удельный расход топлива (param_id = 295)
            $specificFuelConsumption = calculateSpecificFuelConsumption($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 40); // row_num 40
            $values[] = [
                'param_id' => 295, // Удельный расход топлива
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $specificFuelConsumption,
                'cell' => $cell
            ];
            
            // 17. Поправка на КПД (param_id = 296)
            $efficiencyCorrection = calculateEfficiencyCorrection($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 41); // row_num 41
            $values[] = [
                'param_id' => 296, // Поправка на КПД
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $efficiencyCorrection,
                'cell' => $cell
            ];
            
            // 18. Расход топлива (param_id = 297)
            $fuelConsumption = calculateFuelConsumption($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 43); // row_num 43
            $values[] = [
                'param_id' => 297, // Расход топлива
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $fuelConsumption,
                'cell' => $cell
            ];
            
            // 19. Удельный расход топлива на выработку тепла (param_id = 298)
            $specificFuelConsumptionForHeat = calculateSpecificFuelConsumptionForHeat($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 44); // row_num 44
            $values[] = [
                'param_id' => 298, // Удельный расход топлива на выработку тепла
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $specificFuelConsumptionForHeat,
                'cell' => $cell
            ];
            
            // 20. Удельный расход топлива на выработку электроэнергии (param_id = 299)
            $specificFuelConsumptionForElectricity = calculateSpecificFuelConsumptionForElectricity($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 45); // row_num 45
            $values[] = [
                'param_id' => 299, // Удельный расход топлива на выработку электроэнергии
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $specificFuelConsumptionForElectricity,
                'cell' => $cell
            ];
            
            // 21. Расход топлива на собственные нужды (param_id = 300)
            $fuelConsumptionForOwnNeeds = calculateFuelConsumptionForOwnNeeds($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 47); // row_num 47
            $values[] = [
                'param_id' => 300, // Расход топлива на собственные нужды
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $fuelConsumptionForOwnNeeds,
                'cell' => $cell
            ];
            
            // 22. Удельный расход топлива на собственные нужды (param_id = 301)
            $specificFuelConsumptionForOwnNeeds = calculateSpecificFuelConsumptionForOwnNeeds($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 49); // row_num 49
            $values[] = [
                'param_id' => 301, // Удельный расход топлива на собственные нужды
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $specificFuelConsumptionForOwnNeeds,
                'cell' => $cell
            ];
            
            // 23. КПД нетто котла с учетом собственных нужд (param_id = 302)
            $netEfficiencyWithOwnNeeds = calculateNetEfficiencyWithOwnNeeds($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 50); // row_num 50
            $values[] = [
                'param_id' => 302, // КПД нетто котла с учетом собственных нужд
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $netEfficiencyWithOwnNeeds,
                'cell' => $cell
            ];
            
            // 24. Общий удельный расход топлива (param_id = 303)
            $totalSpecificFuelConsumption = calculateTotalSpecificFuelConsumption($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 51); // row_num 51
            $values[] = [
                'param_id' => 303, // Общий удельный расход топлива
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $totalSpecificFuelConsumption,
                'cell' => $cell
            ];
            
            // Category 4 calculations (Прочие параметры)
            // 1. Коэффициент теплового потока, % (param_id = 312)
            $heatFlowCoefficient = calculateHeatFlowCoefficient($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 7); // row_num 7
            $values[] = [
                'param_id' => 1, // Коэффициент теплового потока, %
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $heatFlowCoefficient,
                'cell' => $cell
            ];
            
            // 2. Коэффициент учитывающий влияние стабилизации тепловых процессов, % (param_id = 313)
            $stabilizationCoefficient = calculateStabilizationCoefficient($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 8); // row_num 8
            $values[] = [
                'param_id' => 2, // Коэффициент учитывающий влияние стабилизации тепловых процессов, %
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $stabilizationCoefficient,
                'cell' => $cell
            ];
            
            // 3. Удельный расход условного топлива на отпуск электроэнергии (param_id = 314)
            $specificFuelConsumptionForElectricity4 = calculateSpecificFuelConsumptionForElectricity4($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 9); // row_num 9
            $values[] = [
                'param_id' => 3, // Удельный расход условного топлива на отпуск электроэнергии
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $specificFuelConsumptionForElectricity4,
                'cell' => $cell
            ];
            
            // 4. Номинальное значение без учета работы ОИУ (param_id = 315)
            $nominalValueWithoutOIU = calculateNominalValueWithoutOIU($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 10); // row_num 10
            $values[] = [
                'param_id' => 4, // Номинальное значение без учета работы ОИУ
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $nominalValueWithoutOIU,
                'cell' => $cell
            ];
            
            // 5. Поправка к удельному расходу топлива на пуски (param_id = 316)
            $startupCorrection = calculateStartupCorrection($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 11); // row_num 11
            $values[] = [
                'param_id' => 5, // Поправка к удельному расходу топлива на пуски
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $startupCorrection,
                'cell' => $cell
            ];
            
            // 6. Поправка к удельному расходу топлива на cos φ (param_id = 317)
            $cosPhiCorrection = calculateCosPhiCorrection($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 12); // row_num 12
            $values[] = [
                'param_id' => 6, // Поправка к удельному расходу топлива на cos φ
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $cosPhiCorrection,
                'cell' => $cell
            ];
            
            // 7. Поправка к удельному расходу топлива на работу ОИУ (param_id = 318)
            $oiuCorrection = calculateOIUCorrection($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 13); // row_num 13
            $values[] = [
                'param_id' => 7, // Поправка к удельному расходу топлива на работу ОИУ
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $oiuCorrection,
                'cell' => $cell
            ];
            
            // 8. Поправка к удельному расходу топлива на карбонатный занос конденсатора (param_id = 319)
            $carbonateCorrection = calculateCarbonateCorrection($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 14); // row_num 14
            $values[] = [
                'param_id' => 8, // Поправка к удельному расходу топлива на карбонатный занос конденсатора
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $carbonateCorrection,
                'cell' => $cell
            ];
            
            // 9. Поправка к удельному расходу топлива на режимы работы (param_id = 320)
            $operationModeCorrection = calculateOperationModeCorrection($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 15); // row_num 15
            $values[] = [
                'param_id' => 9, // Поправка к удельному расходу топлива на режимы работы
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $operationModeCorrection,
                'cell' => $cell
            ];
            
            // 10. Поправка к удельному расходу топлива на работу БН (param_id = 321)
            $bnCorrection = calculateBNCorrection($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 16); // row_num 16
            $values[] = [
                'param_id' => 10, // Поправка к удельному расходу топлива на работу БН
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $bnCorrection,
                'cell' => $cell
            ];
            
            // 11. Номинальное значение с учетом работы ОИУ и других факторов (Блоки) (param_id = 322)
            $nominalValueWithOIU = calculateNominalValueWithOIU($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 17); // row_num 17
            $values[] = [
                'param_id' => 11, // Номинальное значение с учетом работы ОИУ и других факторов (Блоки)
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $nominalValueWithOIU,
                'cell' => $cell
            ];
            
            // 12. Номинальное значение, (для ПГУ) (param_id = 323)
            $nominalValueForPGU = calculateNominalValueForPGU($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 18); // row_num 18
            $values[] = [
                'param_id' => 12, // Номинальное значение, (для ПГУ)
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $nominalValueForPGU,
                'cell' => $cell
            ];
            
            // 13. Фактическое значение (param_id = 324)
            $actualValue = calculateActualValue($date, null, $blockId, $values);
            $cell = getCellForBlock($blockId, 19); // row_num 19
            $values[] = [
                'param_id' => 13, // Фактическое значение
                'tg_id' => $blockId,
                'shift_id' => null,
                'value' => $actualValue,
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
        // Для ОЧ-130 (blockId = 9) отпуск = сумма отпуска ТГ7 + ТГ8
        if ($blockId == 9) {
            $tg7Release = calculateElectricityRelease($date, $shiftId, 7);
            $tg8Release = calculateElectricityRelease($date, $shiftId, 8);
            error_log("Расчет отпуска для ОЧ-130: ТГ7=$tg7Release, ТГ8=$tg8Release, сумма=" . ($tg7Release + $tg8Release));
            return $tg7Release + $tg8Release;
        }
        
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
            error_log("Собственные нужды (прямые счетчики) для блока $blockId, смена $shiftId: $totalConsumption");
        }
        
        // 2. Получаем долю от общих счетчиков для собственных нужд по показаниям
        $commonMeterShares = calculateCommonMeterShares($date);
        
        if (!empty($commonMeterShares) && isset($commonMeterShares[$equipmentId])) {
            $equipmentData = $commonMeterShares[$equipmentId];
            
            if ($shiftId !== null) {
                // Для смены - получаем долю от общих счетчиков для собственных нужд
                $commonOwnNeeds = $equipmentData['shifts'][$shiftId] ?? 0;
                $totalConsumption += $commonOwnNeeds;
                
                error_log("Собственные нужды для блока $blockId, смена $shiftId: собственные счетчики=" . ($totalConsumption - $commonOwnNeeds) . ", общие счетчики=$commonOwnNeeds, итого=$totalConsumption");
            } else {
                // Для суточного расчета - суммируем все смены
                $commonOwnNeeds = 0;
                for ($i = 1; $i <= 3; $i++) {
                    $commonOwnNeeds += $equipmentData['shifts'][$i] ?? 0;
                }
                $totalConsumption += $commonOwnNeeds;
                
                error_log("Собственные нужды для блока $blockId, сутки: собственные счетчики=" . ($totalConsumption - $commonOwnNeeds) . ", общие счетчики=$commonOwnNeeds, итого=$totalConsumption");
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
            $value = (float)$result['value'];
            error_log("getFeedwaterFlowForBlock: equipment_id=$equipmentId, date=$date, shift_id=$shiftId, value=$value");
            return $value;
        }
        
        error_log("getFeedwaterFlowForBlock: equipment_id=$equipmentId, date=$date, shift_id=$shiftId, НЕ НАЙДЕНО, возвращаем 0");
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
            return limitDecimalValue($result, "calculateParameter51 for block $blockId, shift $shiftId");
            
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
            return limitDecimalValue($result, "calculateParameter51 for block 9 (ОЧ-130)");
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
        return limitDecimalValue($result, "calculateParameter52");
        
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
 * Расчет долей общих счетчиков по показаниям с учетом времени использования
 * @param string $date Дата расчета
 * @return array Массив с долями по оборудованию и сменам
 */
function calculateCommonMeterShares($date) {
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
            
            // Получаем показания счетчика
            if (!isset($meterReadings[$meterId])) {
                error_log("Нет показаний для счетчика $meterId на дату $date");
                continue;
            }
            
            $reading = $meterReadings[$meterId];
            $r0 = (float)$reading['r0'];
            $r8 = (float)$reading['r8'];
            $r16 = (float)$reading['r16'];
            $r24 = (float)$reading['r24'];
            
            // Определяем границы смен с правильной логикой
            $shiftBoundaries = [
                1 => ['start' => '00:00:00', 'end' => '08:00:00', 'r_start' => $r0, 'r_end' => $r8],
                2 => ['start' => '08:00:00', 'end' => '16:00:00', 'r_start' => $r8, 'r_end' => $r16],
                3 => ['start' => '16:00:00', 'end' => '24:00:00', 'r_start' => $r16, 'r_end' => $r24]
            ];
            
            // Исправляем логику: если r16 или r24 NULL, используем предыдущее значение
            if ($r16 === null && $r8 !== null) {
                $shiftBoundaries[2]['r_end'] = $r8; // Для 2-й смены используем r8 как конечное значение
            }
            if ($r24 === null && $r16 !== null) {
                $shiftBoundaries[3]['r_end'] = $r16; // Для 3-й смены используем r16 как конечное значение
            } elseif ($r24 === null && $r8 !== null) {
                $shiftBoundaries[3]['r_end'] = $r8; // Если r16 тоже NULL, используем r8
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
                error_log("Оборудование $equipmentId, смена $startShift: полностью в одной смене, потребление $energyConsumed кВт⋅ч");
            } 
            // Если использование пересекает границы смен
            else if ($startShift !== null && $endShift !== null) {
                // Для начальной смены
                $startShiftBoundary = $shiftBoundaries[$startShift];
                $Ra = $startShiftBoundary['r_end']; // Показание на конец начальной смены
                
                if ($Ra !== null && $startReading !== null) {
                    $startShiftConsumed = ($Ra - $startReading) * $coefficient / 1000;
                    $result[$equipmentId]['shifts'][$startShift] += $startShiftConsumed;
                    error_log("Оборудование $equipmentId, смена $startShift: пересечение смен, начальная смена, потребление $startShiftConsumed кВт⋅ч");
                }
                
                // Для конечной смены
                $endShiftBoundary = $shiftBoundaries[$endShift];
                $Rb = $endShiftBoundary['r_start']; // Показание на начало конечной смены
                
                if ($Rb !== null && $endReading !== null) {
                    $endShiftConsumed = ($endReading - $Rb) * $coefficient / 1000;
                    $result[$equipmentId]['shifts'][$endShift] += $endShiftConsumed;
                    error_log("Оборудование $equipmentId, смена $endShift: пересечение смен, конечная смена, потребление $endShiftConsumed кВт⋅ч");
                }
                
                // Для промежуточных смен (если есть)
                for ($i = $startShift + 1; $i < $endShift; $i++) {
                    if (isset($shiftBoundaries[$i]) && $shiftBoundaries[$i]['r_start'] !== null && $shiftBoundaries[$i]['r_end'] !== null) {
                        $intermediateConsumed = ($shiftBoundaries[$i]['r_end'] - $shiftBoundaries[$i]['r_start']) * $coefficient / 1000;
                        $result[$equipmentId]['shifts'][$i] += $intermediateConsumed;
                        error_log("Оборудование $equipmentId, смена $i: пересечение смен, промежуточная смена, потребление $intermediateConsumed кВт⋅ч");
                    }
                }
            }
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете долей общих счетчиков: ' . $e->getMessage());
        return [];
    }
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
        
        // Хозяйственные нужды теперь одно значение на весь день
        // Суммируем расход по всем счетчикам хозяйственных нужд
        $totalConsumption = 0;
        foreach ($readings as $reading) {
            // Всегда используем поле 'total', так как хозяйственные нужды не делятся по сменам
            $totalConsumption += (float)($reading['total'] ?? 0);
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

/**
 * Расчет фактического качества сожженного топлива (газ) (param_id = 269)
 * Для ТГ7 и ТГ8: нет значений
 * Для ОЧ-130: значение из исходных данных (E28)
 */
function calculateGasFuelQuality($date, $shiftId, $blockId) {
    try {
        // Для ТГ7 и ТГ8 нет значений
        if ($blockId == 7 || $blockId == 8) {
            return null;
        }
        
        // Для ОЧ-130 берем значение из исходных данных (E28)
        if ($blockId == 9) {
            $db = getDbConnection();
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 43 AND equipment_id = 7 AND date = ? AND shift_id = ? AND cell = "E28"
            ');
            $stmt->execute([$date, $shiftId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                error_log("Фактическое качество сожженного топлива (газ) для ОЧ-130, смена $shiftId: " . $result['value']);
                return (float)$result['value'];
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете фактического качества сожженного топлива (газ): ' . $e->getMessage());
        return null;
    }
}

/**
 * Расчет фактического качества сожженного топлива (мазут) (param_id = 270)
 * Для ТГ7 и ТГ8: нет значений
 * Для ОЧ-130: значение из исходных данных (E29)
 */
function calculateOilFuelQuality($date, $shiftId, $blockId) {
    try {
        // Для ТГ7 и ТГ8 нет значений
        if ($blockId == 7 || $blockId == 8) {
            return null;
        }
        
        // Для ОЧ-130 берем значение из исходных данных (E29)
        if ($blockId == 9) {
            $db = getDbConnection();
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 44 AND equipment_id = 7 AND date = ? AND shift_id = ? AND cell = "E29"
            ');
            $stmt->execute([$date, $shiftId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                error_log("Фактическое качество сожженного топлива (мазут) для ОЧ-130, смена $shiftId: " . $result['value']);
                return (float)$result['value'];
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете фактического качества сожженного топлива (мазут): ' . $e->getMessage());
        return null;
    }
}

/**
 * Расчет количества топлива (газ) в натуральном исчислении (param_id = 271)
 * E12 = C30 (для ТГ7)
 * F12 = D30 (для ТГ8) 
 * G12 = E12 + F12 (для ОЧ-130)
 */
function calculateGasFuelQuantity($date, $shiftId, $blockId, &$values) {
    try {
        // Для ТГ7: E12 = C30
        if ($blockId == 7) {
            $db = getDbConnection();
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 45 AND equipment_id = 1 AND date = ? AND shift_id = ? AND cell = "C30"
            ');
            $stmt->execute([$date, $shiftId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                error_log("Количество топлива (газ) для ТГ7, смена $shiftId: " . $result['value']);
                return (float)$result['value'];
            }
        }
        
        // Для ТГ8: F12 = D30
        if ($blockId == 8) {
            $db = getDbConnection();
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 45 AND equipment_id = 2 AND date = ? AND shift_id = ? AND cell = "D30"
            ');
            $stmt->execute([$date, $shiftId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                error_log("Количество топлива (газ) для ТГ8, смена $shiftId: " . $result['value']);
                return (float)$result['value'];
            }
        }
        
        // Для ОЧ-130: G12 = E12 + F12
        if ($blockId == 9) {
            // Получаем значение для ТГ7 (E12)
            $tg7Value = getParameterValue($date, $shiftId, 7, 271, $values);
            
            // Получаем значение для ТГ8 (F12)
            $tg8Value = getParameterValue($date, $shiftId, 8, 271, $values);
            
            $sum = $tg7Value + $tg8Value;
            error_log("Количество топлива (газ) для ОЧ-130, смена $shiftId: ТГ7=$tg7Value + ТГ8=$tg8Value = $sum");
            return $sum;
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете количества топлива (газ): ' . $e->getMessage());
        return null;
    }
}

/**
 * Расчет количества топлива (мазут) в натуральном исчислении (param_id = 272)
 * E13 = C31 (для ТГ7)
 * F13 = D31 (для ТГ8) 
 * G13 = E13 + F13 (для ОЧ-130)
 */
function calculateOilFuelQuantity($date, $shiftId, $blockId, &$values) {
    try {
        // Для ТГ7: E13 = C31
        if ($blockId == 7) {
            $db = getDbConnection();
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 46 AND equipment_id = 1 AND date = ? AND shift_id = ? AND cell = "C31"
            ');
            $stmt->execute([$date, $shiftId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                error_log("Количество топлива (мазут) для ТГ7, смена $shiftId: " . $result['value']);
                return (float)$result['value'];
            }
        }
        
        // Для ТГ8: F13 = D31
        if ($blockId == 8) {
            $db = getDbConnection();
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 46 AND equipment_id = 2 AND date = ? AND shift_id = ? AND cell = "D31"
            ');
            $stmt->execute([$date, $shiftId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                error_log("Количество топлива (мазут) для ТГ8, смена $shiftId: " . $result['value']);
                return (float)$result['value'];
            }
        }
        
        // Для ОЧ-130: G13 = E13 + F13
        if ($blockId == 9) {
            // Получаем значение для ТГ7 (E13)
            $tg7Value = getParameterValue($date, $shiftId, 7, 272, $values);
            
            // Получаем значение для ТГ8 (F13)
            $tg8Value = getParameterValue($date, $shiftId, 8, 272, $values);
            
            $sum = $tg7Value + $tg8Value;
            error_log("Количество топлива (мазут) для ОЧ-130, смена $shiftId: ТГ7=$tg7Value + ТГ8=$tg8Value = $sum");
            return $sum;
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете количества топлива (мазут): ' . $e->getMessage());
        return null;
    }
}

/**
 * Расчет фактического расхода топлива на производство электроэнергии в пересчете на условное (газ) (param_id = 273)
 * E14 = G10/7000*E12 (для ТГ7)
 * F14 = G10/7000*F12 (для ТГ8)
 * G14 = E14 + F14 (для ОЧ-130)
 */
function calculateGasFuelConsumption($date, $shiftId, $blockId, &$values) {
    try {
        // Получаем качество газа (параметр 269) - G10
        $gasQuality = getParameterValue($date, $shiftId, 9, 269, $values);
        
        // Получаем количество газа (параметр 271) - E12/F12
        $gasQuantity = getParameterValue($date, $shiftId, $blockId, 271, $values);
        
        if ($gasQuality && $gasQuantity) {
            $consumption = ($gasQuality / 7000) * $gasQuantity;
            error_log("Расход топлива (газ) для блока $blockId, смена $shiftId: ($gasQuality/7000)*$gasQuantity = $consumption");
            return $consumption;
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете расхода топлива (газ): ' . $e->getMessage());
        return null;
    }
}

/**
 * Расчет фактического расхода топлива на производство электроэнергии в пересчете на условное (мазут) (param_id = 274)
 * E15 = G11/7000*E13 (для ТГ7)
 * F15 = G11/7000*F13 (для ТГ8)
 * G15 = E15 + F15 (для ОЧ-130)
 */
function calculateOilFuelConsumption($date, $shiftId, $blockId, &$values) {
    try {
        // Получаем качество мазута (параметр 270) - G11
        $oilQuality = getParameterValue($date, $shiftId, 9, 270, $values);
        
        // Получаем количество мазута (параметр 272) - E13/F13
        $oilQuantity = getParameterValue($date, $shiftId, $blockId, 272, $values);
        
        if ($oilQuality && $oilQuantity) {
            $consumption = ($oilQuality / 7000) * $oilQuantity;
            error_log("Расход топлива (мазут) для блока $blockId, смена $shiftId: ($oilQuality/7000)*$oilQuantity = $consumption");
            return $consumption;
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете расхода топлива (мазут): ' . $e->getMessage());
        return null;
    }
}

/**
 * Расчет полного расхода условного топлива на выработку электроэнергии за месяц (param_id = 275)
 * E16 = E14 + E15 (для ТГ7)
 * F16 = F14 + F15 (для ТГ8)
 * G16 = G14 + G15 (для ОЧ-130)
 */
function calculateTotalFuelConsumption($date, $shiftId, $blockId, &$values) {
    try {
        // Получаем расход газа (параметр 273)
        $gasConsumption = getParameterValue($date, $shiftId, $blockId, 273, $values);
        
        // Получаем расход мазута (параметр 274)
        $oilConsumption = getParameterValue($date, $shiftId, $blockId, 274, $values);
        
        $total = ($gasConsumption ?? 0) + ($oilConsumption ?? 0);
        error_log("Полный расход топлива для блока $blockId, смена $shiftId: газ=$gasConsumption + мазут=$oilConsumption = $total");
        return $total;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете полного расхода топлива: ' . $e->getMessage());
        return null;
    }
}

/**
 * Расчет доли газа в общем расходе топлива (param_id = 276)
 * E17 = E14/E16 (для ТГ7)
 * F17 = F14/F16 (для ТГ8)
 * G17 = G14/G16 (для ОЧ-130)
 */
function calculateGasFuelShare($date, $shiftId, $blockId, &$values) {
    try {
        // Получаем расход газа (параметр 273)
        $gasConsumption = getParameterValue($date, $shiftId, $blockId, 273, $values);
        
        // Получаем общий расход топлива (параметр 275)
        $totalConsumption = getParameterValue($date, $shiftId, $blockId, 275, $values);
        
        if ($totalConsumption && $totalConsumption > 0) {
            $share = $gasConsumption / $totalConsumption;
            error_log("Доля газа для блока $blockId, смена $shiftId: $gasConsumption/$totalConsumption = $share");
            return $share;
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете доли газа: ' . $e->getMessage());
        return null;
    }
}

/**
 * Расчет числа часов работы группы котлов (param_id = 277)
 * E18 = getWorkingHours() для ТГ7
 * F18 = getWorkingHours() для ТГ8
 * G18 = E18 + F18 (для ОЧ-130)
 */
function calculateBoilerWorkingHours($date, $shiftId, $blockId, &$values) {
    try {
        // Для ТГ7 и ТГ8 используем getWorkingHours()
        if ($blockId == 7 || $blockId == 8) {
            $workingHours = getWorkingHours($date, $shiftId, $blockId);
            error_log("Часы работы котлов для блока $blockId, смена $shiftId: $workingHours");
            return $workingHours;
        }
        
        // Для ОЧ-130: G18 = E18 + F18
        if ($blockId == 9) {
            // Получаем часы работы для ТГ7 (E18)
            $tg7Hours = getParameterValue($date, $shiftId, 7, 277, $values);
            
            // Получаем часы работы для ТГ8 (F18)
            $tg8Hours = getParameterValue($date, $shiftId, 8, 277, $values);
            
            $totalHours = $tg7Hours + $tg8Hours;
            error_log("Часы работы котлов для ОЧ-130, смена $shiftId: ТГ7=$tg7Hours + ТГ8=$tg8Hours = $totalHours");
            return $totalHours;
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете часов работы котлов: ' . $e->getMessage());
        return null;
    }
}

/**
 * Расчет выработки тепла котлом (param_id = 278)
 * Формула: IF(C11=0,0,(21.125+0.8218*C20/C11))
 * 
 * Для ТГ7: C11 - часы работы (param_id=28), C20 - выработка пара котлами (parameter_values с cell C20)
 * Для ТГ8: D11 - часы работы (param_id=28), D20 - выработка пара котлами (parameter_values с cell D20)
 * Для ОЧ-130: E11 - часы работы (param_id=28), E20 - сумма выработки пара ТГ7 и ТГ8
 */
function calculateReheatSteamFlow($date, $shiftId, $blockId, $values) {
    try {
        // Получаем часы работы (param_id = 28)
        $workingHours = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 28 && $value['tg_id'] == $blockId) {
                $workingHours = $value['value'];
                break;
            }
        }
        
        error_log("Часы работы для блока $blockId: " . ($workingHours !== null ? $workingHours : 'null'));
        
        if ($workingHours === null || $workingHours == 0) {
            error_log("Часы работы равны 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем выработку пара котлами из parameter_values
        $db = getDbConnection();
        
        // Определяем cell в зависимости от блока
        $cell = '';
        $equipmentId = 0;
        if ($blockId == 7) {
            $cell = 'C20';
            $equipmentId = 1;
        } elseif ($blockId == 8) {
            $cell = 'D20';
            $equipmentId = 2;
        } else {
            // Для ОЧ-130 суммируем значения ТГ7 и ТГ8
            $cell = 'E20';
            $equipmentId = 0; // Не используется для ОЧ-130
        }
        
        error_log("Выработка тепла котлом: блок=$blockId, equipment_id=$equipmentId, cell=$cell, date=$date, shift_id=$shiftId");
        
        // Ищем parameter_id = 35 (Выработка пара котлами)
        if ($blockId == 9) {
            // Для ОЧ-130 суммируем значения ТГ7 и ТГ8
            $steamGeneration = 0;
            
            // Получаем значение для ТГ7 (equipment_id = 1, cell = 'C20')
            if ($shiftId === null) {
                $stmt = $db->prepare('
                    SELECT value FROM parameter_values 
                    WHERE parameter_id = 35 AND equipment_id = 1 AND date = ? AND cell = "C20"
                    LIMIT 1
                ');
                $stmt->execute([$date]);
            } else {
                $stmt = $db->prepare('
                    SELECT value FROM parameter_values 
                    WHERE parameter_id = 35 AND equipment_id = 1 AND date = ? AND shift_id = ? AND cell = "C20"
                ');
                $stmt->execute([$date, $shiftId]);
            }
            $result1 = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result1 && $result1['value']) {
                $steamGeneration += $result1['value'];
            }
            
            // Получаем значение для ТГ8 (equipment_id = 2, cell = 'D20')
            if ($shiftId === null) {
                $stmt = $db->prepare('
                    SELECT value FROM parameter_values 
                    WHERE parameter_id = 35 AND equipment_id = 2 AND date = ? AND cell = "D20"
                    LIMIT 1
                ');
                $stmt->execute([$date]);
            } else {
                $stmt = $db->prepare('
                    SELECT value FROM parameter_values 
                    WHERE parameter_id = 35 AND equipment_id = 2 AND date = ? AND shift_id = ? AND cell = "D20"
                ');
                $stmt->execute([$date, $shiftId]);
            }
            $result2 = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result2 && $result2['value']) {
                $steamGeneration += $result2['value'];
            }
            
            error_log("ОЧ-130: ТГ7=" . ($result1 ? $result1['value'] : '0') . ", ТГ8=" . ($result2 ? $result2['value'] : '0') . ", сумма=$steamGeneration");
            
        } else {
            // Для ТГ7 и ТГ8 используем обычную логику
            if ($shiftId === null) {
                // Для суточных расчетов ищем любую смену
                $stmt = $db->prepare('
                    SELECT value FROM parameter_values 
                    WHERE parameter_id = 35 AND equipment_id = ? AND date = ? AND cell = ?
                    LIMIT 1
                ');
                $stmt->execute([$equipmentId, $date, $cell]);
            } else {
                // Для сменных расчетов ищем конкретную смену
                $stmt = $db->prepare('
                    SELECT value FROM parameter_values 
                    WHERE parameter_id = 35 AND equipment_id = ? AND date = ? AND shift_id = ? AND cell = ?
                ');
                $stmt->execute([$equipmentId, $date, $shiftId, $cell]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            error_log("Запрос к parameter_values: equipment_id=$equipmentId, date=$date, shift_id=$shiftId, cell=$cell");
            error_log("Результат запроса: " . ($result ? json_encode($result) : 'null'));
            
            if (!$result || !$result['value']) {
                error_log("Не найдены данные для выработки пара котлами");
                return 0;
            }
            
            $steamGeneration = $result['value'];
        }
        
        // Применяем формулу: 21.125 + 0.8218 * C20/C11
        $reheatSteamFlow = 21.125 + 0.8218 * ($steamGeneration / $workingHours);
        
        error_log("Выработка тепла котлом для блока $blockId: часы=$workingHours, пар=$steamGeneration, результат=$reheatSteamFlow");
        
        return $reheatSteamFlow;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете выработки тепла котлом: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет средней тепловой нагрузки котлов (param_id = 279)
 * Формула: IF(часы_работы=0, 0, выработка_тепла_котлом / часы_работы)
 * 
 * Для ТГ7: E21 = IF(E18=0,0,E19/E18)
 * Для ТГ8: F21 = IF(F18=0,0,F19/F18)  
 * Для ОЧ-130: G21 = IF(G18=0,0,G19/G18)
 */
function calculateAvgThermalLoad($date, $shiftId, $blockId, $values) {
    try {
        // Получаем часы работы котлов (param_id = 277)
        $boilerWorkingHours = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 277 && $value['tg_id'] == $blockId) {
                $boilerWorkingHours = $value['value'];
                break;
            }
        }
        
        error_log("Часы работы котлов для блока $blockId: " . ($boilerWorkingHours !== null ? $boilerWorkingHours : 'null'));
        
        if ($boilerWorkingHours === null || $boilerWorkingHours == 0) {
            error_log("Часы работы котлов равны 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем выработку тепла котлом (param_id = 278)
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        error_log("Выработка тепла котлом для блока $blockId: " . ($boilerHeatGeneration !== null ? $boilerHeatGeneration : 'null'));
        
        if ($boilerHeatGeneration === null || $boilerHeatGeneration == 0) {
            error_log("Выработка тепла котлом равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Применяем формулу: выработка_тепла_котлом / часы_работы_котлов
        $avgThermalLoad = $boilerHeatGeneration / $boilerWorkingHours;
        
        error_log("Средняя тепловая нагрузка котлов для блока $blockId: выработка=$boilerHeatGeneration, часы=$boilerWorkingHours, результат=$avgThermalLoad");
        
        return $avgThermalLoad;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете средней тепловой нагрузки котлов: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет средней электрической нагрузки (param_id = 280)
 * Берет значение из категории 3a, param_id = 35 (E26/F26)
 * 
 * E22 = 'НоваяЭХ -3 стр.(а)'!E26
 * F22 = 'НоваяЭХ -3 стр.(а)'!F26
 */
function calculateAvgElectricLoadFrom3a($date, $shiftId, $blockId, $values) {
    try {
        // Ищем значение param_id = 35 (средняя электрическая нагрузка турбоагрегата) из категории 3a
        $avgElectricLoad = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 35 && $value['tg_id'] == $blockId) {
                $avgElectricLoad = $value['value'];
                break;
            }
        }
        
        error_log("Средняя электрическая нагрузка из 3a для блока $blockId: " . ($avgElectricLoad !== null ? $avgElectricLoad : 'null'));
        
        if ($avgElectricLoad === null) {
            error_log("Не найдено значение param_id = 35 для блока $blockId");
            return 0;
        }
        
        return $avgElectricLoad;
        
    } catch (Exception $e) {
        error_log('Ошибка при получении средней электрической нагрузки из 3a: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет среднего расхода питательной воды (param_id = 282)
 * Формула: IF(часы_работы=0,0,расход_питательной_воды/часы_работы)
 * 
 * E24 = IF(E18=0,0,E23/E18)
 * F24 = IF(F18=0,0,F23/F18)
 * G24 = IF(G18=0,0,G23/G18)
 */
function calculateAvgFeedwaterFlow($date, $shiftId, $blockId, $values) {
    try {
        // Получаем часы работы котлов (param_id = 277)
        $boilerWorkingHours = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 277 && $value['tg_id'] == $blockId) {
                $boilerWorkingHours = $value['value'];
                break;
            }
        }
        
        error_log("Часы работы котлов для расчета среднего расхода питательной воды блока $blockId: " . ($boilerWorkingHours !== null ? $boilerWorkingHours : 'null'));
        
        if ($boilerWorkingHours === null || $boilerWorkingHours == 0) {
            error_log("Часы работы котлов равны 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем расход питательной воды за месяц (param_id = 281)
        $feedwaterFlowMonthly = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 281 && $value['tg_id'] == $blockId) {
                $feedwaterFlowMonthly = $value['value'];
                break;
            }
        }
        
        error_log("Расход питательной воды за месяц для блока $blockId: " . ($feedwaterFlowMonthly !== null ? $feedwaterFlowMonthly : 'null') . " (тип: " . gettype($feedwaterFlowMonthly) . ")");
        
        // Более строгая проверка на ноль
        if ($feedwaterFlowMonthly === null || $feedwaterFlowMonthly == 0 || $feedwaterFlowMonthly === 0.0 || $feedwaterFlowMonthly === '0' || $feedwaterFlowMonthly === '0.0') {
            error_log("Расход питательной воды за месяц равен 0 или null, возвращаем 0");
            return 0;
        }
        
        // Применяем формулу: расход_питательной_воды_за_месяц / часы_работы_котлов
        $avgFeedwaterFlow = $feedwaterFlowMonthly / $boilerWorkingHours;
        
        error_log("Средний расход питательной воды для блока $blockId: расход=$feedwaterFlowMonthly, часы=$boilerWorkingHours, результат=$avgFeedwaterFlow");
        
        return $avgFeedwaterFlow;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете среднего расхода питательной воды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет температуры холодного воздуха на стороне всасывания дутьевого вентилятора (param_id = 284)
 * Берет значение из исходных данных
 * 
 * E26 = 'Исх. данные оч.130'!C27
 * F26 = 'Исх. данные оч.130'!D27
 * G26 - нет (только для ТГ7 и ТГ8)
 */
function calculateColdAirTemperature($date, $shiftId, $blockId) {
    try {
        $db = getDbConnection();
        
        // Определяем cell в зависимости от блока
        $cell = '';
        $equipmentId = 0;
        if ($blockId == 7) {
            $cell = 'C27';
            $equipmentId = 1;
        } elseif ($blockId == 8) {
            $cell = 'D27';
            $equipmentId = 2;
        } else {
            // Для ОЧ-130 или других блоков возвращаем 0 (нет G26)
            error_log("Температура холодного воздуха: неподдерживаемый блок $blockId (нет G26)");
            return 0;
        }
        
        error_log("Температура холодного воздуха: блок=$blockId, equipment_id=$equipmentId, cell=$cell, date=$date, shift_id=$shiftId");
        
        // Ищем значение в исходных данных (parameter_values)
        // Нужно найти правильный parameter_id для температуры холодного воздуха
        // Предполагаем, что это parameter_id = 45 (температура холодного воздуха)
        if ($shiftId === null) {
            // Для суточных расчетов ищем любую смену
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 42 AND equipment_id = ? AND date = ? AND cell = ?
                LIMIT 1
            ');
            $stmt->execute([$equipmentId, $date, $cell]);
        } else {
            // Для сменных расчетов ищем конкретную смену
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 42 AND equipment_id = ? AND date = ? AND shift_id = ? AND cell = ?
            ');
            $stmt->execute([$equipmentId, $date, $shiftId, $cell]);
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Запрос к parameter_values: parameter_id=45, equipment_id=$equipmentId, date=$date, shift_id=$shiftId, cell=$cell");
        error_log("Результат запроса: " . ($result ? json_encode($result) : 'null'));
        
        if (!$result || !$result['value']) {
            error_log("Не найдены данные для температуры холодного воздуха");
            return 0;
        }
        
        $coldAirTemp = (float)$result['value'];
        
        error_log("Температура холодного воздуха для блока $blockId: $coldAirTemp");
        
        return $coldAirTemp;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете температуры холодного воздуха: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет температуры питательной воды (param_id = 285)
 * Берет значение из исходных данных
 * 
 * E27 = 'Исх. данные оч.130'!C25
 * F27 = 'Исх. данные оч.130'!D25
 */
function calculateFeedwaterTemperature($date, $shiftId, $blockId) {
    try {
        $db = getDbConnection();
        
        // Определяем cell в зависимости от блока
        $cell = '';
        $equipmentId = 0;
        if ($blockId == 7) {
            $cell = 'C25';
            $equipmentId = 1;
        } elseif ($blockId == 8) {
            $cell = 'D25';
            $equipmentId = 2;
        } else {
            // Для ОЧ-130 или других блоков возвращаем 0 (нет G27)
            error_log("Температура питательной воды: неподдерживаемый блок $blockId (нет G27)");
            return 0;
        }
        
        error_log("Температура питательной воды: блок=$blockId, equipment_id=$equipmentId, cell=$cell, date=$date, shift_id=$shiftId");
        
        // Ищем значение в исходных данных (parameter_values)
        // Предполагаем, что это parameter_id = 43 (температура питательной воды)
        if ($shiftId === null) {
            // Для суточных расчетов ищем любую смену
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 40 AND equipment_id = ? AND date = ? AND cell = ?
                LIMIT 1
            ');
            $stmt->execute([$equipmentId, $date, $cell]);
        } else {
            // Для сменных расчетов ищем конкретную смену
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 40 AND equipment_id = ? AND date = ? AND shift_id = ? AND cell = ?
            ');
            $stmt->execute([$equipmentId, $date, $shiftId, $cell]);
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Запрос к parameter_values: parameter_id=43, equipment_id=$equipmentId, date=$date, shift_id=$shiftId, cell=$cell");
        error_log("Результат запроса: " . ($result ? json_encode($result) : 'null'));
        
        if (!$result || !$result['value']) {
            error_log("Не найдены данные для температуры питательной воды");
            return 0;
        }
        
        $feedwaterTemp = (float)$result['value'];
        
        error_log("Температура питательной воды для блока $blockId: $feedwaterTemp");
        
        return $feedwaterTemp;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете температуры питательной воды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет продолжительности работы котла от даты составления энергетических характеристик (param_id = 286)
 * Берет значение из исходных данных
 * 
 * E28 = 'Исх. данные оч.130'!C12
 * F28 = 'Исх. данные оч.130'!D12
 */
function calculateBoilerOperationDuration($date, $shiftId, $blockId) {
    try {
        $db = getDbConnection();
        
        // Определяем cell в зависимости от блока
        $cell = '';
        $equipmentId = 0;
        if ($blockId == 7) {
            $cell = 'C12';
            $equipmentId = 1;
        } elseif ($blockId == 8) {
            $cell = 'D12';
            $equipmentId = 2;
        } else {
            // Для ОЧ-130 или других блоков возвращаем 0 (нет G28)
            error_log("Продолжительность работы котла: неподдерживаемый блок $blockId (нет G28)");
            return 0;
        }
        
        error_log("Продолжительность работы котла: блок=$blockId, equipment_id=$equipmentId, cell=$cell, date=$date, shift_id=$shiftId");
        
        // Ищем значение в исходных данных (parameter_values)
        // Предполагаем, что это parameter_id = 44 (продолжительность работы котла)
        if ($shiftId === null) {
            // Для суточных расчетов ищем любую смену
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 49 AND equipment_id = ? AND date = ? AND cell = ?
                LIMIT 1
            ');
            $stmt->execute([$equipmentId, $date, $cell]);
        } else {
            // Для сменных расчетов ищем конкретную смену
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 49 AND equipment_id = ? AND date = ? AND shift_id = ? AND cell = ?
            ');
            $stmt->execute([$equipmentId, $date, $shiftId, $cell]);
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Запрос к parameter_values: parameter_id=44, equipment_id=$equipmentId, date=$date, shift_id=$shiftId, cell=$cell");
        error_log("Результат запроса: " . ($result ? json_encode($result) : 'null'));
        
        if (!$result || !$result['value']) {
            error_log("Не найдены данные для продолжительности работы котла");
            return 0;
        }
        
        $operationDuration = (float)$result['value'];
        
        error_log("Продолжительность работы котла для блока $blockId: $operationDuration");
        
        return $operationDuration;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете продолжительности работы котла: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет КПД брутто котла (param_id = 288)
 * Сложная формула, зависящая от средней электрической нагрузки и коэффициента использования топлива
 * 
 * E32 = (-0.000022*E26^2 -0.001195*E26+94.199705)*E17+(0.0000007164*E26^3 - 0.0003935316*E26^2 + 0.0601780611*E26 + 89.291015565)*(1-E17)
 * F32 = (-0.000022*F26^2 -0.001195*F26+94.199705)*F17+(0.0000007164*F26^3 - 0.0003935316*F26^2 + 0.0601780611*F26 + 89.291015565)*(1-F17)
 * G32 = (E32+F32)/2
 */
function calculateBoilerEfficiency($date, $shiftId, $blockId, $values) {
    try {
        // Для ОЧ-130 (blockId = 9) G32 = (E32+F32)/2
        if ($blockId == 9) {
            // Получаем E32 (ТГ7) и F32 (ТГ8)
            $tg7Efficiency = calculateBoilerEfficiencyForBlock($date, $shiftId, 7, $values);
            $tg8Efficiency = calculateBoilerEfficiencyForBlock($date, $shiftId, 8, $values);
            
            $avgEfficiency = ($tg7Efficiency + $tg8Efficiency) / 2;
            
            error_log("КПД брутто котла для ОЧ-130: ТГ7=$tg7Efficiency, ТГ8=$tg8Efficiency, среднее=$avgEfficiency");
            
            return $avgEfficiency;
        }
        
        // Для ТГ7 и ТГ8 используем обычную формулу
        return calculateBoilerEfficiencyForBlock($date, $shiftId, $blockId, $values);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете КПД брутто котла: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет КПД брутто котла для конкретного блока (ТГ7 или ТГ8)
 */
function calculateBoilerEfficiencyForBlock($date, $shiftId, $blockId, $values) {
    try {
        // Получаем среднюю электрическую нагрузку (E26/F26) - param_id = 35
        $avgElectricLoad = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 35 && $value['tg_id'] == $blockId) {
                $avgElectricLoad = $value['value'];
                break;
            }
        }
        
        error_log("КПД брутто котла: средняя электрическая нагрузка для блока $blockId: " . ($avgElectricLoad !== null ? $avgElectricLoad : 'null'));
        
        if ($avgElectricLoad === null) {
            error_log("Не найдено значение средней электрической нагрузки для блока $blockId");
            return 0;
        }
        
        // Получаем коэффициент использования топлива (E17/F17) - param_id = 277
        $fuelUtilizationFactor = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 277 && $value['tg_id'] == $blockId) {
                $fuelUtilizationFactor = $value['value'];
                break;
            }
        }
        
        error_log("КПД брутто котла: коэффициент использования топлива для блока $blockId: " . ($fuelUtilizationFactor !== null ? $fuelUtilizationFactor : 'null'));
        
        if ($fuelUtilizationFactor === null) {
            error_log("Не найден коэффициент использования топлива для блока $blockId");
            return 0;
        }
        
        // Применяем формулу
        $x = (float)$avgElectricLoad;
        $k = (float)$fuelUtilizationFactor;
        
        // Первая часть формулы: (-0.000022*x^2 -0.001195*x+94.199705)*k
        $part1 = (-0.000022 * $x * $x - 0.001195 * $x + 94.199705) * $k;
        
        // Вторая часть формулы: (0.0000007164*x^3 - 0.0003935316*x^2 + 0.0601780611*x + 89.291015565)*(1-k)
        $part2 = (0.0000007164 * $x * $x * $x - 0.0003935316 * $x * $x + 0.0601780611 * $x + 89.291015565) * (1 - $k);
        
        $efficiency = $part1 + $part2;
        
        error_log("КПД брутто котла для блока $blockId: x=$x, k=$k, part1=$part1, part2=$part2, результат=$efficiency");
        
        return $efficiency;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете КПД брутто котла для блока: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки на температуру питательной воды (param_id = 289)
 * 
 * E34 = IF(E19=0,0,(E26-30)*0.044)
 * F34 = IF(F19=0,0,(F26-30)*0.044)
 * G34 = IF(G19=0,0,(E34*E19+F34*F19)/G19)
 */
function calculateFeedwaterTempCorrection($date, $shiftId, $blockId, $values) {
    try {
        // Получаем выработку тепла котлом (E19/F19) - param_id = 278
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        error_log("Поправка на температуру питательной воды: выработка тепла котлом для блока $blockId: " . ($boilerHeatGeneration !== null ? $boilerHeatGeneration : 'null'));
        
        if ($boilerHeatGeneration === null || $boilerHeatGeneration == 0) {
            error_log("Выработка тепла котлом равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем среднюю электрическую нагрузку (E26/F26) - param_id = 35
        $avgElectricLoad = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 35 && $value['tg_id'] == $blockId) {
                $avgElectricLoad = $value['value'];
                break;
            }
        }
        
        error_log("Поправка на температуру питательной воды: средняя электрическая нагрузка для блока $blockId: " . ($avgElectricLoad !== null ? $avgElectricLoad : 'null'));
        
        if ($avgElectricLoad === null) {
            error_log("Не найдено значение средней электрической нагрузки для блока $blockId");
            return 0;
        }
        
        // Для ОЧ-130 (G34) используем формулу: (E34*E19+F34*F19)/G19
        if ($blockId == 9) {
            // Получаем E34 и F34 (ТГ7 и ТГ8)
            $tg7Correction = calculateFeedwaterTempCorrectionForBlock($date, $shiftId, 7, $values);
            $tg8Correction = calculateFeedwaterTempCorrectionForBlock($date, $shiftId, 8, $values);
            
            // Получаем E19 и F19 (выработка тепла котлом для ТГ7 и ТГ8)
            $tg7HeatGeneration = null;
            $tg8HeatGeneration = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 278 && $value['tg_id'] == 7) {
                    $tg7HeatGeneration = $value['value'];
                } elseif ($value['param_id'] == 278 && $value['tg_id'] == 8) {
                    $tg8HeatGeneration = $value['value'];
                }
            }
            
            if ($tg7HeatGeneration === null || $tg8HeatGeneration === null) {
                error_log("Не найдены значения выработки тепла котлом для ТГ7 или ТГ8");
                return 0;
            }
            
            $g19 = $tg7HeatGeneration + $tg8HeatGeneration; // G19 = E19 + F19
            
            if ($g19 == 0) {
                error_log("G19 равно 0, возвращаем 0");
                return 0;
            }
            
            $correction = ($tg7Correction * $tg7HeatGeneration + $tg8Correction * $tg8HeatGeneration) / $g19;
            
            error_log("Поправка на температуру питательной воды для ОЧ-130: E34=$tg7Correction, F34=$tg8Correction, E19=$tg7HeatGeneration, F19=$tg8HeatGeneration, G19=$g19, результат=$correction");
            
            return $correction;
        }
        
        // Для ТГ7 и ТГ8 используем формулу: (E26-30)*0.044
        return calculateFeedwaterTempCorrectionForBlock($date, $shiftId, $blockId, $values);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на температуру питательной воды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки на температуру питательной воды для конкретного блока (ТГ7 или ТГ8)
 */
function calculateFeedwaterTempCorrectionForBlock($date, $shiftId, $blockId, $values) {
    try {
        // Получаем среднюю электрическую нагрузку (E26/F26) - param_id = 35
        $avgElectricLoad = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 284 && $value['tg_id'] == $blockId) {
                $avgElectricLoad = $value['value'];
                break;
            }
        }
        
        if ($avgElectricLoad === null) {
            error_log("Не найдено значение средней электрической нагрузки для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: (E26-30)*0.044
        $correction = ($avgElectricLoad - 30) * 0.044;
        
        error_log("Поправка на температуру питательной воды для блока $blockId: E26=$avgElectricLoad, результат=$correction");
        
        return $correction;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на температуру питательной воды для блока: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки на продолжительность работы котла (param_id = 290)
 * 
 * E35 = IF(E19=0,0,(-0.0015*E28/1000))
 * F35 = IF(F19=0,0,(-0.0015*F28/1000))
 */
function calculateOperationDurationCorrection($date, $shiftId, $blockId, $values) {
    try {
        // Получаем выработку тепла котлом (E19/F19) - param_id = 278
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        error_log("Поправка на продолжительность работы котла: выработка тепла котлом для блока $blockId: " . ($boilerHeatGeneration !== null ? $boilerHeatGeneration : 'null'));
        
        if ($boilerHeatGeneration === null || $boilerHeatGeneration == 0) {
            error_log("Выработка тепла котлом равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем продолжительность работы котла (E28/F28) - param_id = 286
        $operationDuration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 286 && $value['tg_id'] == $blockId) {
                $operationDuration = $value['value'];
                break;
            }
        }
        
        error_log("Поправка на продолжительность работы котла: продолжительность работы для блока $blockId: " . ($operationDuration !== null ? $operationDuration : 'null'));
        
        if ($operationDuration === null) {
            error_log("Не найдено значение продолжительности работы котла для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: (-0.0015*E28/1000)
        $correction = -0.0015 * $operationDuration / 1000;
        
        error_log("Поправка на продолжительность работы котла для блока $blockId: E28=$operationDuration, результат=$correction");
        
        return $correction;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на продолжительность работы котла: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки на температуру холодного воздуха (param_id = 291)
 * 
 * E36 = IF(E26<0,E21*0.008,E21*0.006)
 * F36 = IF(F26<0,F21*0.008,F21*0.006)
 */
function calculateColdAirTempCorrection($date, $shiftId, $blockId, $values) {
    try {
        // Получаем среднюю электрическую нагрузку (E26/F26) - param_id = 35
        $avgElectricLoad = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 284 && $value['tg_id'] == $blockId) {
                $avgElectricLoad = $value['value'];
                break;
            }
        }
        
        error_log("Поправка на температуру холодного воздуха: средняя электрическая нагрузка для блока $blockId: " . ($avgElectricLoad !== null ? $avgElectricLoad : 'null'));
        
        if ($avgElectricLoad === null) {
            error_log("Не найдено значение средней электрической нагрузки для блока $blockId");
            return 0;
        }
        
        // Получаем среднюю тепловую нагрузку котлов (E21/F21) - param_id = 279
        $avgThermalLoad = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 279 && $value['tg_id'] == $blockId) {
                $avgThermalLoad = $value['value'];
                break;
            }
        }
        
        error_log("Поправка на температуру холодного воздуха: средняя тепловая нагрузка для блока $blockId: " . ($avgThermalLoad !== null ? $avgThermalLoad : 'null'));
        
        if ($avgThermalLoad === null) {
            error_log("Не найдено значение средней тепловой нагрузки для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: IF(E26<0,E21*0.008,E21*0.006)
        $coefficient = ($avgElectricLoad < 0) ? 0.008 : 0.006;
        $correction = $avgThermalLoad * $coefficient;
        
        error_log("Поправка на температуру холодного воздуха для блока $blockId: E26=$avgElectricLoad, E21=$avgThermalLoad, коэффициент=$coefficient, результат=$correction");
        
        return $correction;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на температуру холодного воздуха: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки на температуру холодного воздуха в процентах (param_id = 292)
 * 
 * E37 = E36/E21*100
 * F37 = F36/F21*100
 */
function calculateColdAirTempCorrectionPercent($date, $shiftId, $blockId, $values) {
    try {
        // Получаем поправку на температуру холодного воздуха (E36/F36) - param_id = 291
        $coldAirTempCorrection = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 291 && $value['tg_id'] == $blockId) {
                $coldAirTempCorrection = $value['value'];
                break;
            }
        }
        
        error_log("Поправка на температуру холодного воздуха в процентах: поправка для блока $blockId: " . ($coldAirTempCorrection !== null ? $coldAirTempCorrection : 'null'));
        
        if ($coldAirTempCorrection === null) {
            error_log("Не найдено значение поправки на температуру холодного воздуха для блока $blockId");
            return 0;
        }
        
        // Получаем среднюю тепловую нагрузку котлов (E21/F21) - param_id = 279
        $avgThermalLoad = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 279 && $value['tg_id'] == $blockId) {
                $avgThermalLoad = $value['value'];
                break;
            }
        }
        
        error_log("Поправка на температуру холодного воздуха в процентах: средняя тепловая нагрузка для блока $blockId: " . ($avgThermalLoad !== null ? $avgThermalLoad : 'null'));
        
        if ($avgThermalLoad === null || $avgThermalLoad == 0) {
            error_log("Средняя тепловая нагрузка равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Применяем формулу: E36/E21*100
        $correctionPercent = ($coldAirTempCorrection / $avgThermalLoad) * 100;
        
        error_log("Поправка на температуру холодного воздуха в процентах для блока $blockId: E36=$coldAirTempCorrection, E21=$avgThermalLoad, результат=$correctionPercent");
        
        return $correctionPercent;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на температуру холодного воздуха в процентах: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет КПД брутто котла с поправками (param_id = 293)
 * 
 * E38 = IF(E19=0,0,(E32+E34+E35-E37))
 * F38 = IF(F19=0,0,(F32+F34+F35-F37))
 */
function calculateBoilerEfficiencyWithCorrections($date, $shiftId, $blockId, $values) {
    try {
        // Получаем выработку тепла котлом (E19/F19) - param_id = 278
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        error_log("КПД брутто котла с поправками: выработка тепла котлом для блока $blockId: " . ($boilerHeatGeneration !== null ? $boilerHeatGeneration : 'null'));
        
        if ($boilerHeatGeneration === null || $boilerHeatGeneration == 0) {
            error_log("Выработка тепла котлом равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем КПД брутто котла (E32/F32) - param_id = 288
        $boilerEfficiency = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 288 && $value['tg_id'] == $blockId) {
                $boilerEfficiency = $value['value'];
                break;
            }
        }
        
        if ($boilerEfficiency === null) {
            error_log("Не найдено значение КПД брутто котла для блока $blockId");
            return 0;
        }
        
        // Получаем поправку на температуру питательной воды (E34/F34) - param_id = 289
        $feedwaterTempCorrection = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 289 && $value['tg_id'] == $blockId) {
                $feedwaterTempCorrection = $value['value'];
                break;
            }
        }
        
        if ($feedwaterTempCorrection === null) {
            error_log("Не найдено значение поправки на температуру питательной воды для блока $blockId");
            return 0;
        }
        
        // Получаем поправку на продолжительность работы котла (E35/F35) - param_id = 290
        $operationDurationCorrection = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 290 && $value['tg_id'] == $blockId) {
                $operationDurationCorrection = $value['value'];
                break;
            }
        }
        
        if ($operationDurationCorrection === null) {
            error_log("Не найдено значение поправки на продолжительность работы котла для блока $blockId");
            return 0;
        }
        
        // Получаем поправку на температуру холодного воздуха в процентах (E37/F37) - param_id = 292
        $coldAirTempCorrectionPercent = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 292 && $value['tg_id'] == $blockId) {
                $coldAirTempCorrectionPercent = $value['value'];
                break;
            }
        }
        
        if ($coldAirTempCorrectionPercent === null) {
            error_log("Не найдено значение поправки на температуру холодного воздуха в процентах для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: E32+E34+E35-E37
        $efficiencyWithCorrections = $boilerEfficiency + $feedwaterTempCorrection + $operationDurationCorrection - $coldAirTempCorrectionPercent;
        
        error_log("КПД брутто котла с поправками для блока $blockId: E32=$boilerEfficiency, E34=$feedwaterTempCorrection, E35=$operationDurationCorrection, E37=$coldAirTempCorrectionPercent, результат=$efficiencyWithCorrections");
        
        return $efficiencyWithCorrections;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете КПД брутто котла с поправками: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет КПД нетто котла (param_id = 294)
 * Сложная формула с условной логикой и полиномиальными коэффициентами
 * 
 * E39 = IF(E19=0,0,IF(E24<540,17.0477-0.05398*E24+0.000056*E24*E24,40.8655-0.120431*E24+0.000103*E24*E24))*E17+(0.000044*E24^2 - 0.047029*E24 + 18.8321)*(1-E17)
 * F39 = IF(F19=0,0,IF(F24<540,17.0477-0.05398*F24+0.000056*F24*F24,40.8655-0.120431*F24+0.000103*F24*F24))*F17+(0.000044*F24^2 - 0.047029*F24 + 18.8321)*(1-F17)
 */
function calculateBoilerNetEfficiency($date, $shiftId, $blockId, $values) {
    try {
        // Получаем выработку тепла котлом (E19/F19) - param_id = 278
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        error_log("КПД нетто котла: выработка тепла котлом для блока $blockId: " . ($boilerHeatGeneration !== null ? $boilerHeatGeneration : 'null'));
        
        if ($boilerHeatGeneration === null || $boilerHeatGeneration == 0) {
            error_log("Выработка тепла котлом равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем средний расход питательной воды (E24/F24) - param_id = 282
        $avgFeedwaterFlow = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 282 && $value['tg_id'] == $blockId) {
                $avgFeedwaterFlow = $value['value'];
                break;
            }
        }
        
        error_log("КПД нетто котла: средний расход питательной воды для блока $blockId: " . ($avgFeedwaterFlow !== null ? $avgFeedwaterFlow : 'null'));
        
        if ($avgFeedwaterFlow === null) {
            error_log("Не найдено значение среднего расхода питательной воды для блока $blockId");
            return 0;
        }
        
        // Получаем коэффициент использования топлива (E17/F17) - param_id = 277
        $fuelUtilizationFactor = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 277 && $value['tg_id'] == $blockId) {
                $fuelUtilizationFactor = $value['value'];
                break;
            }
        }
        
        error_log("КПД нетто котла: коэффициент использования топлива для блока $blockId: " . ($fuelUtilizationFactor !== null ? $fuelUtilizationFactor : 'null'));
        
        if ($fuelUtilizationFactor === null) {
            error_log("Не найден коэффициент использования топлива для блока $blockId");
            return 0;
        }
        
        // Применяем сложную формулу
        $x = (float)$avgFeedwaterFlow;
        $k = (float)$fuelUtilizationFactor;
        
        // Первая часть: IF(E24<540,17.0477-0.05398*E24+0.000056*E24*E24,40.8655-0.120431*E24+0.000103*E24*E24)
        if ($x < 540) {
            $part1Coeff = 17.0477 - 0.05398 * $x + 0.000056 * $x * $x;
        } else {
            $part1Coeff = 40.8655 - 0.120431 * $x + 0.000103 * $x * $x;
        }
        
        // Вторая часть: (0.000044*E24^2 - 0.047029*E24 + 18.8321)
        $part2Coeff = 0.000044 * $x * $x - 0.047029 * $x + 18.8321;
        
        // Итоговая формула: part1Coeff * E17 + part2Coeff * (1-E17)
        $netEfficiency = $part1Coeff * $k + $part2Coeff * (1 - $k);
        
        error_log("КПД нетто котла для блока $blockId: x=$x, k=$k, part1Coeff=$part1Coeff, part2Coeff=$part2Coeff, результат=$netEfficiency");
        
        return $netEfficiency;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете КПД нетто котла: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет удельного расхода топлива (param_id = 295)
 * 
 * E40 = IF(E19=0,0,25.3-0.151161*'НоваяЭХ -3 стр.(а)'!E26+0.000295*'НоваяЭХ -3 стр.(а)'!E26*'НоваяЭХ -3 стр.(а)'!E26)
 * F40 = IF(F19=0,0,25.3-0.151161*'НоваяЭХ -3 стр.(а)'!F26+0.000295*'НоваяЭХ -3 стр.(а)'!F26*'НоваяЭХ -3 стр.(а)'!F26)
 * 
 * 'НоваяЭХ -3 стр.(а)'!E26/F26 означает param_id = 280 (E22/F22 - средняя электрическая нагрузка из категории 3b)
 */
function calculateSpecificFuelConsumption($date, $shiftId, $blockId, $values) {
    try {
        // Получаем выработку тепла котлом (E19/F19) - param_id = 278
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        error_log("Удельный расход топлива: выработка тепла котлом для блока $blockId: " . ($boilerHeatGeneration !== null ? $boilerHeatGeneration : 'null'));
        
        if ($boilerHeatGeneration === null || $boilerHeatGeneration == 0) {
            error_log("Выработка тепла котлом равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем среднюю электрическую нагрузку из категории 3b (E22/F22) - param_id = 280
        $avgElectricLoad3b = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 280 && $value['tg_id'] == $blockId) {
                $avgElectricLoad3b = $value['value'];
                break;
            }
        }
        
        error_log("Удельный расход топлива: средняя электрическая нагрузка из 3b для блока $blockId: " . ($avgElectricLoad3b !== null ? $avgElectricLoad3b : 'null'));
        
        if ($avgElectricLoad3b === null) {
            error_log("Не найдено значение средней электрической нагрузки из 3b для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: 25.3-0.151161*E22+0.000295*E22*E22
        $x = (float)$avgElectricLoad3b;
        $specificFuelConsumption = 25.3 - 0.151161 * $x + 0.000295 * $x * $x;
        
        error_log("Удельный расход топлива для блока $blockId: E22(3b)=$x, результат=$specificFuelConsumption");
        
        return $specificFuelConsumption;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете удельного расхода топлива: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки на КПД (param_id = 296)
 * 
 * E41 = IF(E18=0,0,1.2)
 * F41 = IF(F18=0,0,1.2)
 */
function calculateEfficiencyCorrection($date, $shiftId, $blockId, $values) {
    try {
        // Получаем часы работы котлов (E18/F18) - param_id = 277
        $boilerWorkingHours = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 277 && $value['tg_id'] == $blockId) {
                $boilerWorkingHours = $value['value'];
                break;
            }
        }
        
        error_log("Поправка на КПД: часы работы котлов для блока $blockId: " . ($boilerWorkingHours !== null ? $boilerWorkingHours : 'null'));
        
        if ($boilerWorkingHours === null || $boilerWorkingHours == 0) {
            error_log("Часы работы котлов равны 0 или null, возвращаем 0");
            return 0;
        }
        
        // Если часы работы > 0, то поправка = 1.2
        $correction = 1.2;
        
        error_log("Поправка на КПД для блока $blockId: E18=$boilerWorkingHours, результат=$correction");
        
        return $correction;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на КПД: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет расхода топлива (param_id = 297)
 * 
 * E43 = (((E39+E41)*E19+E40*E23)*1.043)/1000
 * F43 = (((F39+F41)*F19+F40*F23)*1.043)/1000
 */
function calculateFuelConsumption($date, $shiftId, $blockId, $values) {
    try {
        // Получаем КПД нетто котла (E39/F39) - param_id = 294
        $boilerNetEfficiency = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 294 && $value['tg_id'] == $blockId) {
                $boilerNetEfficiency = $value['value'];
                break;
            }
        }
        
        if ($boilerNetEfficiency === null) {
            error_log("Не найдено значение КПД нетто котла для блока $blockId");
            return 0;
        }
        
        // Получаем поправку на КПД (E41/F41) - param_id = 296
        $efficiencyCorrection = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 296 && $value['tg_id'] == $blockId) {
                $efficiencyCorrection = $value['value'];
                break;
            }
        }
        
        if ($efficiencyCorrection === null) {
            error_log("Не найдено значение поправки на КПД для блока $blockId");
            return 0;
        }
        
        // Получаем выработку тепла котлом (E19/F19) - param_id = 278
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        if ($boilerHeatGeneration === null) {
            error_log("Не найдено значение выработки тепла котлом для блока $blockId");
            return 0;
        }
        
        // Получаем удельный расход топлива (E40/F40) - param_id = 295
        $specificFuelConsumption = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 295 && $value['tg_id'] == $blockId) {
                $specificFuelConsumption = $value['value'];
                break;
            }
        }
        
        if ($specificFuelConsumption === null) {
            error_log("Не найдено значение удельного расхода топлива для блока $blockId");
            return 0;
        }
        
        // Получаем расход питательной воды (E23/F23) - param_id = 281
        $feedwaterFlow = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 281 && $value['tg_id'] == $blockId) {
                $feedwaterFlow = $value['value'];
                break;
            }
        }
        
        if ($feedwaterFlow === null) {
            error_log("Не найдено значение расхода питательной воды для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: (((E39+E41)*E19+E40*E23)*1.043)/1000
        $fuelConsumption = ((($boilerNetEfficiency + $efficiencyCorrection) * $boilerHeatGeneration + $specificFuelConsumption * $feedwaterFlow) * 1.043) / 1000;
        
        error_log("Расход топлива для блока $blockId: E39=$boilerNetEfficiency, E41=$efficiencyCorrection, E19=$boilerHeatGeneration, E40=$specificFuelConsumption, E23=$feedwaterFlow, результат=$fuelConsumption");
        
        return $fuelConsumption;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете расхода топлива: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет удельного расхода топлива на выработку тепла (param_id = 298)
 * 
 * E44 = IF(E19=0,0,1000*E43/E19)
 * F44 = IF(F19=0,0,1000*F43/F19)
 */
function calculateSpecificFuelConsumptionForHeat($date, $shiftId, $blockId, $values) {
    try {
        // Получаем выработку тепла котлом (E19/F19) - param_id = 278
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        error_log("Удельный расход топлива на выработку тепла: выработка тепла котлом для блока $blockId: " . ($boilerHeatGeneration !== null ? $boilerHeatGeneration : 'null'));
        
        if ($boilerHeatGeneration === null || $boilerHeatGeneration == 0) {
            error_log("Выработка тепла котлом равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем расход топлива (E43/F43) - param_id = 297
        $fuelConsumption = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 297 && $value['tg_id'] == $blockId) {
                $fuelConsumption = $value['value'];
                break;
            }
        }
        
        if ($fuelConsumption === null) {
            error_log("Не найдено значение расхода топлива для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: 1000*E43/E19
        $specificFuelConsumptionForHeat = 1000 * $fuelConsumption / $boilerHeatGeneration;
        
        error_log("Удельный расход топлива на выработку тепла для блока $blockId: E43=$fuelConsumption, E19=$boilerHeatGeneration, результат=$specificFuelConsumptionForHeat");
        
        return $specificFuelConsumptionForHeat;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете удельного расхода топлива на выработку тепла: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет удельного расхода топлива на выработку электроэнергии (param_id = 299)
 * 
 * E45 = IF(E19=0,0,(100*E43/'НоваяЭХ -3 стр.(а)'!E13))
 * F45 = IF(F19=0,0,(100*F43/'НоваяЭХ -3 стр.(а)'!F13))
 * 
 * 'НоваяЭХ -3 стр.(а)'!E13/F13 означает param_id = 35 (категория 3a)
 */
function calculateSpecificFuelConsumptionForElectricity($date, $shiftId, $blockId, $values) {
    try {
        // Получаем выработку тепла котлом (E19/F19) - param_id = 278
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        error_log("Удельный расход топлива на выработку электроэнергии: выработка тепла котлом для блока $blockId: " . ($boilerHeatGeneration !== null ? $boilerHeatGeneration : 'null'));
        
        if ($boilerHeatGeneration === null || $boilerHeatGeneration == 0) {
            error_log("Выработка тепла котлом равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем расход топлива (E43/F43) - param_id = 297
        $fuelConsumption = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 297 && $value['tg_id'] == $blockId) {
                $fuelConsumption = $value['value'];
                break;
            }
        }
        
        if ($fuelConsumption === null) {
            error_log("Не найдено значение расхода топлива для блока $blockId");
            return 0;
        }
        
        // Получаем среднюю электрическую нагрузку из категории 3a (E13/F13) - param_id = 35
        $avgElectricLoad3a = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 35 && $value['tg_id'] == $blockId) {
                $avgElectricLoad3a = $value['value'];
                break;
            }
        }
        
        error_log("Удельный расход топлива на выработку электроэнергии: средняя электрическая нагрузка из 3a для блока $blockId: " . ($avgElectricLoad3a !== null ? $avgElectricLoad3a : 'null'));
        
        if ($avgElectricLoad3a === null || $avgElectricLoad3a == 0) {
            error_log("Средняя электрическая нагрузка из 3a равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Применяем формулу: 100*E43/E13
        $specificFuelConsumptionForElectricity = 100 * $fuelConsumption / $avgElectricLoad3a;
        
        error_log("Удельный расход топлива на выработку электроэнергии для блока $blockId: E43=$fuelConsumption, E13(3a)=$avgElectricLoad3a, результат=$specificFuelConsumptionForElectricity");
        
        return $specificFuelConsumptionForElectricity;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете удельного расхода топлива на выработку электроэнергии: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет расхода топлива на собственные нужды (param_id = 300)
 * 
 * E47 = 0.005*E19
 * F47 = 0.005*F19
 */
function calculateFuelConsumptionForOwnNeeds($date, $shiftId, $blockId, $values) {
    try {
        // Получаем выработку тепла котлом (E19/F19) - param_id = 278
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        error_log("Расход топлива на собственные нужды: выработка тепла котлом для блока $blockId: " . ($boilerHeatGeneration !== null ? $boilerHeatGeneration : 'null'));
        
        if ($boilerHeatGeneration === null) {
            error_log("Не найдено значение выработки тепла котлом для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: 0.005*E19
        $fuelConsumptionForOwnNeeds = 0.005 * $boilerHeatGeneration;
        
        error_log("Расход топлива на собственные нужды для блока $blockId: E19=$boilerHeatGeneration, результат=$fuelConsumptionForOwnNeeds");
        
        return $fuelConsumptionForOwnNeeds;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете расхода топлива на собственные нужды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет удельного расхода топлива на собственные нужды (param_id = 301)
 * 
 * E49 = IF(E19=0,0,100*E47/E19)
 * F49 = IF(F19=0,0,100*F47/F19)
 */
function calculateSpecificFuelConsumptionForOwnNeeds($date, $shiftId, $blockId, $values) {
    try {
        // Получаем выработку тепла котлом (E19/F19) - param_id = 278
        $boilerHeatGeneration = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 278 && $value['tg_id'] == $blockId) {
                $boilerHeatGeneration = $value['value'];
                break;
            }
        }
        
        error_log("Удельный расход топлива на собственные нужды: выработка тепла котлом для блока $blockId: " . ($boilerHeatGeneration !== null ? $boilerHeatGeneration : 'null'));
        
        if ($boilerHeatGeneration === null || $boilerHeatGeneration == 0) {
            error_log("Выработка тепла котлом равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем расход топлива на собственные нужды (E47/F47) - param_id = 300
        $fuelConsumptionForOwnNeeds = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 300 && $value['tg_id'] == $blockId) {
                $fuelConsumptionForOwnNeeds = $value['value'];
                break;
            }
        }
        
        if ($fuelConsumptionForOwnNeeds === null) {
            error_log("Не найдено значение расхода топлива на собственные нужды для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: 100*E47/E19
        $specificFuelConsumptionForOwnNeeds = 100 * $fuelConsumptionForOwnNeeds / $boilerHeatGeneration;
        
        error_log("Удельный расход топлива на собственные нужды для блока $blockId: E47=$fuelConsumptionForOwnNeeds, E19=$boilerHeatGeneration, результат=$specificFuelConsumptionForOwnNeeds");
        
        return $specificFuelConsumptionForOwnNeeds;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете удельного расхода топлива на собственные нужды: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет КПД нетто котла с учетом собственных нужд (param_id = 302)
 * 
 * E50 = IF('НоваяЭХ -3 стр.(а)'!E49=0,0,E38*(100-E49)/100*((100-E51)/(100-'НоваяЭХ -3 стр.(а)'!E49)))
 * F50 = IF('НоваяЭХ -3 стр.(а)'!F49=0,0,F38*(100-F49)/100*((100-F51)/(100-'НоваяЭХ -3 стр.(а)'!F49)))
 * G50 = (E50*E21+F50*F21)/G21
 * 
 * 'НоваяЭХ -3 стр.(а)'!E49/F49 означает param_id = 35 (категория 3a)
 */
function calculateNetEfficiencyWithOwnNeeds($date, $shiftId, $blockId, $values) {
    try {
        // Получаем среднюю электрическую нагрузку из категории 3a (E49/F49) - param_id = 35
        $avgElectricLoad3a = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 35 && $value['tg_id'] == $blockId) {
                $avgElectricLoad3a = $value['value'];
                break;
            }
        }
        
        error_log("КПД нетто котла с учетом собственных нужд: средняя электрическая нагрузка из 3a для блока $blockId: " . ($avgElectricLoad3a !== null ? $avgElectricLoad3a : 'null'));
        
        if ($avgElectricLoad3a === null || $avgElectricLoad3a == 0) {
            error_log("Средняя электрическая нагрузка из 3a равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем КПД брутто котла с поправками (E38/F38) - param_id = 293
        $boilerEfficiencyWithCorrections = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 293 && $value['tg_id'] == $blockId) {
                $boilerEfficiencyWithCorrections = $value['value'];
                break;
            }
        }
        
        if ($boilerEfficiencyWithCorrections === null) {
            error_log("Не найдено значение КПД брутто котла с поправками для блока $blockId");
            return 0;
        }
        
        // Получаем удельный расход топлива на собственные нужды (E49/F49) - param_id = 301
        $specificFuelConsumptionForOwnNeeds = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 301 && $value['tg_id'] == $blockId) {
                $specificFuelConsumptionForOwnNeeds = $value['value'];
                break;
            }
        }
        
        if ($specificFuelConsumptionForOwnNeeds === null) {
            error_log("Не найдено значение удельного расхода топлива на собственные нужды для блока $blockId");
            return 0;
        }
        
        // Получаем общий удельный расход топлива (E51/F51) - param_id = 303
        $totalSpecificFuelConsumption = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 303 && $value['tg_id'] == $blockId) {
                $totalSpecificFuelConsumption = $value['value'];
                break;
            }
        }
        
        if ($totalSpecificFuelConsumption === null) {
            error_log("Не найдено значение общего удельного расхода топлива для блока $blockId");
            return 0;
        }
        
        // Для ОЧ-130 (G50) используем формулу: (E50*E21+F50*F21)/G21
        if ($blockId == 9) {
            // Получаем E50 и F50 (ТГ7 и ТГ8)
            $tg7Efficiency = calculateNetEfficiencyWithOwnNeedsForBlock($date, $shiftId, 7, $values);
            $tg8Efficiency = calculateNetEfficiencyWithOwnNeedsForBlock($date, $shiftId, 8, $values);
            
            // Получаем E21 и F21 (средняя тепловая нагрузка для ТГ7 и ТГ8)
            $tg7ThermalLoad = null;
            $tg8ThermalLoad = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 279 && $value['tg_id'] == 7) {
                    $tg7ThermalLoad = $value['value'];
                } elseif ($value['param_id'] == 279 && $value['tg_id'] == 8) {
                    $tg8ThermalLoad = $value['value'];
                }
            }
            
            if ($tg7ThermalLoad === null || $tg8ThermalLoad === null) {
                error_log("Не найдены значения средней тепловой нагрузки для ТГ7 или ТГ8");
                return 0;
            }
            
            $g21 = $tg7ThermalLoad + $tg8ThermalLoad; // G21 = E21 + F21
            
            if ($g21 == 0) {
                error_log("G21 равно 0, возвращаем 0");
                return 0;
            }
            
            $efficiency = ($tg7Efficiency * $tg7ThermalLoad + $tg8Efficiency * $tg8ThermalLoad) / $g21;
            
            error_log("КПД нетто котла с учетом собственных нужд для ОЧ-130: E50=$tg7Efficiency, F50=$tg8Efficiency, E21=$tg7ThermalLoad, F21=$tg8ThermalLoad, G21=$g21, результат=$efficiency");
            
            return limitDecimalValue($efficiency, "calculateNetEfficiencyWithOwnNeeds for ОЧ-130 (block 9)");
        }
        
        // Для ТГ7 и ТГ8 используем обычную формулу
        return calculateNetEfficiencyWithOwnNeedsForBlock($date, $shiftId, $blockId, $values);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете КПД нетто котла с учетом собственных нужд: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет КПД нетто котла с учетом собственных нужд для конкретного блока (ТГ7 или ТГ8)
 */
function calculateNetEfficiencyWithOwnNeedsForBlock($date, $shiftId, $blockId, $values) {
    try {
        // Получаем среднюю электрическую нагрузку из категории 3a (E49/F49) - param_id = 35
        $avgElectricLoad3a = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 35 && $value['tg_id'] == $blockId) {
                $avgElectricLoad3a = $value['value'];
                break;
            }
        }
        
        if ($avgElectricLoad3a === null || $avgElectricLoad3a == 0) {
            error_log("Средняя электрическая нагрузка из 3a равна 0 или null, возвращаем 0");
            return 0;
        }
        
        // Получаем КПД брутто котла с поправками (E38/F38) - param_id = 293
        $boilerEfficiencyWithCorrections = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 293 && $value['tg_id'] == $blockId) {
                $boilerEfficiencyWithCorrections = $value['value'];
                break;
            }
        }
        
        if ($boilerEfficiencyWithCorrections === null) {
            error_log("Не найдено значение КПД брутто котла с поправками для блока $blockId");
            return 0;
        }
        
        // Получаем удельный расход топлива на собственные нужды (E49/F49) - param_id = 301
        $specificFuelConsumptionForOwnNeeds = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 301 && $value['tg_id'] == $blockId) {
                $specificFuelConsumptionForOwnNeeds = $value['value'];
                break;
            }
        }
        
        if ($specificFuelConsumptionForOwnNeeds === null) {
            error_log("Не найдено значение удельного расхода топлива на собственные нужды для блока $blockId");
            return 0;
        }
        
        // Получаем общий удельный расход топлива (E51/F51) - param_id = 303
        $totalSpecificFuelConsumption = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 303 && $value['tg_id'] == $blockId) {
                $totalSpecificFuelConsumption = $value['value'];
                break;
            }
        }
        
        if ($totalSpecificFuelConsumption === null) {
            error_log("Не найдено значение общего удельного расхода топлива для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: E38*(100-E49)/100*((100-E51)/(100-E49))
        // Проверяем деление на ноль
        $denominator = 100 - $avgElectricLoad3a;
        if (abs($denominator) < 0.0001) {
            error_log("WARNING: Деление на ноль в расчете КПД нетто для блока $blockId: avgElectricLoad3a=$avgElectricLoad3a, denominator=$denominator");
            return 0;
        }
        
        $efficiency = $boilerEfficiencyWithCorrections * (100 - $avgElectricLoad3a) / 100 * ((100 - $totalSpecificFuelConsumption) / $denominator);
        
        error_log("КПД нетто котла с учетом собственных нужд для блока $blockId: E38=$boilerEfficiencyWithCorrections, E49(3a)=$avgElectricLoad3a, E51=$totalSpecificFuelConsumption, результат=$efficiency");
        
        return limitDecimalValue($efficiency, "calculateNetEfficiencyWithOwnNeedsForBlock for block $blockId");
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете КПД нетто котла с учетом собственных нужд для блока: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет общего удельного расхода топлива (param_id = 303)
 * 
 * E51 = E45+'НоваяЭХ -3 стр.(а)'!E49
 * F51 = F45+'НоваяЭХ -3 стр.(а)'!F49
 * G51 = (E51*'НоваяЭХ -3 стр.(а)'!E11+F51*'НоваяЭХ -3 стр.(а)'!F11)/'НоваяЭХ -3 стр.(а)'!G11
 * 
 * 'НоваяЭХ -3 стр.(а)'!E49/F49 означает param_id = 35 (категория 3a)
 * 'НоваяЭХ -3 стр.(а)'!E11/F11/G11 означает param_id = 28 (часы работы)
 */
function calculateTotalSpecificFuelConsumption($date, $shiftId, $blockId, $values) {
    try {
        // Получаем удельный расход топлива на выработку электроэнергии (E45/F45) - param_id = 299
        $specificFuelConsumptionForElectricity = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 299 && $value['tg_id'] == $blockId) {
                $specificFuelConsumptionForElectricity = $value['value'];
                break;
            }
        }
        
        if ($specificFuelConsumptionForElectricity === null) {
            error_log("Не найдено значение удельного расхода топлива на выработку электроэнергии для блока $blockId");
            return 0;
        }
        
        // Получаем среднюю электрическую нагрузку из категории 3a (E49/F49) - param_id = 35
        $avgElectricLoad3a = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 35 && $value['tg_id'] == $blockId) {
                $avgElectricLoad3a = $value['value'];
                break;
            }
        }
        
        if ($avgElectricLoad3a === null) {
            error_log("Не найдено значение средней электрической нагрузки из 3a для блока $blockId");
            return 0;
        }
        
        // Для ОЧ-130 (G51) используем формулу: (E51*E11+F51*F11)/G11
        if ($blockId == 9) {
            // Получаем E51 и F51 (ТГ7 и ТГ8)
            $tg7TotalConsumption = calculateTotalSpecificFuelConsumptionForBlock($date, $shiftId, 7, $values);
            $tg8TotalConsumption = calculateTotalSpecificFuelConsumptionForBlock($date, $shiftId, 8, $values);
            
            // Получаем E11 и F11 (часы работы для ТГ7 и ТГ8)
            $tg7WorkingHours = null;
            $tg8WorkingHours = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 28 && $value['tg_id'] == 7) {
                    $tg7WorkingHours = $value['value'];
                } elseif ($value['param_id'] == 28 && $value['tg_id'] == 8) {
                    $tg8WorkingHours = $value['value'];
                }
            }
            
            if ($tg7WorkingHours === null || $tg8WorkingHours === null) {
                error_log("Не найдены значения часов работы для ТГ7 или ТГ8");
                return 0;
            }
            
            $g11 = $tg7WorkingHours + $tg8WorkingHours; // G11 = E11 + F11
            
            if ($g11 == 0) {
                error_log("G11 равно 0, возвращаем 0");
                return 0;
            }
            
            $totalConsumption = ($tg7TotalConsumption * $tg7WorkingHours + $tg8TotalConsumption * $tg8WorkingHours) / $g11;
            
            error_log("Общий удельный расход топлива для ОЧ-130: E51=$tg7TotalConsumption, F51=$tg8TotalConsumption, E11=$tg7WorkingHours, F11=$tg8WorkingHours, G11=$g11, результат=$totalConsumption");
            
            return $totalConsumption;
        }
        
        // Для ТГ7 и ТГ8 используем обычную формулу
        return calculateTotalSpecificFuelConsumptionForBlock($date, $shiftId, $blockId, $values);
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете общего удельного расхода топлива: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет общего удельного расхода топлива для конкретного блока (ТГ7 или ТГ8)
 */
function calculateTotalSpecificFuelConsumptionForBlock($date, $shiftId, $blockId, $values) {
    try {
        // Получаем удельный расход топлива на выработку электроэнергии (E45/F45) - param_id = 299
        $specificFuelConsumptionForElectricity = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 299 && $value['tg_id'] == $blockId) {
                $specificFuelConsumptionForElectricity = $value['value'];
                break;
            }
        }
        
        if ($specificFuelConsumptionForElectricity === null) {
            error_log("Не найдено значение удельного расхода топлива на выработку электроэнергии для блока $blockId");
            return 0;
        }
        
        // Получаем среднюю электрическую нагрузку из категории 3a (E49/F49) - param_id = 35
        $avgElectricLoad3a = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 35 && $value['tg_id'] == $blockId) {
                $avgElectricLoad3a = $value['value'];
                break;
            }
        }
        
        if ($avgElectricLoad3a === null) {
            error_log("Не найдено значение средней электрической нагрузки из 3a для блока $blockId");
            return 0;
        }
        
        // Применяем формулу: E45+E49
        $totalConsumption = $specificFuelConsumptionForElectricity + $avgElectricLoad3a;
        
        error_log("Общий удельный расход топлива для блока $blockId: E45=$specificFuelConsumptionForElectricity, E49(3a)=$avgElectricLoad3a, результат=$totalConsumption");
        
        return $totalConsumption;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете общего удельного расхода топлива для блока: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет коэффициента теплового потока, %
 * Формула: E7=IF('НоваяЭХ -3 стр.(а)'!E29=0,0,100-1.5*700/'НоваяЭХ -3 стр.(а)'!E29)
 * Где 'НоваяЭХ -3 стр.(а)'!E29 это param_id = 35 (средняя электрическая нагрузка из категории 3a)
 */
function calculateHeatFlowCoefficient($date, $shiftId, $blockId, $values) {
    try {
        // Получаем среднюю электрическую нагрузку из категории 3a (E29) - param_id = 35
        $avgElectricLoad3a = null;
        foreach ($values as $value) {
            if ($value['param_id'] == 35 && $value['tg_id'] == $blockId) {
                $avgElectricLoad3a = $value['value'];
                break;
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        if ($avgElectricLoad3a === null || $avgElectricLoad3a == 0) {
            $db = getDbConnection();
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
            $avgElectricLoad3a = $result ? (float)$result['value'] : 0;
        }
        
        if ($avgElectricLoad3a == 0) {
            error_log("Средняя электрическая нагрузка (param_id=35) не найдена или равна 0 для блока $blockId");
            return 0;
        }
        
        // Формула: 100 - 1.5 * 700 / E29
        $coefficient = 100 - (1.5 * 700 / $avgElectricLoad3a);
        
        error_log("Коэффициент теплового потока для блока $blockId: E29=$avgElectricLoad3a, результат=$coefficient");
        
        return $coefficient;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете коэффициента теплового потока: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет коэффициента учитывающего влияние стабилизации тепловых процессов, %
 * Заглушка - требует уточнения формулы
 */
function calculateStabilizationCoefficient($date, $shiftId, $blockId, $values) {
    try {
        // Заглушка - требует уточнения формулы
        if ($blockId == 8) {
            return 0.2;
        } else {
            return 0;
        }
    } catch (Exception $e) {
        error_log('Ошибка при расчете коэффициента стабилизации: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет удельного расхода условного топлива на отпуск электроэнергии
 * Заглушка - требует уточнения формулы
 */
function calculateSpecificFuelConsumptionForElectricity4($date, $shiftId, $blockId, $values) {
    try {
        // Заглушка - требует уточнения формулы
        return 0;
    } catch (Exception $e) {
        error_log('Ошибка при расчете удельного расхода топлива на электроэнергию: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет номинального значения без учета работы ОИУ
 * Формула: 
 * E10=IF(E18=0,0,(1+E8*0.01)*E54*10^4/(7*E50*E7))
 * F10=IF(F18=0,0,(1+F8*0.01)*F54*10^4/(7*F50*F7))
 * G10=(E10*E13+F13*F10)/G13
 * Где:
 * - E18/F18 = param_id = 277 (число часов работы группы котлов) из категории 3b
 * - E8/F8 = param_id = 2 (коэффициент стабилизации) из категории 4
 * - E54/F54 = param_id = 53 (номинальное значение удельного расхода тепла нетто по подгруппе турбоагрегатов) из категории 3a, row_num = 52
 * - E50/F50 = param_id = 50 (номинальное относительное значение расхода электроэнергии на СН подгруппы т/агрегатов) из категории 3a
 * - E7/F7 = param_id = 1 (коэффициент теплового потока) из категории 4
 * - E13/F13/G13 = param_id = 27 (отпуск электроэнергии с шин) из категории 3a
 */
function calculateNominalValueWithoutOIU($date, $shiftId, $blockId, $values) {
    try {
        if ($blockId == 7 || $blockId == 8) {
            // Для ТГ7 и ТГ8: E10/F10 = IF(E18/F18=0,0,(1+E8/F8*0.01)*E54/F54*10^4/(7*E50/F50*E7/F7))
            
            // Получаем E18/F18 (число часов работы группы котлов) из категории 3b - param_id = 277
            $boilerWorkingHours = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 277 && $value['tg_id'] == $blockId) {
                    $boilerWorkingHours = $value['value'];
                    break;
                }
            }
            
            // Если не нашли в текущих значениях, ищем в базе
            if ($boilerWorkingHours === null) {
                $db = getDbConnection();
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 277 AND tg_id = ? AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([$blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $boilerWorkingHours = $result ? (float)$result['value'] : 0;
            }
            
            if ($boilerWorkingHours == 0) {
                error_log("Число часов работы группы котлов (param_id=277) равно 0 для блока $blockId, возвращаем 0");
                return 0;
            }
            
            // Получаем E8/F8 (коэффициент стабилизации) из категории 4 - param_id = 2
            $stabilizationCoefficient = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 2 && $value['tg_id'] == $blockId) {
                    $stabilizationCoefficient = $value['value'];
                    break;
                }
            }
            
            if ($stabilizationCoefficient === null) {
                $db = getDbConnection();
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 2 AND tg_id = ? AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([$blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $stabilizationCoefficient = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем E54/F54 (номинальное значение удельного расхода тепла нетто) из категории 3a - param_id = 53
            $nominalHeatConsumption = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 53 && $value['tg_id'] == $blockId) {
                    $nominalHeatConsumption = $value['value'];
                    break;
                }
            }
            
            if ($nominalHeatConsumption === null) {
                $db = getDbConnection();
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 53 AND tg_id = ? AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([$blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $nominalHeatConsumption = $result ? (float)$result['value'] : 0;
            }
            
            if ($nominalHeatConsumption == 0) {
                error_log("Номинальное значение удельного расхода тепла нетто (param_id=53) равно 0 для блока $blockId");
                return 0;
            }
            
            // Получаем E50/F50 (номинальное относительное значение расхода электроэнергии на СН) из категории 3a - param_id = 50
            $nominalElectricityConsumption = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 50 && $value['tg_id'] == $blockId) {
                    $nominalElectricityConsumption = $value['value'];
                    break;
                }
            }
            
            if ($nominalElectricityConsumption === null) {
                $db = getDbConnection();
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 50 AND tg_id = ? AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([$blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $nominalElectricityConsumption = $result ? (float)$result['value'] : 0;
            }
            
            if ($nominalElectricityConsumption == 0) {
                error_log("Номинальное относительное значение расхода электроэнергии на СН (param_id=50) равно 0 для блока $blockId");
                return 0;
            }
            
            // Получаем E7/F7 (коэффициент теплового потока) из категории 4 - param_id = 1
            $heatFlowCoefficient = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 1 && $value['tg_id'] == $blockId) {
                    $heatFlowCoefficient = $value['value'];
                    break;
                }
            }
            
            if ($heatFlowCoefficient === null) {
                $db = getDbConnection();
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 1 AND tg_id = ? AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([$blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $heatFlowCoefficient = $result ? (float)$result['value'] : 0;
            }
            
            if ($heatFlowCoefficient == 0) {
                error_log("Коэффициент теплового потока (param_id=1) равен 0 для блока $blockId");
                return 0;
            }
            
            // Формула: (1 + E8 * 0.01) * E54 * 10^4 / (7 * E50 * E7)
            $denominator = 7 * $nominalElectricityConsumption * $heatFlowCoefficient;
            if ($denominator == 0) {
                error_log("Знаменатель равен 0, возвращаем 0");
                return 0;
            }
            
            $result = (1 + $stabilizationCoefficient * 0.01) * $nominalHeatConsumption * 10000 / $denominator;
            
            error_log("Номинальное значение без ОИУ для блока $blockId: E18=$boilerWorkingHours, E8=$stabilizationCoefficient, E54=$nominalHeatConsumption, E50=$nominalElectricityConsumption, E7=$heatFlowCoefficient, результат=$result");
            
            return limitDecimalValue($result, "calculateNominalValueWithoutOIU for block $blockId");
            
        } elseif ($blockId == 9) {
            // Для ОЧ-130: G10 = (E10*E13+F13*F10)/G13
            $db = getDbConnection();
            
            // Получаем E10 (номинальное значение без ОИУ для ТГ7)
            $e10 = calculateNominalValueWithoutOIU($date, $shiftId, 7, $values);
            
            // Получаем F10 (номинальное значение без ОИУ для ТГ8)
            $f10 = calculateNominalValueWithoutOIU($date, $shiftId, 8, $values);
            
            // Получаем E13 (отпуск электроэнергии с шин для ТГ7) - param_id = 27
            $e13 = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 27 && $value['tg_id'] == 7) {
                    $e13 = $value['value'];
                    break;
                }
            }
            
            if ($e13 === null) {
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 27 AND tg_id = 7 AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$date, $shiftId]);
                } else {
                    $stmt->execute([$date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $e13 = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем F13 (отпуск электроэнергии с шин для ТГ8) - param_id = 27
            $f13 = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 27 && $value['tg_id'] == 8) {
                    $f13 = $value['value'];
                    break;
                }
            }
            
            if ($f13 === null) {
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 27 AND tg_id = 8 AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$date, $shiftId]);
                } else {
                    $stmt->execute([$date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $f13 = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем G13 (отпуск электроэнергии с шин для ОЧ-130) - param_id = 27
            $g13 = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 27 && $value['tg_id'] == 9) {
                    $g13 = $value['value'];
                    break;
                }
            }
            
            if ($g13 === null) {
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 27 AND tg_id = 9 AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$date, $shiftId]);
                } else {
                    $stmt->execute([$date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $g13 = $result ? (float)$result['value'] : 0;
            }
            
            if ($g13 == 0) {
                error_log("Отпуск электроэнергии с шин для ОЧ-130 (param_id=27) равен 0, возвращаем 0");
                return 0;
            }
            
            // Формула: G10 = (E10*E13+F13*F10)/G13
            $result = ($e10 * $e13 + $f13 * $f10) / $g13;
            
            error_log("Номинальное значение без ОИУ для ОЧ-130: E10=$e10, E13=$e13, F10=$f10, F13=$f13, G13=$g13, результат=$result");
            
            return limitDecimalValue($result, "calculateNominalValueWithoutOIU for block 9");
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете номинального значения без ОИУ: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки к удельному расходу топлива на пуски
 * Заглушка - требует уточнения формулы
 */
/**
 * Расчет поправки к удельному расходу топлива на пуски
 * Формула: 
 * E11=IF(C13=0,0,74.9*C13*1000/E13)
 * F11=IF(D13=0,0,74.9*D13*1000/F13)
 * G11=(E11*E13+F13*F11)/G13
 * Где:
 * - C13/D13 = количество пусков из 'Исх. данные оч.130' (parameter_values с equipment_id = 7, cell = C13/D13)
 *   Или из tg_result_values с param_id = 30 (Количество пусков т/агрегатов по диспетчерскому графику)
 * - E13/F13/G13 = отпуск электроэнергии с шин (param_id = 27) из tg_result_values
 */
function calculateStartupCorrection($date, $shiftId, $blockId, $values) {
    try {
        if ($blockId == 7 || $blockId == 8) {
            // Для ТГ7 и ТГ8: E11/F11 = IF(C13/D13=0,0,74.9*C13/D13*1000/E13/F13)
            $db = getDbConnection();
            
            // Определяем cell в зависимости от блока
            $cell13 = $blockId == 7 ? 'C13' : 'D13';
            
            // Получаем C13/D13 (количество пусков) из parameter_values для equipment_id = 7
            $c13 = null;
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
            
            if ($c13 == 0) {
                error_log("Количество пусков (C13/D13) равно 0 для блока $blockId, возвращаем 0");
                return 0;
            }
            
            // Получаем E13/F13 (отпуск электроэнергии с шин) - param_id = 27
            $e13 = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 27 && $value['tg_id'] == $blockId) {
                    $e13 = $value['value'];
                    break;
                }
            }
            
            if ($e13 === null) {
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 27 AND tg_id = ? AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([$blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $e13 = $result ? (float)$result['value'] : 0;
            }
            
            if ($e13 == 0) {
                error_log("Отпуск электроэнергии с шин (E13/F13) равно 0 для блока $blockId, возвращаем 0");
                return 0;
            }
            
            // Формула: 74.9 * C13 * 1000 / E13
            $result = 74.9 * $c13 * 1000 / $e13;
            
            error_log("Поправка на пуски для блока $blockId: C13=$c13, E13=$e13, результат=$result");
            
            return limitDecimalValue($result, "calculateStartupCorrection for block $blockId");
            
        } elseif ($blockId == 9) {
            // Для ОЧ-130: G11 = (E11*E13+F13*F11)/G13
            $e11 = calculateStartupCorrection($date, $shiftId, 7, $values);
            $f11 = calculateStartupCorrection($date, $shiftId, 8, $values);
            
            // Получаем E13/F13/G13 (отпуск электроэнергии с шин) - param_id = 27
            $e13 = null;
            $f13 = null;
            $g13 = null;
            
            foreach ($values as $value) {
                if ($value['param_id'] == 27) {
                    if ($value['tg_id'] == 7) {
                        $e13 = $value['value'];
                    } elseif ($value['tg_id'] == 8) {
                        $f13 = $value['value'];
                    } elseif ($value['tg_id'] == 9) {
                        $g13 = $value['value'];
                    }
                }
            }
            
            if ($e13 === null || $f13 === null || $g13 === null) {
                $db = getDbConnection();
                if ($e13 === null) {
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 27 AND tg_id = 7 AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    if ($shiftId) {
                        $stmt->execute([$date, $shiftId]);
                    } else {
                        $stmt->execute([$date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $e13 = $result ? (float)$result['value'] : 0;
                }
                
                if ($f13 === null) {
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 27 AND tg_id = 8 AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    if ($shiftId) {
                        $stmt->execute([$date, $shiftId]);
                    } else {
                        $stmt->execute([$date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $f13 = $result ? (float)$result['value'] : 0;
                }
                
                if ($g13 === null) {
                    $stmt = $db->prepare('
                        SELECT value FROM tg_result_values 
                        WHERE param_id = 27 AND tg_id = 9 AND date = ?
                        ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                    );
                    if ($shiftId) {
                        $stmt->execute([$date, $shiftId]);
                    } else {
                        $stmt->execute([$date]);
                    }
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $g13 = $result ? (float)$result['value'] : 0;
                }
            }
            
            if ($g13 == 0) {
                error_log("Отпуск электроэнергии с шин для ОЧ-130 (G13) равно 0, возвращаем 0");
                return 0;
            }
            
            // Формула: G11 = (E11*E13+F13*F11)/G13
            $result = ($e11 * $e13 + $f13 * $f11) / $g13;
            
            error_log("Поправка на пуски для ОЧ-130: E11=$e11, E13=$e13, F11=$f11, F13=$f13, G13=$g13, результат=$result");
            
            return limitDecimalValue($result, "calculateStartupCorrection for block 9");
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на пуски: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки к удельному расходу топлива на cos φ
 * Заглушка - требует уточнения формулы
 */
function calculateCosPhiCorrection($date, $shiftId, $blockId, $values) {
    try {
        // Заглушка - требует уточнения формулы
        return 0;
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на cos φ: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки к удельному расходу топлива на работу ОИУ
 * Заглушка - требует уточнения формулы
 */
function calculateOIUCorrection($date, $shiftId, $blockId, $values) {
    try {
        // Заглушка - требует уточнения формулы
        return 0;
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на ОИУ: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки к удельному расходу топлива на карбонатный занос конденсатора
 * Заглушка - требует уточнения формулы
 */
/**
 * Расчет поправки к удельному расходу топлива на карбонатный занос конденсатора
 * Константные значения:
 * - Для блока 7 (ТГ7): 1.48 g/kWh
 * - Для блока 8 (ТГ8): 2.08 g/kWh
 * - Для других блоков: 0
 */
function calculateCarbonateCorrection($date, $shiftId, $blockId, $values) {
    try {
        if ($blockId == 7) {
            return 1.48;
        } elseif ($blockId == 8) {
            return 2.08;
        } else {
            // Для ОЧ-130 и других блоков возвращаем 0
            return 0;
        }
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на карбонатный занос: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки к удельному расходу топлива на режимы работы
 * Заглушка - требует уточнения формулы
 */
function calculateOperationModeCorrection($date, $shiftId, $blockId, $values) {
    try {
        // Заглушка - требует уточнения формулы
        return 0;
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на режимы работы: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет поправки к удельному расходу топлива на работу БН
 * Заглушка - требует уточнения формулы
 */
function calculateBNCorrection($date, $shiftId, $blockId, $values) {
    try {
        // Заглушка - требует уточнения формулы
        return 0;
    } catch (Exception $e) {
        error_log('Ошибка при расчете поправки на БН: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет номинального значения с учетом работы ОИУ и других факторов (Блоки)
 * Заглушка - требует уточнения формулы
 */
/**
 * Расчет номинального значения с учетом работы ОИУ и других факторов (Блоки)
 * Формула: E17 = E10 + E11 + E12 + E13 + E14 + E15 + E16
 * Где:
 * - E10 = param_id = 4 (Номинальное значение без учета работы ОИУ)
 * - E11 = param_id = 5 (Поправка к удельному расходу топлива на пуски)
 * - E12 = param_id = 6 (Поправка к удельному расходу топлива на cos φ)
 * - E13 = param_id = 7 (Поправка к удельному расходу топлива на работу ОИУ)
 * - E14 = param_id = 8 (Поправка к удельному расходу топлива на карбонатный занос конденсатора)
 * - E15 = param_id = 9 (Поправка к удельному расходу топлива на режимы работы)
 * - E16 = param_id = 10 (Поправка к удельному расходу топлива на работу БН)
 */
function calculateNominalValueWithOIU($date, $shiftId, $blockId, $values) {
    try {
        // Получаем все необходимые значения из массива $values
        $e10 = null; // param_id = 4
        $e11 = null; // param_id = 5
        $e12 = null; // param_id = 6
        $e13 = null; // param_id = 7
        $e14 = null; // param_id = 8
        $e15 = null; // param_id = 9
        $e16 = null; // param_id = 10
        
        foreach ($values as $value) {
            if ($value['tg_id'] == $blockId && $value['shift_id'] == ($shiftId ? (int)$shiftId : null)) {
                switch ($value['param_id']) {
                    case 4:
                        $e10 = $value['value'];
                        break;
                    case 5:
                        $e11 = $value['value'];
                        break;
                    case 6:
                        $e12 = $value['value'];
                        break;
                    case 7:
                        $e13 = $value['value'];
                        break;
                    case 8:
                        $e14 = $value['value'];
                        break;
                    case 9:
                        $e15 = $value['value'];
                        break;
                    case 10:
                        $e16 = $value['value'];
                        break;
                }
            }
        }
        
        // Если не нашли в текущих значениях, ищем в базе
        $db = getDbConnection();
        
        if ($e10 === null) {
            $stmt = $db->prepare('
                SELECT value FROM tg_result_values 
                WHERE param_id = 4 AND tg_id = ? AND date = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$blockId, $date, $shiftId]);
            } else {
                $stmt->execute([$blockId, $date]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e10 = $result ? (float)$result['value'] : 0;
        }
        
        if ($e11 === null) {
            $stmt = $db->prepare('
                SELECT value FROM tg_result_values 
                WHERE param_id = 5 AND tg_id = ? AND date = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$blockId, $date, $shiftId]);
            } else {
                $stmt->execute([$blockId, $date]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e11 = $result ? (float)$result['value'] : 0;
        }
        
        if ($e12 === null) {
            $stmt = $db->prepare('
                SELECT value FROM tg_result_values 
                WHERE param_id = 6 AND tg_id = ? AND date = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$blockId, $date, $shiftId]);
            } else {
                $stmt->execute([$blockId, $date]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e12 = $result ? (float)$result['value'] : 0;
        }
        
        if ($e13 === null) {
            $stmt = $db->prepare('
                SELECT value FROM tg_result_values 
                WHERE param_id = 7 AND tg_id = ? AND date = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$blockId, $date, $shiftId]);
            } else {
                $stmt->execute([$blockId, $date]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e13 = $result ? (float)$result['value'] : 0;
        }
        
        if ($e14 === null) {
            $stmt = $db->prepare('
                SELECT value FROM tg_result_values 
                WHERE param_id = 8 AND tg_id = ? AND date = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$blockId, $date, $shiftId]);
            } else {
                $stmt->execute([$blockId, $date]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e14 = $result ? (float)$result['value'] : 0;
        }
        
        if ($e15 === null) {
            $stmt = $db->prepare('
                SELECT value FROM tg_result_values 
                WHERE param_id = 9 AND tg_id = ? AND date = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$blockId, $date, $shiftId]);
            } else {
                $stmt->execute([$blockId, $date]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e15 = $result ? (float)$result['value'] : 0;
        }
        
        if ($e16 === null) {
            $stmt = $db->prepare('
                SELECT value FROM tg_result_values 
                WHERE param_id = 10 AND tg_id = ? AND date = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$blockId, $date, $shiftId]);
            } else {
                $stmt->execute([$blockId, $date]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e16 = $result ? (float)$result['value'] : 0;
        }
        
        // Формула: E17 = E10 + E11 + E12 + E13 + E14 + E15 + E16
        $result = $e10 + $e11 + $e12 + $e13 + $e14 + $e15 + $e16;
        
        error_log("Номинальное значение с ОИУ для блока $blockId: E10=$e10, E11=$e11, E12=$e12, E13=$e13, E14=$e14, E15=$e15, E16=$e16, результат=$result");
        
        return limitDecimalValue($result, "calculateNominalValueWithOIU for block $blockId");
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете номинального значения с ОИУ: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет номинального значения для ПГУ
 * Заглушка - требует уточнения формулы
 */
function calculateNominalValueForPGU($date, $shiftId, $blockId, $values) {
    try {
        // Заглушка - требует уточнения формулы
        return 0;
    } catch (Exception $e) {
        error_log('Ошибка при расчете номинального значения для ПГУ: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Расчет фактического значения УРТ
 * Формула: E39=IF(C4<=0,0,ROUND((C34*E32+C35*E33)/7000/C5*1000,2))
 * Для ТГ7: C4, C34, C35, E32, E33, C5 из 'Исходные данные оч.130' (equipment_id = 7)
 * Для ТГ8: D4, D34, D35, F32, F33, D5
 * Где:
 * - C4/D4 = отпуск электроэнергии с шин для ТГ7/ТГ8 (param_id = 27, tg_id = 7/8) или из parameter_values для equipment_id = 7
 * - C34/D34 = parameter_id = 45 ("В топлива за месяц (газ)") для equipment_id = 7, cell = C34/D34 (для ТГ7/ТГ8) или E30 (для ОЧ-130)
 * - C35/D35 = parameter_id = 46 ("В топлива за месяц (мазут)") для equipment_id = 7, cell = C35/D35 (для ТГ7/ТГ8) или E31 (для ОЧ-130)
 * - E32/F32 = parameter_id = 43 ("Факт Qнр (газ)") для equipment_id = 7, cell = E28/F28 (для ТГ7/ТГ8) или E28 (для ОЧ-130)
 * - E33/F33 = parameter_id = 44 ("Факт Qнр (мазут)") для equipment_id = 7, cell = E29/F29 (для ТГ7/ТГ8) или E29 (для ОЧ-130)
 * - C5/D5 = отпуск электроэнергии с шин для ТГ7/ТГ8 (param_id = 27, tg_id = 7/8) или из parameter_values для equipment_id = 7
 */
function calculateActualValue($date, $shiftId, $blockId, $values) {
    try {
        if ($blockId == 7 || $blockId == 8) {
            // Для ТГ7 и ТГ8: E39/F39 = IF(C4/D4<=0,0,ROUND((C34/D34*E32/F32+C35/D35*E33/F33)/7000/C5/D5*1000,2))
            $db = getDbConnection();
            
            // Определяем cell в зависимости от блока
            $cellPrefix = $blockId == 7 ? 'C' : 'D';
            $cellE = $blockId == 7 ? 'E' : 'F';
            
            // Получаем C4/D4 (отпуск электроэнергии с шин) - param_id = 27
            $c4 = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 27 && $value['tg_id'] == $blockId) {
                    $c4 = $value['value'];
                    break;
                }
            }
            
            if ($c4 === null) {
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 27 AND tg_id = ? AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$blockId, $date, $shiftId]);
                } else {
                    $stmt->execute([$blockId, $date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $c4 = $result ? (float)$result['value'] : 0;
            }
            
            if ($c4 <= 0) {
                error_log("Отпуск электроэнергии с шин (C4/D4) <= 0 для блока $blockId, возвращаем 0");
                return 0;
            }
            
            // Получаем C34/D34 (расход газа за месяц) - parameter_id = 45, equipment_id = 7
            // Для ТГ7/ТГ8 используем cell C34/D34, но это может быть E30 для ОЧ-130
            // Попробуем сначала C34/D34, если нет - то E30
            $c34 = null;
            $cell34 = $cellPrefix . '34';
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 45 AND equipment_id = 7 AND date = ? AND cell = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$date, $cell34, $shiftId]);
            } else {
                $stmt->execute([$date, $cell34]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && $result['value'] !== null) {
                $c34 = (float)$result['value'];
            } else {
                // Если нет C34/D34, пробуем E30 (для ОЧ-130)
                $stmt = $db->prepare('
                    SELECT value FROM parameter_values 
                    WHERE parameter_id = 45 AND equipment_id = 7 AND date = ? AND cell = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$date, 'E30', $shiftId]);
                } else {
                    $stmt->execute([$date, 'E30']);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $c34 = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем C35/D35 (расход мазута за месяц) - parameter_id = 46, equipment_id = 7
            $c35 = null;
            $cell35 = $cellPrefix . '35';
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 46 AND equipment_id = 7 AND date = ? AND cell = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$date, $cell35, $shiftId]);
            } else {
                $stmt->execute([$date, $cell35]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && $result['value'] !== null) {
                $c35 = (float)$result['value'];
            } else {
                // Если нет C35/D35, пробуем E31 (для ОЧ-130)
                $stmt = $db->prepare('
                    SELECT value FROM parameter_values 
                    WHERE parameter_id = 46 AND equipment_id = 7 AND date = ? AND cell = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$date, 'E31', $shiftId]);
                } else {
                    $stmt->execute([$date, 'E31']);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $c35 = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем E32/F32 (калорийность газа) - parameter_id = 43, equipment_id = 7
            $cell32 = $cellE . '28'; // E28/F28 для ТГ7/ТГ8
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 43 AND equipment_id = 7 AND date = ? AND cell = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$date, $cell32, $shiftId]);
            } else {
                $stmt->execute([$date, $cell32]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e32 = $result ? (float)$result['value'] : 0;
            
            // Если нет E28/F28, пробуем E28 (для ОЧ-130)
            if ($e32 == 0) {
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
                $e32 = $result ? (float)$result['value'] : 0;
            }
            
            // Получаем E33/F33 (калорийность мазута) - parameter_id = 44, equipment_id = 7
            $cell33 = $cellE . '29'; // E29/F29 для ТГ7/ТГ8
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 44 AND equipment_id = 7 AND date = ? AND cell = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$date, $cell33, $shiftId]);
            } else {
                $stmt->execute([$date, $cell33]);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e33 = $result ? (float)$result['value'] : 0;
            
            // Если нет E29/F29, пробуем E29 (для ОЧ-130)
            if ($e33 == 0) {
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
                $e33 = $result ? (float)$result['value'] : 0;
            }
            
            // C5/D5 = C4/D4 (отпуск электроэнергии с шин)
            $c5 = $c4;
            
            // Формула: ROUND((C34*E32+C35*E33)/7000/C5*1000,2)
            if ($c5 == 0) {
                error_log("C5/D5 равно 0 для блока $blockId, возвращаем 0");
                return 0;
            }
            
            $result = round(($c34 * $e32 + $c35 * $e33) / 7000 / $c5 * 1000, 2);
            
            error_log("Фактический УРТ для блока $blockId: C4=$c4, C34=$c34, C35=$c35, E32=$e32, E33=$e33, C5=$c5, результат=$result");
            
            return $result;
            
        } elseif ($blockId == 9) {
            // Для ОЧ-130: E40 = ROUND((E34*E32+E35*E33)/7000/E5*1000,2)
            $db = getDbConnection();
            
            // Получаем E34 (расход газа за месяц) - parameter_id = 45, equipment_id = 7, cell = E30
            $e34 = null;
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 45 AND equipment_id = 7 AND date = ? AND cell = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$date, 'E30', $shiftId]);
            } else {
                $stmt->execute([$date, 'E30']);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e34 = $result ? (float)$result['value'] : 0;
            
            // Получаем E35 (расход мазута за месяц) - parameter_id = 46, equipment_id = 7, cell = E31
            $e35 = null;
            $stmt = $db->prepare('
                SELECT value FROM parameter_values 
                WHERE parameter_id = 46 AND equipment_id = 7 AND date = ? AND cell = ?
                ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
            );
            if ($shiftId) {
                $stmt->execute([$date, 'E31', $shiftId]);
            } else {
                $stmt->execute([$date, 'E31']);
            }
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $e35 = $result ? (float)$result['value'] : 0;
            
            // Получаем E32 (калорийность газа) - parameter_id = 43, equipment_id = 7, cell = E28
            $e32 = null;
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
            $e32 = $result ? (float)$result['value'] : 0;
            
            // Получаем E33 (калорийность мазута) - parameter_id = 44, equipment_id = 7, cell = E29
            $e33 = null;
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
            $e33 = $result ? (float)$result['value'] : 0;
            
            // Получаем E5 (отпуск электроэнергии с шин для ОЧ-130) - param_id = 27, tg_id = 9
            $e5 = null;
            foreach ($values as $value) {
                if ($value['param_id'] == 27 && $value['tg_id'] == 9) {
                    $e5 = $value['value'];
                    break;
                }
            }
            
            if ($e5 === null) {
                $stmt = $db->prepare('
                    SELECT value FROM tg_result_values 
                    WHERE param_id = 27 AND tg_id = 9 AND date = ?
                    ' . ($shiftId ? 'AND shift_id = ?' : 'AND shift_id IS NULL')
                );
                if ($shiftId) {
                    $stmt->execute([$date, $shiftId]);
                } else {
                    $stmt->execute([$date]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $e5 = $result ? (float)$result['value'] : 0;
            }
            
            if ($e5 == 0) {
                error_log("E5 равно 0 для ОЧ-130, возвращаем 0");
                return 0;
            }
            
            // Формула: ROUND((E34*E32+E35*E33)/7000/E5*1000,2)
            $result = round(($e34 * $e32 + $e35 * $e33) / 7000 / $e5 * 1000, 2);
            
            error_log("Фактический УРТ для ОЧ-130: E34=$e34, E32=$e32, E35=$e35, E33=$e33, E5=$e5, результат=$result");
            
            return $result;
        }
        // Для ПГУ1, ПГУ2 и "по ПГУ" - не рассчитывается в этой функции (нужна отдельная логика)
        // F40/G40/H40 = F84/G84/H84 из pgu_result_values (param_id = 74, row_num = 84, "Экономия (+)/пережог (-) топлива")
        // Но это не фактический УРТ, а экономия/пережог. Фактический УРТ для ПГУ должен быть из param_id = 73
        
        return 0;
        
    } catch (Exception $e) {
        error_log('Ошибка при расчете фактического значения УРТ: ' . $e->getMessage());
        return 0;
    }
}