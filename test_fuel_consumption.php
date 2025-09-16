<?php
require_once '/var/www/html/helpers/db.php';

function getParameterValue($date, $shiftId, $blockId, $paramId, &$values) {
    // Сначала ищем в уже рассчитанных значениях
    foreach ($values as $value) {
        if ($value['tg_id'] == $blockId && $value['shift_id'] == $shiftId && $value['param_id'] == $paramId) {
            echo "getParameterValue: найдено в текущих значениях - param_id=$paramId, blockId=$blockId, shiftId=$shiftId, value={$value['value']}\n";
            return $value['value'];
        }
    }
    
    echo "getParameterValue: НЕ найдено в текущих значениях - param_id=$paramId, blockId=$blockId, shiftId=$shiftId\n";
    return 0;
}

function calculateGasFuelConsumption($date, $shiftId, $blockId, &$values) {
    try {
        echo "=== Расчет расхода газа для блока $blockId, смена $shiftId ===\n";
        
        // Получаем качество газа (параметр 269) - G10
        $gasQuality = getParameterValue($date, $shiftId, 9, 269, $values);
        echo "Качество газа (G10): $gasQuality\n";
        
        // Получаем количество газа (параметр 271) - E12/F12
        $gasQuantity = getParameterValue($date, $shiftId, $blockId, 271, $values);
        echo "Количество газа (E12/F12): $gasQuantity\n";
        
        if ($gasQuality && $gasQuantity) {
            $consumption = ($gasQuality / 7000) * $gasQuantity;
            echo "Расход топлива (газ): ($gasQuality/7000)*$gasQuantity = $consumption\n";
            return $consumption;
        }
        
        echo "Один из параметров равен 0 или null\n";
        return null;
        
    } catch (Exception $e) {
        echo 'Ошибка при расчете расхода топлива (газ): ' . $e->getMessage() . "\n";
        return null;
    }
}

function calculateOilFuelConsumption($date, $shiftId, $blockId, &$values) {
    try {
        echo "=== Расчет расхода мазута для блока $blockId, смена $shiftId ===\n";
        
        // Получаем качество мазута (параметр 270) - G11
        $oilQuality = getParameterValue($date, $shiftId, 9, 270, $values);
        echo "Качество мазута (G11): $oilQuality\n";
        
        // Получаем количество мазута (параметр 272) - E13/F13
        $oilQuantity = getParameterValue($date, $shiftId, $blockId, 272, $values);
        echo "Количество мазута (E13/F13): $oilQuantity\n";
        
        if ($oilQuality && $oilQuantity) {
            $consumption = ($oilQuality / 7000) * $oilQuantity;
            echo "Расход топлива (мазут): ($oilQuality/7000)*$oilQuantity = $consumption\n";
            return $consumption;
        }
        
        echo "Один из параметров равен 0 или null\n";
        return null;
        
    } catch (Exception $e) {
        echo 'Ошибка при расчете расхода топлива (мазут): ' . $e->getMessage() . "\n";
        return null;
    }
}

// Тестируем для разных блоков и дат
$testDate = '2025-09-16';
$testShift = 1;

echo "=== Тест расчетов расхода топлива ===\n";
echo "Дата: $testDate, Смена: $testShift\n\n";

// Создаем массив values с уже рассчитанными значениями
$values = [];

// Добавляем качество топлива (параметры 269, 270)
$values[] = [
    'param_id' => 269,
    'tg_id' => 9,
    'shift_id' => 1,
    'value' => 2500
];

$values[] = [
    'param_id' => 270,
    'tg_id' => 9,
    'shift_id' => 1,
    'value' => 3000
];

// Добавляем количество топлива (параметры 271, 272)
$values[] = [
    'param_id' => 271,
    'tg_id' => 7,
    'shift_id' => 1,
    'value' => 1500
];

$values[] = [
    'param_id' => 271,
    'tg_id' => 8,
    'shift_id' => 1,
    'value' => 2300
];

$values[] = [
    'param_id' => 272,
    'tg_id' => 7,
    'shift_id' => 1,
    'value' => 232
];

$values[] = [
    'param_id' => 272,
    'tg_id' => 8,
    'shift_id' => 1,
    'value' => 2242
];

// Тестируем расчеты
echo "ТГ7 (blockId = 7):\n";
$gas7 = calculateGasFuelConsumption($testDate, $testShift, 7, $values);
$oil7 = calculateOilFuelConsumption($testDate, $testShift, 7, $values);
echo "Газ: " . ($gas7 ?? 'null') . "\n";
echo "Мазут: " . ($oil7 ?? 'null') . "\n\n";

echo "ТГ8 (blockId = 8):\n";
$gas8 = calculateGasFuelConsumption($testDate, $testShift, 8, $values);
$oil8 = calculateOilFuelConsumption($testDate, $testShift, 8, $values);
echo "Газ: " . ($gas8 ?? 'null') . "\n";
echo "Мазут: " . ($oil8 ?? 'null') . "\n\n";

echo "=== Тест завершен ===\n";
?>
