<?php
/**
 * PGU Calculations Controller
 * Clean architecture for PGU result parameters calculation
 */

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/db.php';

// ROW_NUM -> PARAM_ID mapping (single source of truth)
const ROW_TO_PARAM_MAPPING = [
    47 => 38, // row 47 -> param_id 38
    48 => 39, // row 48 -> param_id 39
    49 => 40, // row 49 -> param_id 40
    50 => 41, // row 50 -> param_id 41
    51 => 42, // row 51 -> param_id 42
    52 => 43, // row 52 -> param_id 43
    54 => 44, // row 54 -> param_id 44
    55 => 45, // row 55 -> param_id 45
    56 => 46, // row 56 -> param_id 46
    57 => 47, // row 57 -> param_id 47
    58 => 48, // row 58 -> param_id 48
    59 => 49, // row 59 -> param_id 49
    60 => 50, // row 60 -> param_id 50
    61 => 51, // row 61 -> param_id 51
    62 => 52, // row 62 -> param_id 52
    63 => 53, // row 63 -> param_id 53
    64 => 54, // row 64 -> param_id 54
    65 => 55, // row 65 -> param_id 55
    66 => 56, // row 66 -> param_id 56
    67 => 57, // row 67 -> param_id 57
    68 => 58, // row 68 -> param_id 58
    69 => 59, // row 69 -> param_id 59
    70 => 60, // row 70 -> param_id 60
    71 => 61, // row 71 -> param_id 61
    72 => 62, // row 72 -> param_id 62
    73 => 63, // row 73 -> param_id 63
    74 => 64, // row 74 -> param_id 64
    75 => 65, // row 75 -> param_id 65
    76 => 66, // row 76 -> param_id 66
    77 => 67, // row 77 -> param_id 67
    78 => 68, // row 78 -> param_id 68
    79 => 69, // row 79 -> param_id 69
    80 => 70, // row 80 -> param_id 70
    81 => 71, // row 81 -> param_id 71
    82 => 72, // row 82 -> param_id 72
    83 => 73, // row 83 -> param_id 73
    84 => 74, // row 84 -> param_id 74
];

/**
 * Main entry point for PGU calculations
 */
function performFullCalculation() {
    requireAuth();
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['periodType']) || !isset($data['dates'])) {
            sendError('Необходимо указать тип периода и даты', 400);
        }
        
        $results = calculatePguResults(
            $data['periodType'],
            $data['dates'],
            $data['shifts'] ?? []
        );
        
        sendSuccess([
            'message' => 'Расчет выполнен успешно',
            'results' => $results,
            'calculatedParams' => count($results)
        ]);
        
    } catch (Exception $e) {
        error_log("PGU Calculation Error: " . $e->getMessage());
        sendError('Ошибка при выполнении расчета: ' . $e->getMessage());
    }
}

/**
 * Calculate PGU results for given period/dates
 */
function calculatePguResults($periodType, $dates, $shifts = []) {
    $allResults = [];
    
    if ($periodType === 'period') {
        $results = calculateForPeriod(null, null, 'period', $dates['startDate'], $dates['endDate']);
        $allResults = array_merge($allResults, $results);
    } elseif ($periodType === 'shift') {
        $date = $dates['selectedDate'];
        error_log("PGU Calculation: Processing shifts for date $date: " . implode(', ', $shifts));
        foreach ($shifts as $shift) {
            $shiftId = getShiftIdFromName($shift);
            error_log("PGU Calculation: Processing shift $shift (ID: $shiftId)");
            $results = calculateForPeriod($date, $shiftId, 'shift');
            error_log("PGU Calculation: Got " . count($results) . " results for shift $shift");
            $allResults = array_merge($allResults, $results);
        }
        error_log("PGU Calculation: Total results after all shifts: " . count($allResults));
        
        // Сохраняем ВСЕ результаты для всех смен сразу
        if (!empty($allResults)) {
            $saveData = [
                'date' => $date,
                'periodType' => 'shift',
                'values' => $allResults
            ];
            savePguResultValues($saveData);
            error_log("PGU Calculation: Saved " . count($allResults) . " results to database");
        }
    } else { // day
        $date = $dates['selectedDate'];
        $results = calculateForPeriod($date, null, 'day');
        $allResults = array_merge($allResults, $results);
        
        // Сохраняем результаты для дня
        if (!empty($results)) {
            $saveData = [
                'date' => $date,
                'periodType' => 'day',
                'values' => $results
            ];
            savePguResultValues($saveData);
            error_log("PGU Calculation: Saved " . count($results) . " results for day");
        }
    }
    
    // Сохраняем результаты для периода
    if ($periodType === 'period' && !empty($allResults)) {
        $saveData = [
            'date' => $dates['startDate'],
            'periodType' => 'period',
            'period_start' => $dates['startDate'],
            'period_end' => $dates['endDate'],
            'values' => $allResults
        ];
        savePguResultValues($saveData);
        error_log("PGU Calculation: Saved " . count($allResults) . " results for period");
    }
    
    return $allResults;
}

/**
 * Calculate for specific period and save results
 */
function calculateForPeriod($date, $shiftId, $periodType, $periodStart = null, $periodEnd = null) {
    $inputData = getInputDataForCalculation($date, $shiftId, $periodType, $periodStart, $periodEnd);
    
    // Calculate all rows
    $calculations = [
        47 => calculateRow47($inputData),
        48 => calculateRow48($inputData),
        49 => calculateRow49($inputData),
        50 => calculateRow50($inputData),
        51 => calculateRow51($inputData),
        52 => calculateRow52($inputData),
        54 => calculateRow54($inputData),
        55 => calculateRow55($inputData),
        56 => calculateRow56($inputData),
        57 => calculateRow57($inputData),
        58 => calculateRow58($inputData),
        59 => calculateRow59($inputData),
        60 => calculateRow60($inputData),
        61 => calculateRow61($inputData),
        62 => calculateRow62($inputData),
        63 => calculateRow63($inputData),
        64 => calculateRow64($inputData),
        65 => calculateRow65($inputData),
        66 => calculateRow66($inputData),
        67 => calculateRow67($inputData),
        68 => calculateRow68($inputData),
        69 => calculateRow69($inputData),
        70 => calculateRow70($inputData),
        71 => calculateRow71($inputData),
        72 => calculateRow72($inputData),
        73 => calculateRow73($inputData),
        74 => calculateRow74($inputData),
        75 => calculateRow75($inputData),
        76 => calculateRow76($inputData),
        77 => calculateRow77($inputData),
        78 => calculateRow78($inputData),
        79 => calculateRow79($inputData),
        80 => calculateRow80($inputData),
        81 => calculateRow81($inputData),
        82 => calculateRow82($inputData),
        83 => calculateRow83($inputData),
        84 => calculateRow84($inputData),
    ];
    
    // Convert to save format
    $results = [];
    foreach ($calculations as $rowNum => $values) {
        [$fValue, $gValue, $hValue] = $values;
        $paramId = ROW_TO_PARAM_MAPPING[$rowNum];
        
        // Сохраняем все значения с точностью до 4 знаков
        // Округление для отображения происходит на frontend
        $decimalPlaces = 4;
        
        // ПГУ 1 (F)
        if ($fValue !== null) {
            $results[] = [
                'param_id' => $paramId,
                'pgu_id' => 1,
                'value' => round($fValue, $decimalPlaces),
                'shift_id' => $periodType === 'shift' ? $shiftId : null
            ];
        }
        
        // ПГУ 2 (G) 
        if ($gValue !== null) {
            $results[] = [
                'param_id' => $paramId,
                'pgu_id' => 2,
                'value' => round($gValue, $decimalPlaces),
                'shift_id' => $periodType === 'shift' ? $shiftId : null
            ];
        }
        
        // ПГУ 1+2 (H)
        if ($hValue !== null) {
            $results[] = [
                'param_id' => $paramId,
                'pgu_id' => 3,
                'value' => round($hValue, $decimalPlaces),
                'shift_id' => $periodType === 'shift' ? $shiftId : null
            ];
        }
    }
    
    // НЕ сохраняем здесь - сохраняем только в calculatePguResults
    // if (!empty($results)) {
    //     $saveData = [
    //         'date' => $date,
    //         'periodType' => $periodType,
    //         'values' => $results
    //     ];
    //     
    //     if ($periodType === 'period') {
    //         $saveData['period_start'] = $periodStart;
    //         $saveData['period_end'] = $periodEnd;
    //     }
    //     
    //     savePguResultValues($saveData);
    // }
    
    // Save special cells
    saveSpecialCellsToPguFullParams($inputData, $date ?? $periodStart, $shiftId);
    
    return $results;
}

// =============================================================================
// CALCULATION FUNCTIONS (Row-based)
// =============================================================================

/**
 * Row 47: Nпгубр = F12/F16, G12/G16
 */
function calculateRow47($inputData) {
    $f12 = resolveCellValue($inputData, 'F12');
    $f16 = resolveCellValue($inputData, 'F16');
    $g12 = resolveCellValue($inputData, 'G12');
    $g16 = resolveCellValue($inputData, 'G16');
    $h16 = ($f16 + $g16)/2;

    $f47 = ($f12 !== null && $f16 !== null && $f16 != 0) ? ($f12 / $f16) : null;
    $g47 = ($g12 !== null && $g16 !== null && $g16 != 0) ? ($g12 / $g16) : null;
    $h47 = null;
    
    if ($f47 !== null && $g47 !== null && $f16 !== null && $g16 !== null && $h16 !== null && $h16 != 0) {
        $h47 = ($f47 * $f16 + $g47 * $g16) / $h16;
    }
    
    return [$f47, $g47, $h47];
}

/**
 * Row 48: Nпгун = F13/F16, G13/G16
 */
function calculateRow48($inputData) {
    $f13 = resolveCellValue($inputData, 'F13');
    $f16 = resolveCellValue($inputData, 'F16');
    $g13 = resolveCellValue($inputData, 'G13');
    $g16 = resolveCellValue($inputData, 'G16');
    $h16 = ($f16 + $g16)/2;

    $f48 = ($f13 !== null && $f16 !== null && $f16 != 0) ? ($f13 / $f16) : null;
    $g48 = ($g13 !== null && $g16 !== null && $g16 != 0) ? ($g13 / $g16) : null;
    $h48 = null;
    
    if ($f48 !== null && $g48 !== null && $f16 !== null && $g16 !== null && $h16 !== null && $h16 != 0) {
        $h48 = ($f48 * $f16 + $g48 * $g16) / $h16;
    }
    
    return [$f48, $g48, $h48];
}

/**
 * Row 49: Nгтун = GT_Output/F16
 */
function calculateRow49($inputData) {
    $ctx = $inputData['_context'] ?? [];
    
    $gtOutput1 = getEquipmentEnergyOutput(3, $ctx); // ГТ1
    $gtOutput2 = getEquipmentEnergyOutput(5, $ctx); // ГТ2
    $f16 = resolveCellValue($inputData, 'F16');
    $g16 = resolveCellValue($inputData, 'G16');
    $h16 = ($f16 + $g16)/2;

    $f49 = ($gtOutput1 !== null && $f16 !== null && $f16 != 0) ? ($gtOutput1 / $f16) : null;
    $g49 = ($gtOutput2 !== null && $g16 !== null && $g16 != 0) ? ($gtOutput2 / $g16) : null;
    $h49 = null;
    
    if ($f49 !== null && $g49 !== null && $f16 !== null && $g16 !== null && $h16 !== null && $h16 != 0) {
        $h49 = ($f49 * $f16 + $g49 * $g16) / $h16;
    }
    
    return [$f49, $g49, $h49];
}

/**
 * Row 50: Nптун = PT_Output/F16
 */
function calculateRow50($inputData) {
    $ctx = $inputData['_context'] ?? [];
    
    $ptOutput1 = getEquipmentEnergyOutput(4, $ctx); // ПТ1
    $ptOutput2 = getEquipmentEnergyOutput(6, $ctx); // ПТ2
    $f16 = resolveCellValue($inputData, 'F16');
    $g16 = resolveCellValue($inputData, 'G16');
    $h16 = ($f16 + $g16)/2;

    $f50 = ($ptOutput1 !== null && $f16 !== null && $f16 != 0) ? ($ptOutput1 / $f16) : null;
    $g50 = ($ptOutput2 !== null && $g16 !== null && $g16 != 0) ? ($ptOutput2 / $g16) : null;
    $h50 = null;
    
    if ($f50 !== null && $g50 !== null && $f16 !== null && $g16 !== null && $h16 !== null && $h16 != 0) {
        $h50 = ($f16 * $f50 + $g50 * $g16) / $h16;
    }
    
    return [$f50, $g50, $h50];
}

/**
 * Row 51: Complex piecewise function based on F48/G48
 */
function calculateRow51($inputData) {
    [$f48, $g48, $h48] = calculateRow48($inputData);
    
    $f13 = resolveCellValue($inputData, 'F13');
    $g13 = resolveCellValue($inputData, 'G13'); 
    $h13 = $f13 + $g13;

    $f51 = calculateComplexFunction51($f48);
    $g51 = calculateComplexFunction51($g48);
    $h51 = null;
    
    if ($f51 !== null && $g51 !== null && $f13 !== null && $g13 !== null && $h13 !== null && $h13 != 0) {
        $h51 = ($f51 * $f13 + $g13 * $g51) / $h13;
    }
    
    return [$f51, $g51, $h51];
}

/**
 * Row 52: Впгуф = F41*F31/7000
 */
function calculateRow52($inputData) {
    $f41 = resolveCellValue($inputData, 'F41');
    $f31 = resolveCellValue($inputData, 'F31');
    $g41 = resolveCellValue($inputData, 'G41');
    $g31 = resolveCellValue($inputData, 'G31');

    $f52 = ($f41 !== null && $f31 !== null) ? ($f41 * $f31 / 7000) : null;
    $g52 = ($g41 !== null && $g31 !== null) ? ($g41 * $g31 / 7000) : null;
    $h52 = null;
    
    if ($f52 !== null && $g52 !== null) {
        $h52 = $f52 + $g52;
    }
    
    return [$f52, $g52, $h52];
}

/**
 * Row 54: на изменение cosφГТ, MW
 */
function calculateRow54($inputData) {
    $f27 = resolveCellValue($inputData, 'F27');
    $g27 = resolveCellValue($inputData, 'G27');
    [$f49, $g49] = calculateRow49($inputData);

    $f54 = calcDeltaNCosPhiPiecewise($f27, $f49);
    $g54 = calcDeltaNCosPhiPiecewise($g27, $g49);

    return [$f54, $g54];
}

/**
 * Row 55: на изменение cosφПТ, MW
 */
function calculateRow55($inputData) {
    $f29 = resolveCellValue($inputData, 'F29');
    $g29 = resolveCellValue($inputData, 'G29');
    [$f50, $g50] = calculateRow50($inputData);

    $f55 = calcDeltaNCosPhiPT($f29, $f50); // Используем отдельную функцию для ПТ
    $g55 = calcDeltaNCosPhiPT($g29, $g50);
    
    return [$f55, $g55];
}

/**
 * Row 56: на отпуск тепла на блоки, MW
 */
function calculateRow56($inputData) {
    // F56 = -(E7/D5*1.162)/37.2*5.68
    // где D5 = F16 (часы работы), E7 = отпуск тепла на блоки из parameter_values
    
    $f16 = resolveCellValue($inputData, 'F16'); // Часы работы ПГУ (D5)
    $g16 = resolveCellValue($inputData, 'G16');
    
    // Получаем отпуск тепла на блоки из parameter_values
    $context = $inputData['_context'] ?? [];
    $f56 = calculateRow56Value($f16, 1, $context); // ПГУ1
    $g56 = calculateRow56Value($g16, 2, $context); // ПГУ2
    
    return [$f56, $g56];
}

/**
 * Row 57: на изменение температуры мокрого термометра в градирню, MW
 */
function calculateRow57($inputData) {
    $f26 = resolveCellValue($inputData, 'F26');
    $g26 = resolveCellValue($inputData, 'G26');

    // F57 = (3.026764/10^4*F26^5 - 2.797998/10^2*F26^4 + 7.589394/10*F26^3 - 1.221105*F26^2 + 1.813793*10^2*F26 - 2.258971*10^3)/1000
    $f57 = null;
    if ($f26 !== null) {
        $f57 = (3.026764e-4 * pow($f26, 5) - 2.797998e-2 * pow($f26, 4) + 7.589394e-1 * pow($f26, 3) - 1.221105 * pow($f26, 2) + 1.813793e2 * $f26 - 2.258971e3) / 1000;
    }

    // G57 = (3.026764/10^4*G26^5 - 2.797998/10^2*G26^4 + 7.589394/10*G26^3 - 1.221105*G26^2 + 1.813793*10^2*G26 - 2.258971*10^3)/1000
    $g57 = null;
    if ($g26 !== null) {
        $g57 = (3.026764e-4 * pow($g26, 5) - 2.797998e-2 * pow($g26, 4) + 7.589394e-1 * pow($g26, 3) - 1.221105 * pow($g26, 2) + 1.813793e2 * $g26 - 2.258971e3) / 1000;
    }
    
    return [$f57, $g57];
}

/**
 * Row 58: на давление топливного газа, MW
 */
function calculateRow58($inputData) {
    $f32 = resolveCellValue($inputData, 'F32');
    $g32 = resolveCellValue($inputData, 'G32');

    // F58 = (1.424548/10^1*F32^4 - 9.736232*F32^3 + 2.462609*10^2*F32^2 - 2.784802*10^3*F32 + 1.170906*10^4)/1000
    $f58 = null;
    if ($f32 !== null) {
        $f58 = (1.424548e-1 * pow($f32, 4) - 9.736232 * pow($f32, 3) + 2.462609e2 * pow($f32, 2) - 2.784802e3 * $f32 + 1.170906e4) / 1000;
    }

    // G58 = (1.424548/10^1*G32^4 - 9.736232*G32^3 + 2.462609*10^2*G32^2 - 2.784802*10^3*G32 + 1.170906*10^4)/1000
    $g58 = null;
    if ($g32 !== null) {
        $g58 = (1.424548e-1 * pow($g32, 4) - 9.736232 * pow($g32, 3) + 2.462609e2 * pow($g32, 2) - 2.784802e3 * $g32 + 1.170906e4) / 1000;
    }
    
    return [$f58, $g58];
}

/**
 * Row 59: на температуру топливного газа, MW
 */
function calculateRow59($inputData) {
    $f33 = resolveCellValue($inputData, 'F33');
    $g33 = resolveCellValue($inputData, 'G33');

    // F59 = (-4.613095/10^5*F33^4 + 3.229167/10^3*F33^3 - 5.074405/10^2*F33^2 + 4.827083/10^0*F33^1 - 4.596429*10)/1000
    $f59 = null;
    if ($f33 !== null) {
        $f59 = (-4.613095e-5 * pow($f33, 4) + 3.229167e-3 * pow($f33, 3) - 5.074405e-2 * pow($f33, 2) + 4.827083e-0 * $f33 - 4.596429e1) / 1000;
    }

    // G59 = (-4.613095/10^5*G33^4 + 3.229167/10^3*G33^3 - 5.074405/10^2*G33^2 + 4.827083/10^0*G33^1 - 4.596429*10)/1000
    $g59 = null;
    if ($g33 !== null) {
        $g59 = (-4.613095e-5 * pow($g33, 4) + 3.229167e-3 * pow($g33, 3) - 5.074405e-2 * pow($g33, 2) + 4.827083e-0 * $g33 - 4.596429e1) / 1000;
    }
    
    return [$f59, $g59];
}

/**
 * Row 60: на температуру окружающей среды и относительную влажность (при Вкл испар. охл.), MW
 */
function calculateRow60($inputData) {
    $f22 = resolveCellValue($inputData, 'F22'); // Состояние испарителя (1=вкл, 0=выкл)
    $f20 = resolveCellValue($inputData, 'F20'); // Температура окружающей среды
    $f24 = resolveCellValue($inputData, 'F24'); // Относительная влажность
    $f18 = resolveCellValue($inputData, 'F18'); // Часы с испарителем
    $f16 = resolveCellValue($inputData, 'F16'); // Часы работы ПГУ
    
    $g22 = resolveCellValue($inputData, 'G22');
    $g20 = resolveCellValue($inputData, 'G20');
    $g24 = resolveCellValue($inputData, 'G24');
    $g18 = resolveCellValue($inputData, 'G18');
    $g16 = resolveCellValue($inputData, 'G16');

    $f60 = calculateRow60Value($f22, $f20, $f24, $f18, $f16);
    $g60 = calculateRow60Value($g22, $g20, $g24, $g18, $g16);
    
    return [$f60, $g60];
}

/**
 * Row 61: на температуру на входе компрессора (при Откл. испар. охл.), MW
 */
function calculateRow61($inputData) {
    $f22 = resolveCellValue($inputData, 'F22'); // Состояние испарителя (1=вкл, 0=выкл)
    $f21 = resolveCellValue($inputData, 'F21'); // Температура на входе компрессора
    $f16 = resolveCellValue($inputData, 'F16'); // Часы работы ПГУ
    $f18 = resolveCellValue($inputData, 'F18'); // Часы с испарителем
    
    $g22 = resolveCellValue($inputData, 'G22');
    $g21 = resolveCellValue($inputData, 'G21');
    $g16 = resolveCellValue($inputData, 'G16');
    $g18 = resolveCellValue($inputData, 'G18');

    $f61 = calculateRow61Value($f22, $f21, $f16, $f18);
    $g61 = calculateRow61Value($g22, $g21, $g16, $g18);
    
    return [$f61, $g61];
}

/**
 * Row 62: на изменение барометрического давления и температуры на входе компрессора, MW
 */
function calculateRow62($inputData) {
    $f22 = resolveCellValue($inputData, 'F22'); // Состояние испарителя (1=вкл, 0=выкл)
    $f20 = resolveCellValue($inputData, 'F20'); // Температура окружающей среды
    $f21 = resolveCellValue($inputData, 'F21'); // Температура на входе компрессора
    $f23 = resolveCellValue($inputData, 'F23'); // Барометрическое давление
    $f16 = resolveCellValue($inputData, 'F16'); // Часы работы ПГУ
    $f18 = resolveCellValue($inputData, 'F18'); // Часы с испарителем
    
    $g22 = resolveCellValue($inputData, 'G22');
    $g20 = resolveCellValue($inputData, 'G20');
    $g21 = resolveCellValue($inputData, 'G21');
    $g23 = resolveCellValue($inputData, 'G23');
    $g16 = resolveCellValue($inputData, 'G16');
    $g18 = resolveCellValue($inputData, 'G18');

    $f62 = calculateRow62Value($f22, $f20, $f21, $f23, $f16, $f18);
    $g62 = calculateRow62Value($g22, $g20, $g21, $g23, $g16, $g18);
    
    return [$f62, $g62];
}

/**
 * Row 63: на относительную влажность и температуру на входе компрессора (при Откл. испар. охл.), MW
 */
function calculateRow63($inputData) {
    $f22 = resolveCellValue($inputData, 'F22'); // Состояние испарителя (1=вкл, 0=выкл)
    $f21 = resolveCellValue($inputData, 'F21'); // Температура на входе компрессора
    $f25 = resolveCellValue($inputData, 'F25'); // Относительная влажность
    $f16 = resolveCellValue($inputData, 'F16'); // Часы работы ПГУ
    $f18 = resolveCellValue($inputData, 'F18'); // Часы с испарителем
    
    $g22 = resolveCellValue($inputData, 'G22');
    $g21 = resolveCellValue($inputData, 'G21');
    $g25 = resolveCellValue($inputData, 'G25');
    $g16 = resolveCellValue($inputData, 'G16');
    $g18 = resolveCellValue($inputData, 'G18');

    $f63 = calculateRow63Value($f22, $f21, $f25, $f16, $f18);
    $g63 = calculateRow63Value($g22, $g21, $g25, $g16, $g18);
    
    return [$f63, $g63];
}

/**
 * Row 64: на отклонение низшей теплоты сгорания топлива, MW
 */
function calculateRow64($inputData) {
    $f37 = resolveCellValue($inputData, 'F37'); // Низшая теплота сгорания топлива
    if ($f37 === null) {
        $f37 = 0;
    }
    $f30 = resolveCellValue($inputData, 'F30'); // Параметр для расчета
    
    $g37 = resolveCellValue($inputData, 'G37');
    if ($g37 === null) {
        $g37 = 0;
    }
    $g30 = resolveCellValue($inputData, 'G30');

    $f64 = calculateRow64Value($f37, $f30);
    $g64 = calculateRow64Value($g37, $g30);
    
    return [$f64, $g64];
}

/**
 * Row 65: на изменение частоты ГТ (при Вкл и Откл испар. охл.), MW
 */
function calculateRow65($inputData) {
    $f22 = resolveCellValue($inputData, 'F22'); // Состояние испарителя (1=вкл, 0=выкл)
    $f20 = resolveCellValue($inputData, 'F20'); // Температура окружающей среды
    $f21 = resolveCellValue($inputData, 'F21'); // Температура на входе компрессора
    $f28 = resolveCellValue($inputData, 'F28'); // Частота ГТ
    $f16 = resolveCellValue($inputData, 'F16'); // Часы работы ПГУ
    $f18 = resolveCellValue($inputData, 'F18'); // Часы с испарителем
    
    $g22 = resolveCellValue($inputData, 'G22');
    $g20 = resolveCellValue($inputData, 'G20');
    $g21 = resolveCellValue($inputData, 'G21');
    $g28 = resolveCellValue($inputData, 'G28');
    $g16 = resolveCellValue($inputData, 'G16');
    $g18 = resolveCellValue($inputData, 'G18');

    $f65 = calculateRow65Value($f22, $f20, $f21, $f28, $f16, $f18);
    $g65 = calculateRow65Value($g22, $g20, $g21, $g28, $g16, $g18);
    
    return [$f65, $g65];
}

/**
 * Row 66: Кривая деградации ГТУ, MW
 */
function calculateRow66($inputData) {
    $f39 = round(resolveCellValue($inputData, 'F39'), 2); // Эквивалентные часы с начала эксплуатации
    $g39 = round(resolveCellValue($inputData, 'G39'), 2);
    $f66 = calculateRow66Value($f39);
    $g66 = calculateRow66Value($g39);
    
    return [$f66, $g66];
}

/**
 * Row 67: Кривая деградации ГТУ, MW
 */
function calculateRow67($inputData) {
    [$f49, $g49] = calculateRow49($inputData); // Электрическая нагрузка ГТУ, MW, нетто
    [$f66, $g66] = calculateRow66($inputData); // Кривая деградации ГТУ
    $f67 = calculateRow67Value($f66, $f49);
    $g67 = calculateRow67Value($g66, $g49);
    
    return [$f67, $g67];
}

/**
 * Row 68: на занос КВОУ, MW
 */
function calculateRow68($inputData) {
    $f35 = resolveCellValue($inputData, 'F35'); // Занос КВОУ
    
    $g35 = resolveCellValue($inputData, 'G35');

    $f68 = calculateRow68Value($f35);
    $g68 = calculateRow68Value($g35);
    
    return [$f68, $g68];
}

/**
 * Row 69: на включение антиобледенительной системы (АОС), MW
 */
function calculateRow69($inputData) {
    $f36 = resolveCellValue($inputData, 'F36'); // Включение АОС
    $f17 = resolveCellValue($inputData, 'F17'); // Часы с АОС
    $f16 = resolveCellValue($inputData, 'F16'); // Часы работы ПГУ
    
    $g36 = resolveCellValue($inputData, 'G36');
    $g17 = resolveCellValue($inputData, 'G17');
    $g16 = resolveCellValue($inputData, 'G16');

    $f69 = calculateRow69Value($f36, $f17, $f16);
    $g69 = calculateRow69Value($g36, $g17, $g16);
    
    return [$f69, $g69];
}

/**
 * Row 70: Расчетная мощность, приведённая к фактическим внешним условиям ПГУ, MW
 */
function calculateRow70($inputData) {
    // Получаем все необходимые значения из предыдущих расчетов
    [$f49, $g49] = calculateRow49($inputData); // Электрическая нагрузка ГТУ, MW, нетто
    [$f50, $g50] = calculateRow50($inputData); // Электрическая нагрузка ПТУ, MW, нетто
    [$f54, $g54] = calculateRow54($inputData); // на изменение cosφГТ, MW
    [$f55, $g55] = calculateRow55($inputData); // на изменение cosφПТ, MW
    [$f56, $g56] = calculateRow56($inputData); // на отпуск тепла на блоки, MW
    [$f57, $g57] = calculateRow57($inputData); // на изменение температуры мокрого термометра в градирню, MW
    [$f58, $g58] = calculateRow58($inputData); // на давление топливного газа, MW
    [$f59, $g59] = calculateRow59($inputData); // на температуру топливного газа, MW
    [$f60, $g60] = calculateRow60($inputData); // на температуру окружающей среды и относительную влажность
    [$f61, $g61] = calculateRow61($inputData); // на температуру на входе компрессора
    [$f62, $g62] = calculateRow62($inputData); // на изменение барометрического давления и температуры
    [$f63, $g63] = calculateRow63($inputData); // на относительную влажность и температуру на входе компрессора
    [$f64, $g64] = calculateRow64($inputData); // на отклонение низшей теплоты сгорания топлива
    [$f65, $g65] = calculateRow65($inputData); // на изменение частоты ГТ
    [$f67, $g67] = calculateRow67($inputData); // Кривая деградации ГТУ, MW
    [$f68, $g68] = calculateRow68($inputData); // на занос КВОУ
    [$f69, $g69] = calculateRow69($inputData); // на включение антиобледенительной системы (АОС)
    
    // Получаем базовые значения
    $f16 = resolveCellValue($inputData, 'F16'); // Часы работы ПГУ
    $g16 = resolveCellValue($inputData, 'G16');
    $h16 = ($f16 + $g16) / 2;

    // F70 = ((F49*F60*F61*F62*F63*F64*F65*F68*F69)+F54+F58+F59+F67)+(F50+F55+F57+F56)
    $f70 = null;
    if ($f49 !== null && $f60 !== null && $f61 !== null && $f62 !== null && $f63 !== null && 
        $f64 !== null && $f65 !== null && $f68 !== null && $f69 !== null && $f54 !== null && 
        $f58 !== null && $f59 !== null && $f67 !== null && $f50 !== null && $f55 !== null && 
        $f57 !== null && $f56 !== null) {
        $f70 = (($f49 * $f60 * $f61 * $f62 * $f63 * $f64 * $f65 * $f68 * $f69) + $f54 + $f58 + $f59 + $f67) + ($f50 + $f55 + $f57 + $f56);
    }

    // G70 = ((G49*G60*G61*G62*G63*G64*G65*G68*G69)+G54+G58+G59+G67)+(G50+G55+G57)
    $g70 = null;
    if ($g49 !== null && $g60 !== null && $g61 !== null && $g62 !== null && $g63 !== null && 
        $g64 !== null && $g65 !== null && $g68 !== null && $g69 !== null && $g54 !== null && 
        $g58 !== null && $g59 !== null && $g67 !== null && $g50 !== null && $g55 !== null && 
        $g57 !== null) {
        $g70 = (($g49 * $g60 * $g61 * $g62 * $g63 * $g64 * $g65 * $g68 * $g69) + $g54 + $g58 + $g59 + $g67) + ($g50 + $g55 + $g57);
    }

    // H70 = (F16*F70+G70*G16)/H16
    $h70 = null;
    if ($f16 !== null && $f70 !== null && $g70 !== null && $g16 !== null && $h16 !== null && $h16 != 0) {
        $h70 = ($f16 * $f70 + $g70 * $g16) / $h16;
    }
    
    return [$f70, $g70, $h70];
}

/**
 * Row 71: Изменение мощности ПГУ на внешние факторы, MW
 */
function calculateRow71($inputData) {
    // F71 = F70 - F48
    // G71 = G70 - G48  
    // H71 = (F16*F71 + G71*G16)/H16
    
    [$f70, $g70, $h70] = calculateRow70($inputData);
    [$f48, $g48] = calculateRow48($inputData);
    $f16 = resolveCellValue($inputData, 'F16');
    $g16 = resolveCellValue($inputData, 'G16');
    $h16 = ($f16 + $g16) / 2;
    
    $f71 = ($f70 !== null && $f48 !== null) ? $f70 - $f48 : null;
    $g71 = ($g70 !== null && $g48 !== null) ? $g70 - $g48 : null;
    
    // H71 = (F16*F71 + G71*G16)/H16
    $h71 = null;
    if ($f16 !== null && $f71 !== null && $g71 !== null && $g16 !== null && $h16 !== null && $h16 != 0) {
        $h71 = ($f16 * $f71 + $g71 * $g16) / $h16;
    }
    
    return [$f71, $g71, $h71];
}

/**
 * Row 72: Расход топлива на изменение мощности за счёт изменения внешних факторов, т.усл.топл.
 */
function calculateRow72($inputData) {
    // F72 = ЕСЛИ(-0,001<F71<0,001;0;F71*F51/1000*F16)
    // G72 = ЕСЛИ(-0,001<G71<0,001;0;G71*G51/1000*G16)
    // H72 = F72+G72
    
    [$f71, $g71] = calculateRow71($inputData);
    [$f51, $g51] = calculateRow51($inputData);
    $f16 = resolveCellValue($inputData, 'F16');
    $g16 = resolveCellValue($inputData, 'G16');
    
    // F72: IF(-0.001 < F71 < 0.001; 0; F71*F51/1000*F16)
    $f72 = null;
    if ($f71 !== null) {
        if ($f71 > -0.001 && $f71 < 0.001) {
            $f72 = 0;
        } elseif ($f51 !== null && $f16 !== null) {
            $f72 = $f71 * $f51 / 1000 * $f16;
        }
    }
    
    // G72: IF(-0.001 < G71 < 0.001; 0; G71*G51/1000*G16)
    $g72 = null;
    if ($g71 !== null) {
        if ($g71 > -0.001 && $g71 < 0.001) {
            $g72 = 0;
        } elseif ($g51 !== null && $g16 !== null) {
            $g72 = $g71 * $g51 / 1000 * $g16;
        }
    }
    
    // H72 = F72 + G72
    $h72 = null;
    if ($f72 !== null && $g72 !== null) {
        $h72 = $f72 + $g72;
    }
    
    return [$f72, $g72, $h72];
}

/**
 * Row 73: Исходно-номинальный расход топлива на ПГУ по энергетическим характеристикам, т.усл.топл.
 */
function calculateRow73($inputData) {
    // F73 = F51*F13/1000
    // G73 = G51*G13/1000
    // H73 = F73+G73
    
    [$f51, $g51] = calculateRow51($inputData);
    $f13 = resolveCellValue($inputData, 'F13');
    $g13 = resolveCellValue($inputData, 'G13');
    
    $f73 = ($f51 !== null && $f13 !== null) ? $f51 * $f13 / 1000 : null;
    $g73 = ($g51 !== null && $g13 !== null) ? $g51 * $g13 / 1000 : null;
    
    // H73 = F73 + G73
    $h73 = null;
    if ($f73 !== null && $g73 !== null) {
        $h73 = $f73 + $g73;
    }
    
    return [$f73, $g73, $h73];
}

/**
 * Row 74: Расход топлива на тепловую нагрузку, т.усл.топл.
 */
function calculateRow74($inputData) {
    // F74 = 'Исх данные ПГУ'!E6*0,161
    // G74 = 'Исх данные ПГУ'!H6*0,161
    // H74 = F74+G74
    
    // E6 и H6 - это отпуск тепла на теплоцентраль (parameter_id = 11)
    $context = $inputData['_context'] ?? [];
    $f74 = calculateRow74Value(1, $context); // ПГУ1
    $g74 = calculateRow74Value(2, $context); // ПГУ2
    
    // H74 = F74 + G74
    $h74 = null;
    if ($f74 !== null && $g74 !== null) {
        $h74 = $f74 + $g74;
    }
    
    return [$f74, $g74, $h74];
}

/**
 * Row 75: Расход топлива на ПГУ с учётом влияния внешних факторов и тепловой нагрузки, т.усл.топл.
 */
function calculateRow75($inputData) {
    // F75 = F73+F72+F74
    // G75 = G73+G72+(G14*(0,161+G79*0,02*G51/1000/7))
    // H75 = F75+G75
    
    [$f73, $g73] = calculateRow73($inputData);
    [$f72, $g72] = calculateRow72($inputData);
    [$f74, $g74] = calculateRow74($inputData);
    $g14 = resolveCellValue($inputData, 'G14');
    [$g79, $g79] = calculateRow79($inputData);
    [$g51, $g51] = calculateRow51($inputData);
    
    // F75 = F73+F72+F74
    $f75 = null;
    if ($f73 !== null && $f72 !== null && $f74 !== null) {
        $f75 = $f73 + $f72 + $f74;
    }
    
    // G75 = G73+G72+(G14*(0,161+G79*0,02*G51/1000/7))
    $g75 = null;
    if ($g73 !== null && $g72 !== null && $g14 !== null && $g79 !== null && $g51 !== null) {
        $additionalTerm = $g14 * (0.161 + $g79 * 0.02 * $g51 / 1000 / 7);
        $g75 = $g73 + $g72 + $additionalTerm;
    }
    
    // H75 = F75 + G75
    $h75 = null;
    if ($f75 !== null && $g75 !== null) {
        $h75 = $f75 + $g75;
    }
    
    return [$f75, $g75, $h75];
}

/**
 * Row 76: Мощность тепла топлива ПГУ, MW
 */
function calculateRow76($inputData) {
    // F76 = F75*7000/860/F16
    // G76 = G75*7000/860/G16  
    // H76 = F76+G76
    
    [$f75, $g75] = calculateRow75($inputData);
    $f16 = resolveCellValue($inputData, 'F16');
    $g16 = resolveCellValue($inputData, 'G16');
    
    $f76 = ($f75 !== null && $f16 !== null && $f16 != 0) ? $f75 * 7000 / 860 / $f16 : null;
    $g76 = ($g75 !== null && $g16 !== null && $g16 != 0) ? $g75 * 7000 / 860 / $g16 : null;
    
    // H76 = F76 + G76
    $h76 = null;
    if ($f76 !== null && $g76 !== null) {
        $h76 = $f76 + $g76;
    }
    
    return [$f76, $g76, $h76];
}

/**
 * Row 77: КПД нетто ПГУ (установки), %
 */
function calculateRow77($inputData) {
    // F77 = F70/F76*100
    // G77 = G70/G76*100
    // H77 = H70/H76*100
    
    [$f70, $g70, $h70] = calculateRow70($inputData);
    [$f76, $g76, $h76] = calculateRow76($inputData);
    
    $f77 = ($f70 !== null && $f76 !== null && $f76 != 0) ? $f70 / $f76 * 100 : null;
    $g77 = ($g70 !== null && $g76 !== null && $g76 != 0) ? $g70 / $g76 * 100 : null;
    $h77 = ($h70 !== null && $h76 !== null && $h76 != 0) ? $h70 / $h76 * 100 : null;
    
    return [$f77, $g77, $h77];
}

/**
 * Row 78: Электрические СН ПГУ, %
 */
function calculateRow78($inputData) {
    // F78 = -5,296004/10^11*F70^5+5,46007318/10^8*F70^4-2,1963935287/10^5*F70^3+4,362720319298/10^3*F70^2-4,45893614984655/10*F70+2,292438590867*10
    // G78 = -5,296004/10^11*G70^5+5,46007318/10^8*G70^4-2,1963935287/10^5*G70^3+4,362720319298/10^3*G70^2-4,45893614984655/10*G70+2,292438590867*10
    // H78 = -5,296004/10^11*(H70/2)^5+5,46007318/10^8*(H70/2)^4-2,1963935287/10^5*(H70/2)^3+4,362720319298/10^3*(H70/2)^2-4,45893614984655/10*(H70/2)+2,292438590867*10
    
    [$f70, $g70, $h70] = calculateRow70($inputData);
    
    $f78 = ($f70 !== null) ? calculateRow78Polynomial($f70) : null;
    $g78 = ($g70 !== null) ? calculateRow78Polynomial($g70) : null;
    $h78 = ($h70 !== null) ? calculateRow78Polynomial($h70 / 2) : null;
    
    return [$f78, $g78, $h78];
}

/**
 * Row 79: Электрические СН ПГУ, MW
 */
function calculateRow79($inputData) {
    // F79 = F78*F70/100
    // G79 = G78*G70/100
    // H79 = F79+G79
    
    [$f78, $g78] = calculateRow78($inputData);
    [$f70, $g70] = calculateRow70($inputData);
    
    $f79 = ($f78 !== null && $f70 !== null) ? $f78 * $f70 / 100 : null;
    $g79 = ($g78 !== null && $g70 !== null) ? $g78 * $g70 / 100 : null;
    
    // H79 = F79 + G79
    $h79 = null;
    if ($f79 !== null && $g79 !== null) {
        $h79 = $f79 + $g79;
    }
    
    return [$f79, $g79, $h79];
}

/**
 * Row 80: Номинальный удельный расход топлива на отпуск электроэнергии, приведённый к внешним факторам, g/kWh
 */
function calculateRow80($inputData) {
    // F80 = 0,86*100000/(F77*7)
    // G80 = 0,86*100000/(G77*7)
    // H80 = 0,86*100000/(H77*7)
    
    [$f77, $g77, $h77] = calculateRow77($inputData);
    
    $f80 = ($f77 !== null && $f77 != 0) ? 0.86 * 100000 / ($f77 * 7) : null;
    $g80 = ($g77 !== null && $g77 != 0) ? 0.86 * 100000 / ($g77 * 7) : null;
    $h80 = ($h77 !== null && $h77 != 0) ? 0.86 * 100000 / ($h77 * 7) : null;
    
    return [$f80, $g80, $h80];
}

/**
 * Row 81: Затраты топлива на пуски ПГУ по диспетчерскому графику, g/kWh
 */
function calculateRow81($inputData) {
    // F81 = ((180*F31/7-390*0,24*1000)*'Исх данные ПГУ'!D10+(158*F31/7-365*0,24*1000)*'Исх данные ПГУ'!D11+(60*F31/7-175*0,24*1000)*'Исх данные ПГУ'!D12)/F13
    // G81 = ((180*G31/7-390*0,24*1000)*'Исх данные ПГУ'!G10+(158*G31/7-365*0,24*1000)*'Исх данные ПГУ'!G11+(60*G31/7-175*0,24*1000)*'Исх данные ПГУ'!G12)/G13
    // H81 = G81+F81
    
    $f31 = resolveCellValue($inputData, 'F31');
    $g31 = resolveCellValue($inputData, 'G31');
    $f13 = resolveCellValue($inputData, 'F13');
    $g13 = resolveCellValue($inputData, 'G13');
    
    $context = $inputData['_context'] ?? [];
    
    // Получаем данные D10, D11, D12 для ПГУ1 и G10, G11, G12 для ПГУ2
    $f81 = calculateRow81Value($f31, $f13, 1, $context); // ПГУ1
    $g81 = calculateRow81Value($g31, $g13, 2, $context); // ПГУ2
    
    // H81 = G81 + F81
    $h81 = null;
    if ($f81 !== null && $g81 !== null) {
        $h81 = $f81 + $g81;
    }
    
    return [$f81, $g81, $h81];
}

/**
 * Row 82: Номинальный удельный расход топлива на отпуск электроэнергии с учётом пусков, g/kWh
 */
function calculateRow82($inputData) {
    // F82 = F81+F80
    // G82 = G81+G80
    // H82 = H81+H80
    
    [$f81, $g81, $h81] = calculateRow81($inputData);
    [$f80, $g80, $h80] = calculateRow80($inputData);
    
    $f82 = ($f81 !== null && $f80 !== null) ? $f81 + $f80 : null;
    $g82 = ($g81 !== null && $g80 !== null) ? $g81 + $g80 : null;
    $h82 = ($h81 !== null && $h80 !== null) ? $h81 + $h80 : null;
    
    return [$f82, $g82, $h82];
}

/**
 * Row 83: Фактический удельный расход топлива на отпуск электроэнергии с учётом пусков, g/kWh
 */
function calculateRow83($inputData) {
    // F83 = (F42-F74)/F13*1000
    // G83 = (G42-G74)/G13*1000
    // H83 = (H42-H74)/H13*1000
    
    $f42 = resolveCellValue($inputData, 'F42');
    $g42 = resolveCellValue($inputData, 'G42');
    $h42 = $f42 + $g42;
    [$f74, $g74, $h74] = calculateRow74($inputData);
    $f13 = resolveCellValue($inputData, 'F13');
    $g13 = resolveCellValue($inputData, 'G13');
    $h13 = $f13 + $g13;
    
    $f83 = ($f42 !== null && $f74 !== null && $f13 !== null && $f13 != 0) ? ($f42 - $f74) / $f13 * 1000 : null;
    $g83 = ($g42 !== null && $g74 !== null && $g13 !== null && $g13 != 0) ? ($g42 - $g74) / $g13 * 1000 : null;
    $h83 = ($h42 !== null && $h74 !== null && $h13 !== null && $h13 != 0) ? ($h42 - $h74) / $h13 * 1000 : null;
    
    return [$f83, $g83, $h83];
}

/**
 * Row 84: Экономия (+)/пережог (-) топлива, g/kWh
 */
function calculateRow84($inputData) {
    // F84 = F82-F83
    // G84 = G82-G83
    // H84 = H82-H83
    
    [$f82, $g82, $h82] = calculateRow82($inputData);
    [$f83, $g83, $h83] = calculateRow83($inputData);
    
    $f84 = ($f82 !== null && $f83 !== null) ? $f82 - $f83 : null;
    $g84 = ($g82 !== null && $g83 !== null) ? $g82 - $g83 : null;
    $h84 = ($h82 !== null && $h83 !== null) ? $h82 - $h83 : null;
    
    return [$f84, $g84, $h84];
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Complex function for row 51 calculation
 */
function calculateComplexFunction51($value) {
    if ($value === null) return null;
    
    if ($value >= 212.31) {
        return -0.0000082402 * pow($value, 3) + 0.007231743 * pow($value, 2) - 2.1475024881 * $value + 448.5652770027;
    } else {
        return 742.3358617859 / pow($value, 0.2109403744);
    }
}

/**
 * Calculate Delta N cos phi piecewise function for row 54 (GT)
 */
function calcDeltaNCosPhiPiecewise($cosValue, $nValue) {
    if ($cosValue === null || $nValue === null) {
        return null;
    }
    
    if ($cosValue == 0.9) {
        return 0;
    }
    
    // Базовый полином (одинаковый для всех случаев)
    $basePolynomial = -1.274598e-8 * pow($nValue, 4) + 
                     2.972362e-5 * pow($nValue, 3) + 
                     3.359379e-3 * pow($nValue, 2) + 
                     2.359075 * $nValue + 
                     1.604836e3;
    
    if ($cosValue < 0.9) {
        // Первый полином для cosValue < 0.9
        $polynomial1 = -3.493055e-7 * pow($nValue, 4) + 
                      2.19192e-4 * pow($nValue, 3) - 
                      2.991165e-2 * pow($nValue, 2) + 
                      4.974629 * $nValue + 
                      1.555182e3;
        
        return ($polynomial1 - $basePolynomial) / 0.05 * (0.9 - $cosValue) / 1000;
    } elseif ($cosValue <= 0.95) {
        // Второй полином для 0.9 < cosValue <= 0.95
        $polynomial2 = -5.450546e-8 * pow($nValue, 4) + 
                      3.486201e-5 * pow($nValue, 3) + 
                      5.787735e-3 * pow($nValue, 2) + 
                      1.166651 * $nValue + 
                      1.657923e3;
        
        return ($polynomial2 - $basePolynomial) / 0.05 * ($cosValue - 0.9) / 1000;
    } else { // cosValue > 0.95
        // Третий полином для cosValue > 0.95
        $polynomial3 = 3.550269e-7 * pow($nValue, 4) - 
                      2.116611e-4 * pow($nValue, 3) + 
                      5.486279e-2 * pow($nValue, 2) - 
                      3.205089 * $nValue + 
                      1.755291e3;
        
        return ($polynomial3 - $basePolynomial) / 0.1 * ($cosValue - 0.9) / 1000;
    }
}

/**
 * Calculate Delta N cos phi for PT (row 55) - different coefficients than GT
 */
function calcDeltaNCosPhiPT($cosValue, $nValue) {
    if ($cosValue === null || $nValue === null) {
        return null;
    }
    
    if ($cosValue == 0.9) {
        return 0;
    }
    
    // Базовый полином (одинаковый для всех случаев)
    $basePolynomial = -2.713238e-5 * pow($nValue, 4) + 
                     6.839387e-3 * pow($nValue, 3) - 
                     5.839403e-1 * pow($nValue, 2) + 
                     2.49714e1 * $nValue + 
                     5.03691e2;
    
    if ($cosValue < 0.9) {
        // Первый полином для cosValue < 0.9
        $polynomial1 = -6.007189e-5 * pow($nValue, 4) + 
                      1.485491e-2 * pow($nValue, 3) - 
                      1.276215 * pow($nValue, 2) + 
                      5.054052e1 * $nValue + 
                      1.840042e2;
        
        return ($polynomial1 - $basePolynomial) / 0.05 * (0.9 - $cosValue) / 1000;
    } elseif ($cosValue <= 0.95) {
        // Второй полином для 0.9 < cosValue <= 0.95
        $polynomial2 = 5.807124e-6 * pow($nValue, 4) - 
                      1.176136e-3 * pow($nValue, 3) + 
                      1.083344e1 * pow($nValue, 2) - 
                      5.977259e1 * $nValue + 
                      8.233778e2;
        
        return ($polynomial2 - $basePolynomial) / 0.05 * ($cosValue - 0.9) / 1000;
    } else { // cosValue > 0.95
        // Третий полином для cosValue > 0.95
        $polynomial3 = 3.874663e-5 * pow($nValue, 4) - 
                      9.191659e-3 * pow($nValue, 3) + 
                      8.00609e-1 * pow($nValue, 2) - 
                      2.616685e1 * $nValue + 
                      1.143065e3;
        
        return ($polynomial3 - $basePolynomial) / 0.1 * ($cosValue - 0.9) / 1000;
    }
}

/**
 * Helper function for row 60 calculation
 */
function calculateRow60Value($evaporatorState, $temp, $humidity, $evaporatorHours, $totalHours) {
    $evaporatorState = (int)$evaporatorState;
    $temp = (float)$temp;
    $humidity = (float)$humidity;
    $evaporatorHours = (float)$evaporatorHours;
    $totalHours = (float)$totalHours;

    if ($evaporatorState !== 1) return 1;
    if ($totalHours == 0) return null;

    $result = 0;

    if ($humidity <= 10) {
        $result = -6.747442e-10 * $temp**5 + 9.937119e-8 * $temp**4 - 5.48286e-6 * $temp**3
                + 1.469636e-4 * $temp**2 + 3.156566e-4 * $temp + 9.699084e-1;
    } elseif ($humidity <= 20) {
        $result = 1.441597e-10 * $temp**5 - 2.131997e-8 * $temp**4 + 1.357151e-6 * $temp**3
                - 3.143155e-5 * $temp**2 + 2.653151e-3 * $temp + 9.599987e-1;
    } elseif ($humidity <= 40) {
        $result = 1.190276e-9 * $temp**5 - 1.673392e-7 * $temp**4 + 9.106626e-6 * $temp**3
                - 2.166697e-4 * $temp**2 + 5.012879e-3 * $temp + 9.515353e-1;
    } elseif ($humidity <= 58) {
        $result = -8.255738e-10 * $temp**5 + 2.03998e-7 * $temp**4 - 1.579106e-5 * $temp**3
                + 5.469624e-4 * $temp**2 - 5.077647e-3 * $temp + 1.001017;
    } elseif ($humidity <= 80) {
        $result = 7.879166e-9 * $temp**5 - 9.228572e-7 * $temp**4 + 4.052151e-5 * $temp**3
                - 7.922374e-4 * $temp**2 + 1.019137e-2 * $temp + 9.417196e-1;
    } elseif ($humidity <= 100) {
        $result = 3.404797e-9 * $temp**5 - 3.104972e-7 * $temp**4 + 1.051523e-5 * $temp**3
                - 1.297768e-4 * $temp**2 + 3.738357e-3 * $temp + 9.719307e-1;
    }

    return (($result - 1) * $evaporatorHours / $totalHours) + 1;
}

/**
 * Helper function for row 61 calculation
 */
function calculateRow61Value($evaporatorState, $compressorTemp, $totalHours, $evaporatorHours) {
    if ($evaporatorState !== 0) return 1; // Если испаритель включен
    
    if ($totalHours === null || $totalHours == 0) return null;
    
    // Полиномиальная функция для температуры на входе компрессора
    $result = 2.337825e-9 * pow($compressorTemp, 5) - 1.489873e-7 * pow($compressorTemp, 4) + 5.849849e-7 * pow($compressorTemp, 3) + 1.380716e-4 * pow($compressorTemp, 2) + 8.858796e-4 * $compressorTemp + 9.779048e1;
    
    return (($result - 1) * ($totalHours - $evaporatorHours) / $totalHours) + 1;
}

/**
 * Helper function for row 62 calculation
 */
function calculateRow62Value($evaporatorState, $ambientTemp, $compressorTemp, $pressure, $totalHours, $evaporatorHours) {
    $evaporatorState = (int)$evaporatorState;
    $ambientTemp = (float)$ambientTemp;
    $compressorTemp = (float)$compressorTemp;
    $pressure = (float)$pressure;
    $totalHours = (float)$totalHours;
    $evaporatorHours = (float)$evaporatorHours;

    // выбор температуры и доли времени — как в Excel
    if ($evaporatorState === 1) {
        $temp = $compressorTemp;            // F21
        $hoursPart = ($totalHours > 0) ? ($evaporatorHours / $totalHours) : 0;   // F18/F16
    } else {
        $temp = $ambientTemp;               // F20
        $hoursPart = ($totalHours > 0) ? (($totalHours - $evaporatorHours) / $totalHours) : 0; // (F16-F18)/F16
    }

    if ($totalHours == 0) return null;

    // полином по давлению в зависимости от temp
    if ($temp <= -3) {
        $result = 7.290788e-12*pow($pressure,4) - 3.050169e-8*pow($pressure,3) + 4.875852e-5*pow($pressure,2) - 3.615805e-2*$pressure + 1.161435e1;
    } elseif ($temp <= 9) {
        $result = 7.607963e-12*pow($pressure,4) - 3.189538e-8*pow($pressure,3) + 5.103426e-5*pow($pressure,2) - 3.779398e-2*$pressure + 1.205104e1;
    } elseif ($temp <= 10) {
        $result = 5.749487e-12*pow($pressure,4) - 2.438191e-8*pow($pressure,3) + 3.964932e-5*pow($pressure,2) - 3.013092e-2*$pressure + 1.011792e1;
    } elseif ($temp <= 33) {
        // ВАЖНО: последний член -4,032603/10 => -0.4032603 (а не e1!)
        $result = -4.349388e-12*pow($pressure,4) + 1.645545e-8*pow($pressure,3) - 2.2244e-5*pow($pressure,2) + 1.154508e-2*$pressure - 4.032603e-1;
    } elseif ($temp <= 45) {
        // ВАЖНО: последний член просто +3,647608 (а не e2!)
        $result = 4.61965e-14*pow($pressure,4) - 7.819627e-10*pow($pressure,3) + 3.086744e-6*pow($pressure,2) - 4.994672e-3*$pressure + 3.647608;
    } else {
        // на случай temp>45, как в Excel ветки нет — можно оставить последнюю
        $result = 3.647608;
    }

    // приведение к доле часов: ((result - 1)*part) + 1
    return (($result - 1) * $hoursPart) + 1;
}

/**
 * Helper function for row 63 calculation
 */
function calculateRow63Value($evaporatorState, $compressorTemp, $humidity, $totalHours, $evaporatorHours) {
    if ($evaporatorState !== 0) return 1; // Если испаритель включен
    
    if ($totalHours === null || $totalHours == 0) return null;
    
    // Полиномиальная функция для относительной влажности
    $resultHumidity = 0;
    if ($humidity <= 10) {
        $resultHumidity = -6.747442e-10 * pow($compressorTemp, 5) + 9.937119e-8 * pow($compressorTemp, 4) - 5.48286e-6 * pow($compressorTemp, 3) + 1.469636e-4 * pow($compressorTemp, 2) + 3.156566e-4 * $compressorTemp + 9.699084e1;
    } elseif ($humidity <= 20) {
        $resultHumidity = 1.441597e-10 * pow($compressorTemp, 5) - 2.131997e-8 * pow($compressorTemp, 4) + 1.357151e-6 * pow($compressorTemp, 3) - 3.143155e-5 * pow($compressorTemp, 2) + 2.653151e-3 * $compressorTemp + 9.599987e1;
    } elseif ($humidity <= 40) {
        $resultHumidity = 1.190276e-9 * pow($compressorTemp, 5) - 1.673392e-7 * pow($compressorTemp, 4) + 9.106626e-6 * pow($compressorTemp, 3) - 2.166697e-4 * pow($compressorTemp, 2) + 5.012879e-3 * $compressorTemp + 9.515353e1;
    } elseif ($humidity <= 58) {
        $resultHumidity = -8.255738e-10 * pow($compressorTemp, 5) + 2.03998e-7 * pow($compressorTemp, 4) - 1.579106e-5 * pow($compressorTemp, 3) + 5.469624e-4 * pow($compressorTemp, 2) - 5.077647e-3 * $compressorTemp + 1.001017e2;
    } elseif ($humidity <= 80) {
        $resultHumidity = 7.879166e-9 * pow($compressorTemp, 5) - 9.228572e-7 * pow($compressorTemp, 4) + 4.052151e-5 * pow($compressorTemp, 3) - 7.922374e-4 * pow($compressorTemp, 2) + 1.019137e-2 * $compressorTemp + 9.417196e1;
    } elseif ($humidity <= 100) {
        $resultHumidity = 3.404797e-9 * pow($compressorTemp, 5) - 3.104972e-7 * pow($compressorTemp, 4) + 1.051523e-5 * pow($compressorTemp, 3) - 1.297768e-4 * pow($compressorTemp, 2) + 3.738357e-3 * $compressorTemp + 9.719307e1;
    }
    
    // Полиномиальная функция для температуры на входе компрессора
    $resultTemp = 2.337825e-9 * pow($compressorTemp, 5) - 1.489873e-7 * pow($compressorTemp, 4) + 5.849849e-7 * pow($compressorTemp, 3) + 1.380716e-4 * pow($compressorTemp, 2) + 8.858796e-4 * $compressorTemp + 9.779048e1;
    
    return (($resultHumidity - 1) * ($totalHours - $evaporatorHours) / $totalHours) + 1; // Относительная влажность
}

/**
 * Helper function for row 64 calculation
 */
function calculateRow64Value($lowHeatOfCombustion, $parameter) {
    if ($lowHeatOfCombustion === null) {
        $lowHeatOfCombustion = 0;
    };
    if ($parameter === null) {
        $parameter = 0;
    };
    
    // Кусковая функция в зависимости от низшей теплоты сгорания
    if ($lowHeatOfCombustion <= 3.829) {
        $value = -1.152942e-11 * pow($parameter, 2) + 1.631939e-6 * $parameter + 9.49386e-1;
    } elseif ($lowHeatOfCombustion <= 3.911) {
        $value = -1.094924e-11 * pow($parameter, 2) + 1.576481e-6 * $parameter + 9.499958e-1;
    } elseif ($lowHeatOfCombustion <= 4) {
        $value = -1.170836e-11 * pow($parameter, 2) + 1.64744e-6 * $parameter + 9.475727e-1;
    } else {
        return null;
    }
    
    return round($value, 4);
}

/**
 * Helper function for row 65 calculation
 */
function calculateRow65Value($evaporatorState, $ambientTemp, $compressorTemp, $frequency, $totalHours, $evaporatorHours) {
    if ($evaporatorState !== 1) return 1; // Если испаритель выключен
    
    if ($totalHours === null || $totalHours == 0) return null;
    
    // Полиномиальная функция в зависимости от температуры
    $result = 0;
    
    if ($evaporatorState === 1) {
        // Испаритель включен - используем температуру на входе компрессора
        $temp = $compressorTemp;
        $hours = $evaporatorHours;
    } else {
        // Испаритель выключен - используем температуру окружающей среды
        $temp = $ambientTemp;
        $hours = $totalHours - $evaporatorHours;
    }
    
    if ($temp <= -15) {
        $result = -1.61248e2 * pow($frequency, 3) + 4.87013e2 * pow($frequency, 2) - 4.89624e2 * $frequency + 1.64858e2;
    } elseif ($temp <= -3) {
        $result = -1.26148e2 * pow($frequency, 3) + 3.85543e2 * pow($frequency, 2) - 3.92196e2 * $frequency + 1.33801e2;
    } elseif ($temp <= 9) {
        $result = -1.6327e1 * pow($frequency, 3) + 5.88608e1 * pow($frequency, 2) - 6.86831e1 * $frequency + 2.71493e1;
    } elseif ($temp <= 10) {
        $result = -3.09157e1 * pow($frequency, 3) + 1.02919e2 * pow($frequency, 2) - 1.13066e2 * $frequency + 4.20625e1;
    } elseif ($temp <= 33) {
        $result = -2.65059e2 * pow($frequency, 3) + 8.15389e2 * pow($frequency, 2) - 8.36579e2 * $frequency + 2.87249e2;
    } elseif ($temp <= 45) {
        $result = -4.21663e2 * pow($frequency, 3) + 1.28452e3 * pow($frequency, 2) - 1.30582e3 * $frequency + 4.43963e2;
    }
    
    if ($evaporatorState === 1) {
        return (($result - 1) * $evaporatorHours / $totalHours) + 1;
    } else {
        return (($result - 1) * ($totalHours - $evaporatorHours) / $totalHours) + 1;
    }
}

/**
 * Helper function for row 66 calculation
 */
function calculateRow66Value($hours) {
    if ($hours === null) return null;
    
    // Кусковая функция в зависимости от эквивалентных часов
    if ($hours <= 10000) {
        $value = 1.792e-12 * pow($hours, 3) - 4.98285714e-8 * pow($hours, 2) + 5.47085714e-4 * $hours + 4.74285714e-3;
    } elseif ($hours > 10000) {
        $value = -1.0857143e-9 * pow($hours, 2) + 7.86285714e-5 * $hours + 1.614;
    } else {
        return null;
    }
    
    return round($value / 100 + 1, 4);
}

/**
 * Helper function for row 67 calculation
 */
function calculateRow67Value($degradation, $load) {
    if ($degradation === null || $load === null) return null;
    
    // Формула: -(F66 - 1) * F49
    $value = -($degradation - 1) * $load;
    
    return round($value, 4);
}

/**
 * Helper function for row 68 calculation
 */
function calculateRow68Value($fouling) {
    if ($fouling === null) return null;
    
    if ($fouling < 1000) {
        return 1;
    } else {
        // Формула: 9.74/10^6 * F35 + 0.99026
        $value = 9.74e-6 * $fouling + 0.99026;
        return round($value, 4);
    }
}

/**
 * Helper function for row 69 calculation
 */
function calculateRow69Value($aosState, $aosHours, $totalHours) {
    if ($aosState <= 0) return 1; // Если АОС не включена
    
    if ($totalHours === null || $totalHours == 0) return null;
    
    // Формула: (((-1.124809/10^5 * F36^2 + 5.02014479/10^3 * F36 + 1.00003698) - 1) * F17/F16) + 1
    $result = (-1.124809e-5 * pow($aosState, 2) + 5.02014479e-3 * $aosState + 1.00003698);
    
    return (($result - 1) * $aosHours / $totalHours) + 1;
}

/**
 * Helper function for row 56 calculation
 */
function calculateRow56Value($hours, $pguId, $context = []) {
    if ($hours === null || $hours == 0) return null;
    
    // Получаем отпуск тепла на блоки из parameter_values
    $heatOutput = getHeatOutputForPgu($pguId, $context);
    if ($heatOutput === null) {
        $heatOutput = 0;
    };
    
    // Формула: -(E7/D5*1.162)/37.2*5.68
    // где E7 = отпуск тепла на блоки (Гкал), D5 = часы работы
    $value = -($heatOutput / $hours * 1.162) / 37.2 * 5.68;
    
    return round($value, 4);
}

/**
 * Get heat output for specific PGU from parameter_values
 */
function getHeatOutputForPgu($pguId, $context = []) {
    // Получаем отпуск тепла на блоки для конкретного ПГУ
    // Параметр "Отпуск тепла на блоки" имеет parameter_id = 12
    
    // Маппинг ПГУ -> equipment_id для ПТ (паровых турбин)
    $pguToEquipment = [
        1 => [4], // ПГУ1 -> ПТ1 (equipment_id = 4)
        2 => [6], // ПГУ2 -> ПТ2 (equipment_id = 6)
    ];
    
    if (!isset($pguToEquipment[$pguId])) {
        return null;
    }
    
    $equipmentIds = $pguToEquipment[$pguId];
    
    // Используем контекст для получения даты и смены
    $date = $context['date'] ?? date('Y-m-d');
    $shiftId = $context['shift_id'] ?? null;
    
    // Получаем отпуск тепла на блоки для ПТ данного ПГУ
    $sql = "SELECT SUM(pv.value) as total_heat
            FROM parameter_values pv
            WHERE pv.parameter_id = 12 
            AND pv.equipment_id IN (" . implode(',', $equipmentIds) . ")
            AND pv.date = ?";
    $params = [$date];
    
    if ($shiftId) {
        $sql .= " AND pv.shift_id = ?";
        $params[] = $shiftId;
    }
    
    $result = fetchOne($sql, $params);
    
    if ($result && isset($result['total_heat'])) {
        return (float)$result['total_heat'];
    }
    
    // Если нет данных за указанную дату/смену, возвращаем null
    return null;
}

/**
 * Helper function for row 74 calculation - get heat output for thermal center
 */
function calculateRow74Value($pguId, $context = []) {
    // F74 = 'Исх данные ПГУ'!E6*0,161 -> отпуск тепла на теплоцентраль ПГУ1 * 0.161
    // G74 = 'Исх данные ПГУ'!H6*0,161 -> отпуск тепла на теплоцентраль ПГУ2 * 0.161
    
    // Маппинг ПГУ -> equipment_id для ПТ (паровых турбин)
    $pguToEquipment = [
        1 => [4], // ПГУ1 -> ПТ1 (equipment_id = 4)
        2 => [6], // ПГУ2 -> ПТ2 (equipment_id = 6)
    ];
    
    if (!isset($pguToEquipment[$pguId])) {
        return null;
    }
    
    $equipmentIds = $pguToEquipment[$pguId];
    
    // Используем контекст для получения даты и смены
    $date = $context['date'] ?? date('Y-m-d');
    $shiftId = $context['shift_id'] ?? null;
    
    // Получаем отпуск тепла на теплоцентраль (parameter_id = 11) для ПТ данного ПГУ
    $sql = "SELECT SUM(pv.value) as total_heat
            FROM parameter_values pv
            WHERE pv.parameter_id = 11 
            AND pv.equipment_id IN (" . implode(',', $equipmentIds) . ")
            AND pv.date = ?";
    $params = [$date];
    
    if ($shiftId) {
        $sql .= " AND pv.shift_id = ?";
        $params[] = $shiftId;
    }
    
    $result = fetchOne($sql, $params);
    
    if ($result && isset($result['total_heat'])) {
        $heatOutput = (float)$result['total_heat'];
        return $heatOutput * 0.161;
    }
    
    // Если нет данных за указанную дату/смену, возвращаем null
    return null;
}

/**
 * Helper function for row 78 polynomial calculation
 */
function calculateRow78Polynomial($x) {
    // Полином: -5,296004/10^11*x^5 + 5,46007318/10^8*x^4 - 2,1963935287/10^5*x^3 + 4,362720319298/10^3*x^2 - 4,45893614984655/10*x + 2,292438590867*10
    
    $coeff = [
        -5.296004e-11,      // коэффициент для x^5
        5.46007318e-8,      // коэффициент для x^4
        -2.1963935287e-5,   // коэффициент для x^3
        4.362720319298e-3,  // коэффициент для x^2
        -4.45893614984655e-1, // коэффициент для x^1
        2.292438590867e1    // свободный член
    ];
    
    $result = $coeff[0] * pow($x, 5) +
              $coeff[1] * pow($x, 4) +
              $coeff[2] * pow($x, 3) +
              $coeff[3] * pow($x, 2) +
              $coeff[4] * $x +
              $coeff[5];
    
    return $result;
}

/**
 * Helper function for row 81 calculation - get start counts from parameter_values
 */
function calculateRow81Value($f31, $f13, $pguId, $context = []) {
    // F81 = ((180*F31/7-390*0,24*1000)*D10+(158*F31/7-365*0,24*1000)*D11+(60*F31/7-175*0,24*1000)*D12)/F13
    // G81 = ((180*G31/7-390*0,24*1000)*G10+(158*G31/7-365*0,24*1000)*G11+(60*G31/7-175*0,24*1000)*G12)/G13
    
    if ($f31 === null || $f13 === null || $f13 == 0) {
        return null;
    }
    
    // Получаем количество пусков разных типов из parameter_values
    // D10/G10, D11/G11, D12/G12 - это количество пусков разных типов для ПГУ
    $startCounts = getStartCountsForPgu($pguId, $context);
    
    if (!$startCounts) {
        return null;
    }
    
    // Расчет компонентов формулы
    $component1 = (180 * $f31 / 7 - 390 * 0.24 * 1000) * $startCounts['type1']; // D10/G10
    $component2 = (158 * $f31 / 7 - 365 * 0.24 * 1000) * $startCounts['type2']; // D11/G11
    $component3 = (60 * $f31 / 7 - 175 * 0.24 * 1000) * $startCounts['type3'];  // D12/G12
    
    $result = ($component1 + $component2 + $component3) / $f13;
    
    return $result;
}

/**
 * Get start counts for specific PGU from equipment_events
 */
function getStartCountsForPgu($pguId, $context = []) {
    // Маппинг ПГУ -> equipment_id для получения пусков оборудования ПГУ
    $pguToEquipment = [
        1 => [3, 5], // ПГУ1 -> ГТ1 (id=1), ПТ1 (id=3)
        2 => [4, 6], // ПГУ2 -> ГТ2 (id=2), ПТ2 (id=5)
    ];
    
    if (!isset($pguToEquipment[$pguId])) {
        return null;
    }
    
    $equipmentIds = $pguToEquipment[$pguId];
    
    // Получаем контекст времени
    $date = $context['date'] ?? date('Y-m-d');
    $shiftId = $context['shift_id'] ?? null;
    $periodType = $context['periodType'] ?? 'day';
    
    // Определяем временной диапазон
    if ($periodType === 'period') {
        $periodStart = $context['period_start'] ?? $date;
        $periodEnd = $context['period_end'] ?? $date;
        $whereClause = "DATE(ee.event_time) >= ? AND DATE(ee.event_time) <= ?";
        $params = array_merge($equipmentIds, [$periodStart, $periodEnd]);
    } elseif ($periodType === 'shift') {
        $whereClause = "DATE(ee.event_time) = ? AND ee.shift_id = ?";
        $params = array_merge($equipmentIds, [$date, $shiftId]);
    } else { // day
        $whereClause = "DATE(ee.event_time) = ?";
        $params = array_merge($equipmentIds, [$date]);
    }
    
    // Подсчитываем количество пусков по типам (start_reasons)
    // Используем LEFT JOIN как в equipment_events_controller.php
    $sql = "SELECT 
                sr.id as start_reason_id,
                sr.name as start_reason_name,
                COUNT(*) as count
            FROM equipment_events ee
            LEFT JOIN start_reasons sr ON ee.event_type = 'pusk' AND ee.reason_id = sr.id
            WHERE ee.equipment_id IN (" . implode(',', array_fill(0, count($equipmentIds), '?')) . ")
            AND ee.event_type = 'pusk'
            AND sr.id IS NOT NULL
            AND {$whereClause}
            GROUP BY sr.id, sr.name";
    
    $results = fetchAll($sql, $params);
    
    // Инициализируем счетчики
    $startCounts = [
        'type1' => 0, // Холодный пуск (start_reason_id = 1)
        'type2' => 0, // Неостывший пуск (start_reason_id = 2)  
        'type3' => 0, // Горячий пуск (start_reason_id = 3)
    ];
    
    // Заполняем фактические значения
    foreach ($results as $result) {
        $startReasonId = (int)$result['start_reason_id'];
        $count = (int)$result['count'];
        
        switch ($startReasonId) {
            case 1: // Холодный
                $startCounts['type1'] = $count;
                break;
            case 2: // Неостывший
                $startCounts['type2'] = $count;
                break;
            case 3: // Горячий
                $startCounts['type3'] = $count;
                break;
        }
    }
    
    return $startCounts;
}

// =============================================================================
// DATA ACCESS FUNCTIONS
// =============================================================================

/**
 * Load input data for calculations
 */
function getInputDataForCalculation($date, $shiftId, $periodType, $periodStart = null, $periodEnd = null) {
    $inputData = [];

    if ($periodType !== 'period') {
        $sql = "SELECT pfv.fullparam_id, pfv.value, pfp.code, pfp.row_num, pfv.pgu_id, pfv.cell
                FROM pgu_fullparam_values pfv
                JOIN pgu_fullparams pfp ON pfv.fullparam_id = pfp.id
                WHERE pfv.date = ?";
        $params = [$date];
        if ($shiftId) {
            $sql .= " AND pfv.shift_id = ?";
            $params[] = $shiftId;
        }
        $values = fetchAll($sql, $params);

        foreach ($values as $value) {
            $pid = (int)$value['pgu_id'];
            if (!isset($inputData[$pid])) $inputData[$pid] = [];
            $inputData[$pid][$value['code']] = (float)$value['value'];
            if (!isset($inputData['cells'])) $inputData['cells'] = [];
            if (!empty($value['cell'])) $inputData['cells'][$value['cell']] = (float)$value['value'];
        }
    }

    $inputData['_context'] = [
        'date' => $date,
        'shift_id' => $shiftId,
        'periodType' => $periodType,
        'period_start' => $periodStart,
        'period_end' => $periodEnd
    ];
    
    return $inputData;
}

/**
 * Resolve cell value from cache or special calculation
 */
function resolveCellValue(array &$inputData, string $cell) {
    if (isset($inputData['cells'][$cell])) return $inputData['cells'][$cell];
    $val = resolveSpecialCell($cell, $inputData['_context'] ?? []);
    if ($val !== null) {
        $inputData['cells'][$cell] = $val;
    }
    return $val;
}

/**
 * Get equipment energy output
 */
function getEquipmentEnergyOutput(int $equipmentId, array $ctx) {
    $date = $ctx['date'] ?? null;
    $shiftId = $ctx['shift_id'] ?? null;
    $periodType = $ctx['periodType'] ?? 'day';
    
    // Получаем выработку энергии
    $energyGeneration = null;
    
    if ($periodType === 'period') {
        $periodStart = $ctx['period_start'] ?? $date;
        $periodEnd = $ctx['period_end'] ?? $date;
        $sql = "SELECT SUM(mr.shift1 + mr.shift2 + mr.shift3) as total FROM meter_readings mr 
                JOIN meters m ON mr.meter_id = m.id 
                WHERE m.equipment_id = ? AND m.meter_type_id = 1 AND m.is_active = 1 
                AND mr.date >= ? AND mr.date <= ?";
        $result = fetchOne($sql, [$equipmentId, $periodStart, $periodEnd]);
        $energyGeneration = $result ? (float)$result['total'] : null;
    } else {
        $sql = "SELECT mr.shift1, mr.shift2, mr.shift3 FROM meter_readings mr 
                JOIN meters m ON mr.meter_id = m.id 
                WHERE m.equipment_id = ? AND m.meter_type_id = 1 AND m.is_active = 1 AND mr.date = ?";
        $result = fetchOne($sql, [$equipmentId, $date]);
        
        if (!$result) return null;
        
        if ($shiftId) {
            $shiftKey = 'shift' . $shiftId;
            $energyGeneration = $result[$shiftKey] ? (float)$result[$shiftKey] : null;
        } else {
            $energyGeneration = (float)($result['shift1'] + $result['shift2'] + $result['shift3']);
        }
    }
    
    if ($energyGeneration === null) return null;
    
    // Для equipment 3 и 5 (ПТ1 и ПТ2) нужно вычитать расход энергии (собственные нужды)
    if ($equipmentId == 3 || $equipmentId == 5) {
        $energyConsumption = getEquipmentEnergyConsumption($equipmentId, $ctx);
        if ($energyConsumption !== null) {
            return $energyGeneration - $energyConsumption;
        }
    }
    
    return $energyGeneration;
}

/**
 * Get equipment energy consumption (собственные нужды) for specific equipment
 */
function getEquipmentEnergyConsumption(int $equipmentId, array $ctx) {
    $date = $ctx['date'] ?? null;
    $shiftId = $ctx['shift_id'] ?? null;
    $periodType = $ctx['periodType'] ?? 'day';
    
    // Получаем расход энергии (собственные нужды) - meter_type_id = 2
    if ($periodType === 'period') {
        $periodStart = $ctx['period_start'] ?? $date;
        $periodEnd = $ctx['period_end'] ?? $date;
        $sql = "SELECT SUM(mr.shift1 + mr.shift2 + mr.shift3) as total FROM meter_readings mr 
                JOIN meters m ON mr.meter_id = m.id 
                WHERE m.equipment_id = ? AND m.meter_type_id = 2 AND m.is_active = 1 
                AND mr.date >= ? AND mr.date <= ?";
        $result = fetchOne($sql, [$equipmentId, $periodStart, $periodEnd]);
        return $result ? (float)$result['total'] : null;
    } else {
        $sql = "SELECT mr.shift1, mr.shift2, mr.shift3 FROM meter_readings mr 
                JOIN meters m ON mr.meter_id = m.id 
                WHERE m.equipment_id = ? AND m.meter_type_id = 2 AND m.is_active = 1 AND mr.date = ?";
        $result = fetchOne($sql, [$equipmentId, $date]);
        
        if (!$result) return null;
        
        if ($shiftId) {
            $shiftKey = 'shift' . $shiftId;
            return $result[$shiftKey] ? (float)$result[$shiftKey] : null;
        } else {
            return (float)($result['shift1'] + $result['shift2'] + $result['shift3']);
        }
    }
}

/**
 * Save special cells to pgu_fullparam_values
 */
function saveSpecialCellsToPguFullParams(array $inputData, string $date, $shiftId) {
    $ctx = $inputData['_context'] ?? [];
    
    $specialCellMapping = [
        16 => 7, 17 => 8, 18 => 9, 19 => 10, 22 => 13, 34 => 25, 39 => 30, 40 => 31
    ];
    
    foreach ($specialCellMapping as $rowNum => $fullparamId) {
        foreach ([1, 2, 3] as $pguId) {
            $cell = ($pguId === 1 ? 'F' : ($pguId === 2 ? 'G' : 'H')) . $rowNum;
            $value = resolveSpecialCell($cell, $ctx);
            
            if ($value !== null) {
                $roundedValue = round((float)$value, 4);
                
                $existing = fetchOne(
                    "SELECT id FROM pgu_fullparam_values 
                     WHERE fullparam_id = ? AND pgu_id = ? AND date = ? AND shift_id = ?",
                    [$fullparamId, $pguId, $date, $shiftId]
                );
                
                if ($existing) {
                    executeQuery(
                        "UPDATE pgu_fullparam_values SET value = ?, cell = ? WHERE id = ?",
                        [$roundedValue, $cell, $existing['id']]
                    );
                } else {
                    executeQuery(
                        "INSERT INTO pgu_fullparam_values 
                         (fullparam_id, pgu_id, date, shift_id, value, cell) 
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$fullparamId, $pguId, $date, $shiftId, $roundedValue, $cell]
                    );
                }
            }
        }
    }
}

// =============================================================================
// SPECIAL CELL CALCULATIONS
// =============================================================================

function resolveSpecialCell(string $cell, array $ctx) {
    if (!preg_match('/^([FGH])(\d{1,3})$/u', $cell, $m)) return null;
    $col = $m[1];
    $row = (int)$m[2];

    $specialRows = [16,17,18,19,22,34,39,40];
    if (!in_array($row, $specialRows, true)) return null;

    $pguId = $col === 'F' ? 1 : ($col === 'G' ? 2 : 3);

    switch ($row) {
        case 16: return computePguOperatingHoursSpecial($pguId, $ctx);
        case 17: return computePguHoursWithAOS($pguId, $ctx);
        case 18: return computePguHoursWithEvaporator($pguId, $ctx);
        case 19: return computePguTotalHoursOver35k($pguId, $ctx);
        case 22: return computeEvaporatorState($pguId, $ctx);
        case 34: return computeThermalLoad($pguId, $ctx);
        case 39: return computePguTotalOperatingHours($pguId, $ctx);
        case 40: return computePguStartCount($pguId, $ctx);
        default: return null;
    }
}

// =============================================================================
// SPECIAL CELL CALCULATION FUNCTIONS
// =============================================================================

function computePguOperatingHoursSpecial(int $pguId, array $ctx) {
    $periodType = $ctx['periodType'] ?? 'day';

    if ($periodType === 'shift') {
        [$start, $end] = getShiftWindow($ctx['date'], (int)$ctx['shift_id']);
    } elseif ($periodType === 'day') {
        $start = $ctx['date'] . ' 00:00:00';
        $end   = $ctx['date'] . ' 23:59:59';
    } else {
        $start = ($ctx['period_start'] ?? $ctx['date']) . ' 00:00:00';
        $end   = ($ctx['period_end'] ?? $ctx['date']) . ' 23:59:59';
    }

    if ($pguId === 3) {
        $h1 = computePguOperatingHours(1, $start, $end);
        $h2 = computePguOperatingHours(2, $start, $end);
        return round($h1 + $h2, 4);
    }
    return round(computePguOperatingHours($pguId, $start, $end), 4);
}

function computePguOperatingHours(int $pguId, string $start, string $end): float {
    $equipmentIds = getPguEquipmentIds($pguId);
    $intervals = [];
    foreach ($equipmentIds as $eqId) {
        $intervals = array_merge($intervals, getEquipmentRunIntervals($eqId, $start, $end));
    }
    $merged = mergeIntervals($intervals);
    $seconds = 0;
    foreach ($merged as [$s, $e]) {
        $seconds += (strtotime($e) - strtotime($s));
    }
    return $seconds / 3600.0;
}

function getPguEquipmentIds(int $pguId): array {
    if ($pguId === 1) return [3,4];
    if ($pguId === 2) return [5,6];
    return [];
}

function getEquipmentRunIntervals(int $equipmentId, string $start, string $end): array {
    $last = fetchOne("SELECT event_type, event_time FROM equipment_events WHERE equipment_id = ? AND event_time <= ? ORDER BY event_time DESC LIMIT 1", [$equipmentId, $start]);
    $isRunning = $last && $last['event_type'] === 'pusk';

    $events = fetchAll("SELECT event_type, event_time FROM equipment_events WHERE equipment_id = ? AND event_time >= ? AND event_time <= ? ORDER BY event_time ASC", [$equipmentId, $start, $end]);

    $intervals = [];
    $currentStart = null;

    if ($isRunning) {
        $currentStart = $start;
    }

    foreach ($events as $ev) {
        $ts = $ev['event_time'];
        if ($ev['event_type'] === 'pusk') {
            if (!$isRunning) {
                $isRunning = true;
                $currentStart = max($start, $ts);
            }
        } elseif ($ev['event_type'] === 'ostanov') {
            if ($isRunning) {
                $isRunning = false;
                $intervals[] = [ $currentStart, min($end, $ts) ];
                $currentStart = null;
            }
        }
    }

    if ($isRunning && $currentStart !== null) {
        $intervals[] = [ $currentStart, $end ];
    }

    $clean = [];
    foreach ($intervals as [$s,$e]) {
        if (strtotime($e) > strtotime($s)) $clean[] = [$s,$e];
    }
    return $clean;
}

function mergeIntervals(array $intervals): array {
    if (empty($intervals)) return [];
    usort($intervals, function($a,$b){ return strcmp($a[0], $b[0]); });
    $merged = [];
    [$cs,$ce] = $intervals[0];
    for ($i=1;$i<count($intervals);$i++) {
        [$s,$e] = $intervals[$i];
        if (strtotime($s) <= strtotime($ce)) {
            if (strtotime($e) > strtotime($ce)) $ce = $e;
        } else {
            $merged[] = [$cs,$ce];
            [$cs,$ce] = [$s,$e];
        }
    }
    $merged[] = [$cs,$ce];
    return $merged;
}

function computePguHoursWithAOS(int $pguId, array $ctx) {
    $periodType = $ctx['periodType'] ?? 'day';

    if ($periodType === 'shift') {
        [$start, $end] = getShiftWindow($ctx['date'], (int)$ctx['shift_id']);
    } elseif ($periodType === 'day') {
        $start = $ctx['date'] . ' 00:00:00';
        $end   = $ctx['date'] . ' 23:59:59';
    } else {
        $start = ($ctx['period_start'] ?? $ctx['date']) . ' 00:00:00';
        $end   = ($ctx['period_end'] ?? $ctx['date']) . ' 23:59:59';
    }

    if ($pguId === 3) {
        $h1 = computePguHoursWithTool(1, 'aos', $start, $end);
        $h2 = computePguHoursWithTool(2, 'aos', $start, $end);
        return round($h1 + $h2, 4);
    }
    return round(computePguHoursWithTool($pguId, 'aos', $start, $end), 4);
}

function computePguHoursWithEvaporator(int $pguId, array $ctx) {
    $periodType = $ctx['periodType'] ?? 'day';

    if ($periodType === 'shift') {
        [$start, $end] = getShiftWindow($ctx['date'], (int)$ctx['shift_id']);
    } elseif ($periodType === 'day') {
        $start = $ctx['date'] . ' 00:00:00';
        $end   = $ctx['date'] . ' 23:59:59';
    } else {
        $start = ($ctx['period_start'] ?? $ctx['date']) . ' 00:00:00';
        $end   = ($ctx['period_end'] ?? $ctx['date']) . ' 23:59:59';
    }

    if ($pguId === 3) {
        $h1 = computePguHoursWithTool(1, 'evaporator', $start, $end);
        $h2 = computePguHoursWithTool(2, 'evaporator', $start, $end);
        return round($h1 + $h2, 4);
    }
    return round(computePguHoursWithTool($pguId, 'evaporator', $start, $end), 4);
}

function computePguTotalHoursOver35k(int $pguId, array $ctx) {
    $totalHours = computePguTotalOperatingHours($pguId, $ctx);
    return $totalHours > 35000 ? round($totalHours, 4) : 0;
}

function computeEvaporatorState(int $pguId, array $ctx) {
    $periodType = $ctx['periodType'] ?? 'day';
    
    if ($periodType === 'shift') {
        [$start, $end] = getShiftWindow($ctx['date'], (int)$ctx['shift_id']);
    } elseif ($periodType === 'day') {
        $start = $ctx['date'] . ' 00:00:00';
        $end   = $ctx['date'] . ' 23:59:59';
    } else {
        $start = ($ctx['period_start'] ?? $ctx['date']) . ' 00:00:00';
        $end   = ($ctx['period_end'] ?? $ctx['date']) . ' 23:59:59';
    }

    if ($pguId === 3) {
        $state1 = getLastToolState(1, 'evaporator', $end);
        $state2 = getLastToolState(2, 'evaporator', $end);
        return ($state1 || $state2) ? 1 : 0;
    }
    return getLastToolState($pguId, 'evaporator', $end) ? 1 : 0;
}

function computeThermalLoad(int $pguId, array $ctx) {
    if ($pguId === 3) {
        $f34 = computeThermalLoad(1, $ctx);
        $g34 = computeThermalLoad(2, $ctx);
        return round(($f34 ?? 0) + ($g34 ?? 0), 4);
    }

    $col = $pguId === 1 ? 'F' : 'G';
    $f14 = resolveCellValueDirect($col . '14', $ctx);
    $f16 = computePguOperatingHoursSpecial($pguId, $ctx);
    
    if ($f14 === null || $f16 === null || $f16 == 0) return null;
    return round($f14 / $f16, 4);
}

function computePguTotalOperatingHours(int $pguId, array $ctx) {
    $endDate = $ctx['date'] ?? date('Y-m-d');
    $endTime = $endDate . ' 23:59:59';
    
    if ($pguId === 3) {
        $h1 = computePguOperatingHours(1, '1900-01-01 00:00:00', $endTime);
        $h2 = computePguOperatingHours(2, '1900-01-01 00:00:00', $endTime);
        return round($h1 + $h2, 4);
    }
    return round(computePguOperatingHours($pguId, '1900-01-01 00:00:00', $endTime), 4);
}

function computePguStartCount(int $pguId, array $ctx) {
    $periodType = $ctx['periodType'] ?? 'day';
    
    if ($periodType === 'shift') {
        [$start, $end] = getShiftWindow($ctx['date'], (int)$ctx['shift_id']);
    } elseif ($periodType === 'day') {
        $start = $ctx['date'] . ' 00:00:00';
        $end   = $ctx['date'] . ' 23:59:59';
    } else {
        $start = ($ctx['period_start'] ?? $ctx['date']) . ' 00:00:00';
        $end   = ($ctx['period_end'] ?? $ctx['date']) . ' 23:59:59';
    }

    if ($pguId === 3) {
        $count1 = countPguStarts(1, $start, $end);
        $count2 = countPguStarts(2, $start, $end);
        return $count1 + $count2;
    }
    return countPguStarts($pguId, $start, $end);
}

function computePguHoursWithTool(int $pguId, string $toolType, string $start, string $end): float {
    $equipmentIds = getPguEquipmentIds($pguId);
    $pguIntervals = [];
    foreach ($equipmentIds as $eqId) {
        $pguIntervals = array_merge($pguIntervals, getEquipmentRunIntervals($eqId, $start, $end));
    }
    $mergedPguIntervals = mergeIntervals($pguIntervals);
    
    $toolIntervals = [];
    foreach ($equipmentIds as $eqId) {
        $toolIntervals = array_merge($toolIntervals, getEquipmentToolIntervals($eqId, $toolType, $start, $end));
    }
    $mergedToolIntervals = mergeIntervals($toolIntervals);
    
    $intersectedIntervals = intersectIntervals($mergedPguIntervals, $mergedToolIntervals);
    
    $seconds = 0;
    foreach ($intersectedIntervals as [$s, $e]) {
        $seconds += (strtotime($e) - strtotime($s));
    }
    return $seconds / 3600.0;
}

function getEquipmentToolIntervals(int $equipmentId, string $toolType, string $start, string $end): array {
    $last = fetchOne("SELECT event_type, event_time FROM equipment_tool_events WHERE equipment_id = ? AND tool_type = ? AND event_time <= ? ORDER BY event_time DESC LIMIT 1", [$equipmentId, $toolType, $start]);
    $isOn = $last && $last['event_type'] === 'on';

    $events = fetchAll("SELECT event_type, event_time FROM equipment_tool_events WHERE equipment_id = ? AND tool_type = ? AND event_time >= ? AND event_time <= ? ORDER BY event_time ASC", [$equipmentId, $toolType, $start, $end]);

    $intervals = [];
    $currentStart = null;

    if ($isOn) {
        $currentStart = $start;
    }

    foreach ($events as $ev) {
        $ts = $ev['event_time'];
        if ($ev['event_type'] === 'on') {
            if (!$isOn) {
                $isOn = true;
                $currentStart = max($start, $ts);
            }
        } elseif ($ev['event_type'] === 'off') {
            if ($isOn) {
                $isOn = false;
                $intervals[] = [$currentStart, min($end, $ts)];
                $currentStart = null;
            }
        }
    }

    if ($isOn && $currentStart !== null) {
        $intervals[] = [$currentStart, $end];
    }

    $clean = [];
    foreach ($intervals as [$s,$e]) {
        if (strtotime($e) > strtotime($s)) $clean[] = [$s,$e];
    }
    return $clean;
}

function intersectIntervals(array $intervals1, array $intervals2): array {
    $result = [];
    
    foreach ($intervals1 as [$start1, $end1]) {
        foreach ($intervals2 as [$start2, $end2]) {
            $start = max($start1, $start2);
            $end = min($end1, $end2);
            
            if (strtotime($start) < strtotime($end)) {
                $result[] = [$start, $end];
            }
        }
    }
    
    return mergeIntervals($result);
}

function getLastToolState(int $pguId, string $toolType, string $beforeTime): bool {
    $equipmentIds = getPguEquipmentIds($pguId);
    
    foreach ($equipmentIds as $eqId) {
        $last = fetchOne("SELECT event_type FROM equipment_tool_events WHERE equipment_id = ? AND tool_type = ? AND event_time <= ? ORDER BY event_time DESC LIMIT 1", [$eqId, $toolType, $beforeTime]);
        if ($last && $last['event_type'] === 'on') {
            return true;
        }
    }
    return false;
}

function countPguStarts(int $pguId, string $start, string $end): int {
    $equipmentIds = getPguEquipmentIds($pguId);
    $count = 0;
    
    foreach ($equipmentIds as $eqId) {
        $events = fetchAll("SELECT COUNT(*) as cnt FROM equipment_events WHERE equipment_id = ? AND event_type = 'pusk' AND event_time >= ? AND event_time <= ?", [$eqId, $start, $end]);
        $count += (int)($events[0]['cnt'] ?? 0);
    }
    
    return $count;
}

function resolveCellValueDirect(string $cell, array $ctx) {
    $date = $ctx['date'] ?? null;
    $shiftId = $ctx['shift_id'] ?? null;
    $periodType = $ctx['periodType'] ?? 'day';
    
    if ($periodType === 'period') {
        $periodStart = $ctx['period_start'] ?? $date;
        $periodEnd = $ctx['period_end'] ?? $date;
        $value = fetchOne("SELECT value FROM pgu_fullparam_values WHERE cell = ? AND date >= ? AND date <= ? ORDER BY date DESC LIMIT 1", [$cell, $periodStart, $periodEnd]);
    } else {
        $sql = "SELECT value FROM pgu_fullparam_values WHERE cell = ? AND date = ?";
        $params = [$cell, $date];
        if ($shiftId) {
            $sql .= " AND shift_id = ?";
            $params[] = $shiftId;
        }
        $value = fetchOne($sql, $params);
    }
    
    return $value ? (float)$value['value'] : null;
}

function getShiftWindow(string $date, int $shiftId): array {
    switch ($shiftId) {
        case 1: return [$date.' 00:00:00', $date.' 07:59:59'];
        case 2: return [$date.' 08:00:00', $date.' 15:59:59'];
        case 3: return [$date.' 16:00:00', $date.' 23:59:59'];
        default: return [$date.' 00:00:00', $date.' 23:59:59'];
    }
}

// =============================================================================
// UTILITIES
// =============================================================================

function getShiftIdFromName($shiftName) {
    $mapping = ['shift1' => 1, 'shift2' => 2, 'shift3' => 3];
    return $mapping[$shiftName] ?? null;
}

function getCurrentUserId() { 
    return 1; 
}
