import React, { useState, useEffect, useMemo } from 'react'
import * as XLSX from 'xlsx'
import { useLocation, useNavigate } from 'react-router-dom'
import {
  CCard,
  CCardBody,
  CCardHeader,
  CCol,
  CRow,
  CButton,
  CSpinner,
  CAlert
} from '@coreui/react'
import { cilArrowLeft, cilFile, cilReload } from '@coreui/icons'
import CIcon from '@coreui/icons-react'
import urtAnalysisService from '../../services/urtAnalysisService'
import UrtAnalysisTable from './UrtAnalysisTable'

// Маппинг смен
const mapShiftNameToId = (n) => ({ shift1: 1, shift2: 2, shift3: 3 }[n])

// Форматирование значений
const formatValue = (v, rowNum = null, paramId = null) => {
  if (v === null || v === undefined) return '-'
  const num = Number(v)
  if (Number.isNaN(num)) return '-'
  
  // Для УРТ параметров используем 4 знака после запятой
  const decimalPlaces = 4
  
  return num.toFixed(decimalPlaces)
}

// Сортировка параметров УРТ по row_num
const sortUrtParameters = (params) => {
  return [...params].sort((a, b) => {
    const rowNumA = parseInt(a.row_num, 10) || 999;
    const rowNumB = parseInt(b.row_num, 10) || 999;
    return rowNumA - rowNumB;
  });
}

const UrtAnalysis = () => {
  const location = useLocation()
  const navigate = useNavigate()
  
  const [loading, setLoading] = useState(true)
  const [params, setParams] = useState([])
  const [calculationResult, setCalculationResult] = useState(null)
  const [error, setError] = useState(null)

  const searchParams = new URLSearchParams(location.search)
  const date = searchParams.get('date') || location.state?.date
  const periodType = searchParams.get('periodType') || location.state?.periodType || 'day'
  const shiftsStr = searchParams.get('shifts') || location.state?.shifts
  const calcData = location.state?.calculationData

  const selectedShiftIds = useMemo(() => {
    if (periodType !== 'shift') return []
    const fromCalc = Array.isArray(calcData?.shifts) ? calcData.shifts : []
    if (fromCalc.length) return fromCalc.map(mapShiftNameToId).filter(Boolean)
    if (typeof shiftsStr === 'string') {
      return shiftsStr.split(',').map(s => s.trim()).map(n => ({ 'Смена 1':1, 'Смена 2':2, 'Смена 3':3 }[n])).filter(Boolean)
    }
    return [1,2,3]
  }, [periodType, calcData, shiftsStr])

  useEffect(() => {
    const calcResult = location.state?.calculationResult
    if (calcResult) setCalculationResult(calcResult)
    fetchData()
  }, [location.state])

  const fetchData = async () => {
    try {
      setLoading(true)
      setError(null)
      
      // Получаем параметры УРТ
      const paramsData = await urtAnalysisService.getUrtAnalysisParams()
      setParams(paramsData)
      
      // Получаем значения УРТ
      const valuesData = await urtAnalysisService.getUrtAnalysisValues({
        date,
        periodType,
        shifts: selectedShiftIds
      })
      
      console.log('URT Analysis: Получены параметры:', paramsData.length)
      console.log('URT Analysis: Получены значения:', valuesData?.length || 0, valuesData)
      
      // Объединяем параметры с их значениями
      const paramsWithValues = paramsData.map(param => {
        const paramValues = valuesData?.find(p => p.id === param.id)
        const result = {
          ...param,
          values: paramValues?.values || {},
          valuesByShift: paramValues?.valuesByShift || {}
        }
        console.log(`URT Analysis: Параметр ${param.name} (id=${param.id}):`, {
          hasParamValues: !!paramValues,
          valuesCount: Object.keys(result.values).length,
          valuesByShiftCount: Object.keys(result.valuesByShift).length
        })
        return result
      })
      
      setParams(paramsWithValues)
    } catch (err) {
      console.error('Ошибка при загрузке данных УРТ:', err)
      setError(err.message || 'Ошибка при загрузке данных')
    } finally {
      setLoading(false)
    }
  }

  const handleBack = () => {
    navigate('/calculations')
  }

  const handleRefresh = () => {
    fetchData()
  }

  const handleExportExcel = () => {
    try {
      // Создаем данные для экспорта с новой структурой
      const exportData = []
      
      sortedParams.forEach(param => {
        const row = {
          'Показатель': param.name,
          'Единица': param.unit,
          'Символ': param.symbol
        }
        
        // Добавляем данные для каждого блока
        const blocksWithNormFactDb = [
          { id: 7, name: 'ТГ7' },
          { id: 8, name: 'ТГ8' },
          { id: 1, name: 'ПГУ1' },
          { id: 2, name: 'ПГУ2' }
        ]
        
        const otherBlocks = [
          { id: 9, name: 'по Блокам' },
          { id: 3, name: 'по ПГУ' },
          { id: 4, name: 'ФЭС' },
          { id: 5, name: 'по Станции' }
        ]
        
        if (periodType === 'shift') {
          selectedShiftIds.forEach(shiftId => {
            // Для ТГ7, ТГ8, ПГУ1 и ПГУ2 показываем Норма, Факт, dbэ
            blocksWithNormFactDb.forEach(block => {
              const values = param.valuesByShift?.[shiftId]?.[block.id] || {}
              row[`${block.name} (смена ${shiftId}) - Норма`] = formatValue(values.norm)
              row[`${block.name} (смена ${shiftId}) - Факт`] = formatValue(values.fact)
              row[`${block.name} (смена ${shiftId}) - dbэ`] = formatValue(values.db3)
            })
            
            // Для остальных блоков только значение (fact)
            otherBlocks.forEach(block => {
              const values = param.valuesByShift?.[shiftId]?.[block.id] || {}
              row[`${block.name} (смена ${shiftId})`] = formatValue(values.fact)
            })
          })
        } else {
          // Для ТГ7, ТГ8, ПГУ1 и ПГУ2 показываем Норма, Факт, dbэ
          blocksWithNormFactDb.forEach(block => {
            const values = param.values?.[block.id] || {}
            row[`${block.name} - Норма`] = formatValue(values.norm)
            row[`${block.name} - Факт`] = formatValue(values.fact)
            row[`${block.name} - dbэ`] = formatValue(values.db3)
          })
          
          // Для остальных блоков только значение (fact)
          otherBlocks.forEach(block => {
            const values = param.values?.[block.id] || {}
            row[`${block.name}`] = formatValue(values.fact)
          })
        }
        
        exportData.push(row)
      })
      
      // Создаем Excel файл
      const worksheet = XLSX.utils.json_to_sheet(exportData)
      const workbook = XLSX.utils.book_new()
      
      // Вспомогательная функция для применения стилей
      const applyCellStyle = (worksheet, cell, style) => {
        if (!worksheet[cell]) return
        if (!worksheet[cell].s) worksheet[cell].s = {}
        Object.assign(worksheet[cell].s, style)
      }
      
      // Стили для границ
      const borderStyle = {
        top: { style: 'thin', color: { rgb: '000000' } },
        bottom: { style: 'thin', color: { rgb: '000000' } },
        left: { style: 'thin', color: { rgb: '000000' } },
        right: { style: 'thin', color: { rgb: '000000' } }
      }
      
      // Настройка ширины колонок
      const headers = Object.keys(exportData[0] || {})
      const colWidths = headers.map((header, idx) => {
        if (idx === 0) return { wch: 40 } // Показатель - широкая колонка
        if (idx === 1) return { wch: 15 } // Единица
        if (idx === 2) return { wch: 15 } // Символ
        return { wch: 12 } // Остальные колонки
      })
      worksheet['!cols'] = colWidths
      
      // Форматирование заголовков (жирный, фон, центрирование, границы)
      const headerRowIndex = 0
      headers.forEach((header, col) => {
        const cell = XLSX.utils.encode_cell({ r: headerRowIndex, c: col })
        applyCellStyle(worksheet, cell, {
          font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' } },
          alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
          fill: { fgColor: { rgb: '4472C4' } },
          border: borderStyle
        })
      })
      
      // Форматирование данных (границы, выравнивание, чередование цветов)
      for (let row = 1; row <= exportData.length; row++) {
        headers.forEach((header, col) => {
          const cell = XLSX.utils.encode_cell({ r: row, c: col })
          if (worksheet[cell]) {
            applyCellStyle(worksheet, cell, {
              border: borderStyle,
              alignment: col === 0 ? { horizontal: 'left', vertical: 'center' } : { horizontal: 'center', vertical: 'center' }
            })
            // Чередование цветов строк
            if (row % 2 === 0) {
              applyCellStyle(worksheet, cell, {
                fill: { fgColor: { rgb: 'F9F9F9' } }
              })
            }
          }
        })
      }
      
      XLSX.utils.book_append_sheet(workbook, worksheet, 'Анализ УРТ')
      
      // Генерируем файл и скачиваем
      XLSX.writeFile(workbook, `urt_analysis_${date || 'report'}.xlsx`)
    } catch (error) {
      console.error('Ошибка при экспорте в Excel:', error)
      alert('Ошибка при экспорте файла')
    }
  }

  // Используем новый компонент UrtAnalysisTable для всех параметров
  const renderUrtAnalysisTable = () => {
    return <UrtAnalysisTable 
      params={sortedParams} 
      periodType={periodType} 
      selectedShiftIds={selectedShiftIds} 
    />
  }

  const sortedParams = useMemo(() => sortUrtParameters(params), [params])

  if (loading) {
    return (
      <CRow>
        <CCol>
          <div className="d-flex justify-content-center align-items-center" style={{ height: '200px' }}>
            <CSpinner />
          </div>
        </CCol>
      </CRow>
    )
  }

  return (
    <CRow className="urt-analysis">
      <CCol>
        <CCard>
          <CCardHeader>
            <div className="d-flex justify-content-between align-items-center">
              <div>
                <h4>Анализ УРТ (Удельный Расход Топлива)</h4>
                <p className="text-muted mb-0">
                  Дата: {date || 'Не указана'} | 
                  Период: {periodType === 'shift' ? 'По сменам' : periodType === 'day' ? 'По дням' : 'По периоду'}
                  {periodType === 'shift' && selectedShiftIds.length > 0 && (
                    <span> | Смены: {selectedShiftIds.join(', ')}</span>
                  )}
                </p>
              </div>
              <div>
                <CButton
                  color="secondary"
                  variant="outline"
                  className="me-2"
                  onClick={handleBack}
                >
                  <CIcon icon={cilArrowLeft} className="me-1" />
                  Назад
                </CButton>
                <CButton
                  color="info"
                  variant="outline"
                  className="me-2"
                  onClick={handleRefresh}
                >
                  <CIcon icon={cilReload} className="me-1" />
                  Обновить
                </CButton>
                <CButton
                  color="success"
                  variant="outline"
                  onClick={handleExportExcel}
                >
                  <CIcon icon={cilFile} className="me-1" />
                  Экспорт
                </CButton>
              </div>
            </div>
          </CCardHeader>
          <CCardBody>
            {error && (
              <CAlert color="danger" className="mb-3">
                {error}
              </CAlert>
            )}

            {calculationResult && (
              <CAlert color="success" className="mb-3">
                <strong>Расчет выполнен успешно!</strong> Рассчитано параметров: {calculationResult.calculatedParams}
              </CAlert>
            )}

            {/* Таблица результатов */}
            {renderUrtAnalysisTable()}
          </CCardBody>
        </CCard>
      </CCol>
    </CRow>
  )
}

export default UrtAnalysis
