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
    ];
    
    // Convert to save format
    $results = [];
    foreach ($calculations as $rowNum => $values) {
        [$fValue, $gValue, $hValue] = $values;
        $paramId = ROW_TO_PARAM_MAPPING[$rowNum];
        
        // ПГУ 1 (F)
        if ($fValue !== null) {
            $results[] = [
                'param_id' => $paramId,
                'pgu_id' => 1,
                'value' => round($fValue, 6),
                'shift_id' => $periodType === 'shift' ? $shiftId : null
            ];
        }
        
        // ПГУ 2 (G) 
        if ($gValue !== null) {
            $results[] = [
                'param_id' => $paramId,
                'pgu_id' => 2,
                'value' => round($gValue, 6),
                'shift_id' => $periodType === 'shift' ? $shiftId : null
            ];
        }
        
        // ПГУ 1+2 (H)
        if ($hValue !== null) {
            $results[] = [
                'param_id' => $paramId,
                'pgu_id' => 3,
                'value' => round($hValue, 6),
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

    $f55 = calcDeltaNCosPhiPiecewise($f29, $f50);
    $g55 = calcDeltaNCosPhiPiecewise($g29, $g50);
    
    return [$f55, $g55];
}

/**
 * Row 56: на отпуск тепла на блоки, MW
 */
function calculateRow56($inputData) {
    // F56 = -(E7/D5*1.162)/37.2*5.68
    // Пока что используем placeholder значения, так как E7 и D5 не определены
    $f56 = null; // TODO: Реализовать когда будут доступны E7 и D5
    $g56 = 0; // G56 = 0 по формуле
    
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
 * Piecewise function for cosφ calculations
 */
function calcDeltaNCosPhiPiecewise($cosPhi, $x) {
    if ($cosPhi === null || $x === null) return null;
    
    if ($cosPhi == 0.9) return 0;
    
    $a2 = -1.274598e-8; $b2 = 2.972362e-5; $c2 = 3.359379e-3; $d2 = 2.359075; $e2 = 1.604836e3;
    $y2 = $a2 * pow($x, 4) + $b2 * pow($x, 3) + $c2 * pow($x, 2) + $d2 * $x + $e2;
    
    if ($cosPhi < 0.9) {
        $a1 = -3.493055e-7; $b1 = 2.19192e-4; $c1 = -2.991165e-2; $d1 = 4.974629; $e1 = 1.555182e3;
        $y1 = $a1 * pow($x, 4) + $b1 * pow($x, 3) + $c1 * pow($x, 2) + $d1 * $x + $e1;
        return ($y1 - $y2) / 0.05 * (0.9 - $cosPhi) / 1000;
    } elseif ($cosPhi <= 0.95) {
        $a3 = -5.450546e-8; $b3 = 3.486201e-5; $c3 = 5.787735e-3; $d3 = 1.166651; $e3 = 1.657923e3;
        $y3 = $a3 * pow($x, 4) + $b3 * pow($x, 3) + $c3 * pow($x, 2) + $d3 * $x + $e3;
        return ($y3 - $y2) / 0.05 * ($cosPhi - 0.9) / 1000;
    } else {
        $a4 = 3.550269e-7; $b4 = -2.116611e-4; $c4 = 5.486279e-2; $d4 = -3.205089; $e4 = 1.755291e3;
        $y4 = $a4 * pow($x, 4) + $b4 * pow($x, 3) + $c4 * pow($x, 2) + $d4 * $x + $e4;
        return ($y4 - $y2) / 0.1 * ($cosPhi - 0.9) / 1000;
    }
}

/**
 * Helper function for row 60 calculation
 */
function calculateRow60Value($evaporatorState, $temp, $humidity, $evaporatorHours, $totalHours) {
    if ($evaporatorState !== 1) return 1; // Если испаритель выключен
    
    if ($totalHours === null || $totalHours == 0) return null;
    
    // Полиномиальная функция в зависимости от температуры и влажности
    $result = 0;
    
    if ($humidity <= 10) {
        $result = -6.747442e-10 * pow($temp, 5) + 9.937119e-8 * pow($temp, 4) - 5.48286e-6 * pow($temp, 3) + 1.469636e-4 * pow($temp, 2) + 3.156566e-4 * $temp + 9.699084e1;
    } elseif ($humidity <= 20) {
        $result = 1.441597e-10 * pow($temp, 5) - 2.131997e-8 * pow($temp, 4) + 1.357151e-6 * pow($temp, 3) - 3.143155e-5 * pow($temp, 2) + 2.653151e-3 * $temp + 9.599987e1;
    } elseif ($humidity <= 40) {
        $result = 1.190276e-9 * pow($temp, 5) - 1.673392e-7 * pow($temp, 4) + 9.106626e-6 * pow($temp, 3) - 2.166697e-4 * pow($temp, 2) + 5.012879e-3 * $temp + 9.515353e1;
    } elseif ($humidity <= 58) {
        $result = -8.255738e-10 * pow($temp, 5) + 2.03998e-7 * pow($temp, 4) - 1.579106e-5 * pow($temp, 3) + 5.469624e-4 * pow($temp, 2) - 5.077647e-3 * $temp + 1.001017e2;
    } elseif ($humidity <= 80) {
        $result = 7.879166e-9 * pow($temp, 5) - 9.228572e-7 * pow($temp, 4) + 4.052151e-5 * pow($temp, 3) - 7.922374e-4 * pow($temp, 2) + 1.019137e-2 * $temp + 9.417196e1;
    } elseif ($humidity <= 100) {
        $result = 3.404797e-9 * pow($temp, 5) - 3.104972e-7 * pow($temp, 4) + 1.051523e-5 * pow($temp, 3) - 1.297768e-4 * pow($temp, 2) + 3.738357e-3 * $temp + 9.719307e1;
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
    if ($evaporatorState === 1) {
        // Испаритель включен - используем температуру на входе компрессора
        $temp = $compressorTemp;
        $hours = $evaporatorHours;
    } else {
        // Испаритель выключен - используем температуру окружающей среды
        $temp = $ambientTemp;
        $hours = $totalHours - $evaporatorHours;
    }
    
    if ($totalHours === null || $totalHours == 0) return null;
    
    // Полиномиальная функция в зависимости от температуры
    $result = 0;
    
    if ($temp <= -3) {
        $result = 7.290788e-12 * pow($pressure, 4) - 3.050169e-8 * pow($pressure, 3) + 4.875852e-5 * pow($pressure, 2) - 3.615805e-2 * $pressure + 1.161435e1;
    } elseif ($temp <= 9) {
        $result = 7.607963e-12 * pow($pressure, 4) - 3.189538e-8 * pow($pressure, 3) + 5.103426e-5 * pow($pressure, 2) - 3.779398e-2 * $pressure + 1.205104e1;
    } elseif ($temp <= 10) {
        $result = 5.749487e-12 * pow($pressure, 4) - 2.438191e-8 * pow($pressure, 3) + 3.964932e-5 * pow($pressure, 2) - 3.013092e-2 * $pressure + 1.011792e1;
    } elseif ($temp <= 33) {
        $result = -4.349388e-12 * pow($pressure, 4) + 1.645545e-8 * pow($pressure, 3) - 2.2244e-5 * pow($pressure, 2) + 1.154508e-2 * $pressure - 4.032603e1;
    } elseif ($temp <= 45) {
        $result = 4.61965e-14 * pow($pressure, 4) - 7.819627e-10 * pow($pressure, 3) + 3.086744e-6 * pow($pressure, 2) - 4.994672e-3 * $pressure + 3.647608e2;
    }
    
    if ($evaporatorState === 1) {
        return (($result - 1) * $evaporatorHours / $totalHours) + 1;
    } else {
        return (($result - 1) * ($totalHours - $evaporatorHours) / $totalHours) + 1;
    }
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
    
    if ($periodType === 'period') {
        $periodStart = $ctx['period_start'] ?? $date;
        $periodEnd = $ctx['period_end'] ?? $date;
        $sql = "SELECT SUM(mr.shift1 + mr.shift2 + mr.shift3) as total FROM meter_readings mr 
                JOIN meters m ON mr.meter_id = m.id 
                WHERE m.equipment_id = ? AND m.meter_type_id = 1 AND m.is_active = 1 
                AND mr.date >= ? AND mr.date <= ?";
        $result = fetchOne($sql, [$equipmentId, $periodStart, $periodEnd]);
        return $result ? (float)$result['total'] : null;
    } else {
        $sql = "SELECT mr.shift1, mr.shift2, mr.shift3 FROM meter_readings mr 
                JOIN meters m ON mr.meter_id = m.id 
                WHERE m.equipment_id = ? AND m.meter_type_id = 1 AND m.is_active = 1 AND mr.date = ?";
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
        return round($h1 + $h2, 2);
    }
    return round(computePguOperatingHours($pguId, $start, $end), 2);
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
        return round($h1 + $h2, 2);
    }
    return round(computePguHoursWithTool($pguId, 'aos', $start, $end), 2);
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
        return round($h1 + $h2, 2);
    }
    return round(computePguHoursWithTool($pguId, 'evaporator', $start, $end), 2);
}

function computePguTotalHoursOver35k(int $pguId, array $ctx) {
    $totalHours = computePguTotalOperatingHours($pguId, $ctx);
    return $totalHours > 35000 ? round($totalHours, 2) : 0;
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
        return round(($f34 ?? 0) + ($g34 ?? 0), 2);
    }

    $col = $pguId === 1 ? 'F' : 'G';
    $f14 = resolveCellValueDirect($col . '14', $ctx);
    $f16 = computePguOperatingHoursSpecial($pguId, $ctx);
    
    if ($f14 === null || $f16 === null || $f16 == 0) return null;
    return round($f14 / $f16, 2);
}

function computePguTotalOperatingHours(int $pguId, array $ctx) {
    $endDate = $ctx['date'] ?? date('Y-m-d');
    $endTime = $endDate . ' 23:59:59';
    
    if ($pguId === 3) {
        $h1 = computePguOperatingHours(1, '1900-01-01 00:00:00', $endTime);
        $h2 = computePguOperatingHours(2, '1900-01-01 00:00:00', $endTime);
        return round($h1 + $h2, 2);
    }
    return round(computePguOperatingHours($pguId, '1900-01-01 00:00:00', $endTime), 2);
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