import React from 'react'
import {
  CTable,
  CTableBody,
  CTableDataCell,
  CTableHead,
  CTableHeaderCell,
  CTableRow,
} from '@coreui/react'

// Форматирование значений
const formatValue = (v) => {
  if (v === null || v === undefined) return '-'
  const num = Number(v)
  if (Number.isNaN(num)) return '-'
  
  // Для УРТ параметров используем 4 знака после запятой
  const decimalPlaces = 4
  
  return num.toFixed(decimalPlaces)
}

const UrtAnalysisTable = ({ params, periodType, selectedShiftIds }) => {
  // Функция для рендеринга ячеек значений для блока
  const renderBlockCells = (param, blockId, shiftId = null) => {
    const values = shiftId 
      ? param.valuesByShift?.[shiftId]?.[blockId] 
      : param.values?.[blockId]

    const keySuffix = shiftId ? `-s${shiftId}` : ''
    
    // Для ТГ7, ТГ8, ПГУ1 и ПГУ2 показываем Норма, Факт, dbэ
    if (blockId === 7 || blockId === 8 || blockId === 1 || blockId === 2) {
      if (!values) {
        return [
          <CTableDataCell key={`norm-${blockId}-${param.id}${keySuffix}`}>-</CTableDataCell>,
          <CTableDataCell key={`fact-${blockId}-${param.id}${keySuffix}`}>-</CTableDataCell>,
          <CTableDataCell key={`db-${blockId}-${param.id}${keySuffix}`}>-</CTableDataCell>
        ]
      }
      
      const normValue = formatValue(values.norm)
      const factValue = formatValue(values.fact)
      const dbValue = formatValue(values.db3)
      
      // Определяем цвет для отклонения
      const dbColor = values.db3 > 0 ? 'text-danger' : values.db3 < 0 ? 'text-success' : ''
      
      return [
        <CTableDataCell key={`norm-${blockId}-${param.id}${keySuffix}`}>{normValue}</CTableDataCell>,
        <CTableDataCell key={`fact-${blockId}-${param.id}${keySuffix}`}>{factValue}</CTableDataCell>,
        <CTableDataCell key={`db-${blockId}-${param.id}${keySuffix}`} className={dbColor}>{dbValue}</CTableDataCell>
      ]
    } 
    // Для остальных блоков показываем только значение
    else {
      if (!values) {
        return <CTableDataCell key={`value-${blockId}-${param.id}${keySuffix}`}>-</CTableDataCell>
      }
      
      // Используем fact значение для отображения, если есть, иначе value
      const value = formatValue(values.fact !== null && values.fact !== undefined ? values.fact : values.value)
      
      return <CTableDataCell key={`value-${blockId}-${param.id}${keySuffix}`}>{value}</CTableDataCell>
    }
  }

  if (!params || params.length === 0) {
    return (
      <CTableRow>
        <CTableDataCell colSpan={25} className="text-center">
          Нет данных для отображения
        </CTableDataCell>
      </CTableRow>
    )
  }

  // Формируем заголовки в зависимости от типа периода
  const renderTableHeaders = () => {
    if (periodType === 'shift') {
      // Вычисляем общее количество колонок для каждой смены
      const columnsPerShift = 18; // 3 для ТГ7 + 3 для ТГ8 + 3 для ПГУ1 + 3 для ПГУ2 + 6 одиночных колонок
      
      return (
        <CTableHead>
          {/* Первая строка - группировка по сменам */}
          <CTableRow>
            <CTableHeaderCell rowSpan={3}>Показатель</CTableHeaderCell>
            <CTableHeaderCell rowSpan={3}>Ед. изм.</CTableHeaderCell>
            {selectedShiftIds.map(shiftId => (
              <CTableHeaderCell key={`shift-${shiftId}`} colSpan={columnsPerShift}>
                Смена {shiftId}
              </CTableHeaderCell>
            ))}
          </CTableRow>
          
          {/* Вторая строка - группировка по блокам */}
          <CTableRow>
            {selectedShiftIds.map(shiftId => (
              <React.Fragment key={`blocks-shift-${shiftId}`}>
                <CTableHeaderCell colSpan={3}>ТГ7</CTableHeaderCell>
                <CTableHeaderCell colSpan={3}>ТГ8</CTableHeaderCell>
                <CTableHeaderCell>Блокам</CTableHeaderCell>
                <CTableHeaderCell colSpan={3}>ПГУ1</CTableHeaderCell>
                <CTableHeaderCell colSpan={3}>ПГУ2</CTableHeaderCell>
                <CTableHeaderCell>ПГУ</CTableHeaderCell>
                <CTableHeaderCell>ФЭС</CTableHeaderCell>
                <CTableHeaderCell>Станции</CTableHeaderCell>
              </React.Fragment>
            ))}
          </CTableRow>
          
          {/* Третья строка - типы значений (Норма, Факт, dbэ) */}
          <CTableRow>
            {selectedShiftIds.map(shiftId => (
              <React.Fragment key={`values-shift-${shiftId}`}>
                {/* ТГ7 */}
                <CTableHeaderCell>Норма</CTableHeaderCell>
                <CTableHeaderCell>Факт.</CTableHeaderCell>
                <CTableHeaderCell>dbэ</CTableHeaderCell>
                
                {/* ТГ8 */}
                <CTableHeaderCell>Норма</CTableHeaderCell>
                <CTableHeaderCell>Факт.</CTableHeaderCell>
                <CTableHeaderCell>dbэ</CTableHeaderCell>
                
                {/* Блокам - пустой заголовок */}
                <CTableHeaderCell></CTableHeaderCell>
                
                {/* ПГУ1 */}
                <CTableHeaderCell>Норма</CTableHeaderCell>
                <CTableHeaderCell>Факт.</CTableHeaderCell>
                <CTableHeaderCell>dbэ</CTableHeaderCell>
                
                {/* ПГУ2 */}
                <CTableHeaderCell>Норма</CTableHeaderCell>
                <CTableHeaderCell>Факт.</CTableHeaderCell>
                <CTableHeaderCell>dbэ</CTableHeaderCell>
                
                {/* Other blocks have empty headers */}
                <CTableHeaderCell></CTableHeaderCell>
                <CTableHeaderCell></CTableHeaderCell>
                <CTableHeaderCell></CTableHeaderCell>
              </React.Fragment>
            ))}
          </CTableRow>
        </CTableHead>
      );
    } else {
      return (
        <CTableHead>
          <CTableRow>
            <CTableHeaderCell rowSpan={2}>Показатель</CTableHeaderCell>
            <CTableHeaderCell rowSpan={2}>Ед. изм.</CTableHeaderCell>
            <CTableHeaderCell colSpan={3}>ТГ7</CTableHeaderCell>
            <CTableHeaderCell colSpan={3}>ТГ8</CTableHeaderCell>
            <CTableHeaderCell>Блокам</CTableHeaderCell>
            <CTableHeaderCell colSpan={3}>ПГУ1</CTableHeaderCell>
            <CTableHeaderCell colSpan={3}>ПГУ2</CTableHeaderCell>
            <CTableHeaderCell>ПГУ</CTableHeaderCell>
            <CTableHeaderCell>ФЭС</CTableHeaderCell>
            <CTableHeaderCell>Станции</CTableHeaderCell>
          </CTableRow>
          <CTableRow>
            {/* ТГ7 */}
            <CTableHeaderCell>Норма</CTableHeaderCell>
            <CTableHeaderCell>Факт.</CTableHeaderCell>
            <CTableHeaderCell>dbэ</CTableHeaderCell>
            
            {/* ТГ8 */}
            <CTableHeaderCell>Норма</CTableHeaderCell>
            <CTableHeaderCell>Факт.</CTableHeaderCell>
            <CTableHeaderCell>dbэ</CTableHeaderCell>
            
            {/* Блокам - пустой заголовок */}
            <CTableHeaderCell></CTableHeaderCell>
            
            {/* ПГУ1 */}
            <CTableHeaderCell>Норма</CTableHeaderCell>
            <CTableHeaderCell>Факт.</CTableHeaderCell>
            <CTableHeaderCell>dbэ</CTableHeaderCell>
            
            {/* ПГУ2 */}
            <CTableHeaderCell>Норма</CTableHeaderCell>
            <CTableHeaderCell>Факт.</CTableHeaderCell>
            <CTableHeaderCell>dbэ</CTableHeaderCell>
            
            {/* Other blocks have empty headers */}
            <CTableHeaderCell></CTableHeaderCell>
            <CTableHeaderCell></CTableHeaderCell>
            <CTableHeaderCell></CTableHeaderCell>
          </CTableRow>
        </CTableHead>
      );
    }
  };

  return (
    <CTable responsive striped hover className="urt-analysis-table">
      {renderTableHeaders()}
      <CTableBody>
        {params.map((param) => (
          <CTableRow key={param.id}>
            <CTableDataCell><strong>{param.name}</strong></CTableDataCell>
            <CTableDataCell>{param.unit}</CTableDataCell>
            
            {/* Если по сменам, то выводим данные для каждой смены */}
            {periodType === 'shift' ? (
              selectedShiftIds.flatMap(shiftId => {
                return [
                  // ТГ7 (block_id = 7) - три колонки (Норма, Факт, dbэ)
                  ...renderBlockCells(param, 7, shiftId),
                  
                  // ТГ8 (block_id = 8) - три колонки (Норма, Факт, dbэ)
                  ...renderBlockCells(param, 8, shiftId),
                  
                  // по Блокам (block_id = 9) - одна колонка
                  renderBlockCells(param, 9, shiftId),
                  
                  // ПГУ1 (block_id = 1) - три колонки (Норма, Факт, dbэ)
                  ...renderBlockCells(param, 1, shiftId),
                  
                  // ПГУ2 (block_id = 2) - три колонки (Норма, Факт, dbэ)
                  ...renderBlockCells(param, 2, shiftId),
                  
                  // по ПГУ (block_id = 3) - одна колонка
                  renderBlockCells(param, 3, shiftId),
                  
                  // ФЭС (block_id = 4) - одна колонка
                  renderBlockCells(param, 4, shiftId),
                  
                  // по Станции (block_id = 5) - одна колонка
                  renderBlockCells(param, 5, shiftId)
                ]
              })
            ) : (
              // Для суточных и периодных данных
              [
                // ТГ7 (block_id = 7) - три колонки (Норма, Факт, dbэ)
                ...renderBlockCells(param, 7),
                
                // ТГ8 (block_id = 8) - три колонки (Норма, Факт, dbэ)
                ...renderBlockCells(param, 8),
                
                // по Блокам (block_id = 9) - одна колонка
                renderBlockCells(param, 9),
                
                // ПГУ1 (block_id = 1) - три колонки (Норма, Факт, dbэ)
                ...renderBlockCells(param, 1),
                
                // ПГУ2 (block_id = 2) - три колонки (Норма, Факт, dbэ)
                ...renderBlockCells(param, 2),
                
                // по ПГУ (block_id = 3) - одна колонка
                renderBlockCells(param, 3),
                
                // ФЭС (block_id = 4) - одна колонка
                renderBlockCells(param, 4),
                
                // по Станции (block_id = 5) - одна колонка
                renderBlockCells(param, 5)
              ]
            )}
          </CTableRow>
        ))}
      </CTableBody>
    </CTable>
  )
}

export default UrtAnalysisTable
