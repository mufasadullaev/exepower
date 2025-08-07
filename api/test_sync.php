<?php
/**
 * Тестовый скрипт для проверки синхронизации meter_readings с pgu_fullparam_values
 */

require_once 'config.php';
require_once 'helpers/db.php';
require_once 'controllers/counters_controller.php';

echo "=== Тест синхронизации meter_readings -> pgu_fullparam_values ===\n\n";

try {
    $db = getDbConnection();
    
    // 1. Проверяем существующие данные
    echo "1. Проверка существующих данных:\n";
    
    // Счетчики выработки
    $stmt = $db->prepare('
        SELECT COUNT(*) as count 
        FROM meters 
        WHERE meter_type_id = 1
    ');
    $stmt->execute();
    $meterCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "   - Счетчиков выработки: $meterCount\n";
    
    // Показания счетчиков
    $stmt = $db->prepare('
        SELECT COUNT(*) as count 
        FROM meter_readings mr
        JOIN meters m ON mr.meter_id = m.id
        WHERE m.meter_type_id = 1
    ');
    $stmt->execute();
    $readingsCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "   - Записей показаний: $readingsCount\n";
    
    // Параметры ПГУ
    $stmt = $db->prepare('SELECT * FROM pgu_fullparams WHERE row_num IN (10, 11) ORDER BY row_num');
    $stmt->execute();
    $fullparams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($fullparams) {
        echo "   - Параметры ПГУ найдены:\n";
        foreach ($fullparams as $param) {
            echo "     row_num {$param['row_num']}: {$param['title']} (ID: {$param['id']})\n";
        }
    } else {
        echo "   - Параметры ПГУ НЕ НАЙДЕНЫ!\n";
        exit(1);
    }
    
    // Существующие записи в pgu_fullparam_values
    $stmt = $db->prepare('
        SELECT COUNT(*) as count 
        FROM pgu_fullparam_values 
        WHERE fullparam_id IN (SELECT id FROM pgu_fullparams WHERE row_num IN (10, 11))
    ');
    $stmt->execute();
    $existingCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "   - Существующих записей в pgu_fullparam_values: $existingCount\n\n";
    
    // 2. Тестируем синхронизацию для одной записи
    echo "2. Тест синхронизации одной записи:\n";
    
    // Берем первую запись показаний
    $stmt = $db->prepare('
        SELECT mr.*, m.equipment_id
        FROM meter_readings mr
        JOIN meters m ON mr.meter_id = m.id
        WHERE m.meter_type_id = 1
        LIMIT 1
    ');
    $stmt->execute();
    $testReading = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($testReading) {
        // Получаем информацию об оборудовании
        $stmt = $db->prepare('
            SELECT e.name as equipment_name
            FROM equipment e
            WHERE e.id = ?
        ');
        $stmt->execute([$testReading['equipment_id']]);
        $equipment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Определяем pgu_id
        $pguId = getPguIdFromEquipment($testReading['equipment_id']);
        $pguText = $pguId ? "ПГУ $pguId" : "не ПГУ";
        
        // Определяем тип оборудования и row_num
        $isGT = strpos($equipment['equipment_name'], 'ГТ') !== false;
        $rowNum = $isGT ? 10 : 11;
        $columnLetter = $pguId == 1 ? 'F' : 'G';
        
        echo "   - Тестовая запись: meter_id={$testReading['meter_id']}, date={$testReading['date']}, equipment={$equipment['equipment_name']} ($pguText)\n";
        echo "   - Маппинг: row_num=$rowNum, колонка=$columnLetter\n";
        echo "   - Значения: shift1={$testReading['shift1']}, shift2={$testReading['shift2']}, shift3={$testReading['shift3']}\n";
        
        // Выполняем синхронизацию
        syncMeterReadingsToPguFullParams($testReading['meter_id'], $testReading['date'], 1);
        echo "   - Синхронизация выполнена успешно\n";
        
        if ($pguId) {
            // Проверяем результат
            $stmt = $db->prepare('
                SELECT pfv.*, pfp.row_num
                FROM pgu_fullparam_values pfv
                JOIN pgu_fullparams pfp ON pfv.fullparam_id = pfp.id
                WHERE pfp.row_num = ? AND pfv.pgu_id = ? AND pfv.date = ?
                ORDER BY pfv.shift_id
            ');
            $stmt->execute([$rowNum, $pguId, $testReading['date']]);
            $syncedRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $syncedRecords = [];
        }
        
        echo "   - Создано записей: " . count($syncedRecords) . "\n";
        foreach ($syncedRecords as $record) {
            echo "     Смена {$record['shift_id']}: {$record['value']} (ячейка: {$record['cell']}, row_num: {$record['row_num']})\n";
        }
    } else {
        echo "   - Нет данных для тестирования\n";
    }
    
    echo "\n3. Тест массовой синхронизации:\n";
    
    // Выполняем массовую синхронизацию
    bulkSyncMeterReadingsToPguFullParams();
    echo "   - Массовая синхронизация выполнена\n";
    
    // Проверяем итоговый результат
    $stmt = $db->prepare('
        SELECT COUNT(*) as count 
        FROM pgu_fullparam_values 
        WHERE fullparam_id IN (SELECT id FROM pgu_fullparams WHERE row_num IN (10, 11))
    ');
    $stmt->execute();
    $finalCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "   - Итоговое количество записей в pgu_fullparam_values: $finalCount\n";
    
    echo "\n=== Тест завершен успешно ===\n";
    
} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
} 