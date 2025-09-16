<?php
require_once '/var/www/html/helpers/db.php';

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
                echo "Фактическое качество сожженного топлива (газ) для ОЧ-130: " . $result['value'] . "\n";
                return (float)$result['value'];
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        echo 'Ошибка при расчете фактического качества сожженного топлива (газ): ' . $e->getMessage() . "\n";
        return null;
    }
}

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
                echo "Фактическое качество сожженного топлива (мазут) для ОЧ-130: " . $result['value'] . "\n";
                return (float)$result['value'];
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        echo 'Ошибка при расчете фактического качества сожженного топлива (мазут): ' . $e->getMessage() . "\n";
        return null;
    }
}

// Тестируем для разных блоков и дат
$testDate = '2025-09-16';

echo "=== Тест расчетов котлов ===\n";
echo "Дата: $testDate\n\n";

// Тестируем все смены для ОЧ-130
for ($shift = 1; $shift <= 3; $shift++) {
    echo "=== Смена $shift ===\n";
    
    // ТГ7
    echo "ТГ7 (blockId = 7):\n";
    $gas7 = calculateGasFuelQuality($testDate, $shift, 7);
    $oil7 = calculateOilFuelQuality($testDate, $shift, 7);
    echo "Газ: " . ($gas7 ?? 'null') . "\n";
    echo "Мазут: " . ($oil7 ?? 'null') . "\n\n";

    // ТГ8
    echo "ТГ8 (blockId = 8):\n";
    $gas8 = calculateGasFuelQuality($testDate, $shift, 8);
    $oil8 = calculateOilFuelQuality($testDate, $shift, 8);
    echo "Газ: " . ($gas8 ?? 'null') . "\n";
    echo "Мазут: " . ($oil8 ?? 'null') . "\n\n";

    // ОЧ-130
    echo "ОЧ-130 (blockId = 9):\n";
    $gas9 = calculateGasFuelQuality($testDate, $shift, 9);
    $oil9 = calculateOilFuelQuality($testDate, $shift, 9);
    echo "Газ: " . ($gas9 ?? 'null') . "\n";
    echo "Мазут: " . ($oil9 ?? 'null') . "\n\n";
}

echo "=== Тест завершен ===\n";
?>
