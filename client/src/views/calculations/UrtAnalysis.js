import React, { useState, useEffect, useMemo } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import {
  CCard,
  CCardBody,
  CCardHeader,
  CCol,
  CRow,
  CTable,
  CTableBody,
  CTableDataCell,
  CTableHead,
  CTableHeaderCell,
  CTableRow,
  CButton,
  CSpinner,
  CAlert,
  CBadge
} from '@coreui/react'
import { cilArrowLeft, cilFile, cilReload } from '@coreui/icons'
import CIcon from '@coreui/icons-react'
import urtAnalysisService from '../../services/urtAnalysisService'

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

// Группировка параметров УРТ
const groupUrtParameters = (params) => {
  const groups = {
    'urt_main': {
      name: 'Основные показатели УРТ',
      params: []
    },
    'urt_calculated': {
      name: 'Расчетные показатели УРТ',
      params: []
    },
    'urt_efficiency': {
      name: 'Показатели эффективности',
      params: []
    }
  }
  
  params.forEach(param => {
    const rowNum = parseInt(param.row_num, 10)
    
    if (rowNum >= 1 && rowNum <= 15) {
      groups.urt_main.params.push(param)
    } else if (rowNum >= 16 && rowNum <= 30) {
      groups.urt_calculated.params.push(param)
    } else {
      groups.urt_efficiency.params.push(param)
    }
  })
  
  return groups
}

const UrtAnalysis = () => {
  const location = useLocation()
  const navigate = useNavigate()
  
  const [loading, setLoading] = useState(true)
  const [params, setParams] = useState([])
  const [calculationResult, setCalculationResult] = useState(null)
  const [activeGroup, setActiveGroup] = useState('urt_main')
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
      
      // Объединяем параметры с их значениями
      const paramsWithValues = paramsData.map(param => {
        const paramValues = valuesData.find(p => p.id === param.id)
        return {
          ...param,
          values: paramValues?.values || {},
          valuesByShift: paramValues?.valuesByShift || {}
        }
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
      // Создаем данные для экспорта
      const exportData = []
      
      params.forEach(param => {
        const row = {
          'Показатель': param.name,
          'Единица': param.unit,
          'Символ': param.symbol
        }
        
        if (periodType === 'shift') {
          selectedShiftIds.forEach(shiftId => {
            const byShift = param.valuesByShift?.[shiftId] || {}
            row[`Блок 7 (смена ${shiftId})`] = formatValue(byShift[7]?.value)
            row[`Блок 8 (смена ${shiftId})`] = formatValue(byShift[8]?.value)
            row[`ПГУ 1 (смена ${shiftId})`] = formatValue(byShift[1]?.value)
            row[`ПГУ 2 (смена ${shiftId})`] = formatValue(byShift[2]?.value)
          })
        } else {
          row['Блок 7'] = formatValue(param.values?.[7]?.value)
          row['Блок 8'] = formatValue(param.values?.[8]?.value)
          row['ПГУ 1'] = formatValue(param.values?.[1]?.value)
          row['ПГУ 2'] = formatValue(param.values?.[2]?.value)
        }
        
        exportData.push(row)
      })
      
      // Создаем CSV
      const headers = Object.keys(exportData[0] || {})
      const csvContent = [
        headers.join(','),
        ...exportData.map(row => 
          headers.map(header => `"${row[header] || ''}"`).join(',')
        )
      ].join('\n')
      
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `urt_analysis_${date || 'report'}.csv`
      link.click()
      URL.revokeObjectURL(url)
    } catch (error) {
      console.error('Ошибка при экспорте в Excel:', error)
      alert('Ошибка при экспорте файла')
    }
  }

  const renderResultsTable = (groupParams) => {
    if (!groupParams || groupParams.length === 0) {
      return (
        <CTableRow>
          <CTableDataCell colSpan={3 + (periodType === 'shift' ? selectedShiftIds.length * 4 : 4)} className="text-center">
            Нет данных для отображения
          </CTableDataCell>
        </CTableRow>
      )
    }

    return groupParams.map((param) => (
      <CTableRow key={param.id}>
        <CTableDataCell><strong>{param.name}</strong></CTableDataCell>
        <CTableDataCell>{param.unit}</CTableDataCell>
        <CTableDataCell>{param.symbol}</CTableDataCell>
        {periodType === 'shift' ? (
          selectedShiftIds.flatMap(shiftId => {
            const byShift = param.valuesByShift?.[shiftId] || {}
            return [
              <CTableDataCell key={`block7-${param.id}-${shiftId}`}>{formatValue(byShift[7]?.value)}</CTableDataCell>,
              <CTableDataCell key={`block8-${param.id}-${shiftId}`}>{formatValue(byShift[8]?.value)}</CTableDataCell>,
              <CTableDataCell key={`pgu1-${param.id}-${shiftId}`}>{formatValue(byShift[1]?.value)}</CTableDataCell>,
              <CTableDataCell key={`pgu2-${param.id}-${shiftId}`}>{formatValue(byShift[2]?.value)}</CTableDataCell>
            ]
          })
        ) : (
          [
            <CTableDataCell key={`block7-${param.id}`}>{formatValue(param.values?.[7]?.value)}</CTableDataCell>,
            <CTableDataCell key={`block8-${param.id}`}>{formatValue(param.values?.[8]?.value)}</CTableDataCell>,
            <CTableDataCell key={`pgu1-${param.id}`}>{formatValue(param.values?.[1]?.value)}</CTableDataCell>,
            <CTableDataCell key={`pgu2-${param.id}`}>{formatValue(param.values?.[2]?.value)}</CTableDataCell>
          ]
        )}
      </CTableRow>
    ))
  }

  const groupedParams = useMemo(() => groupUrtParameters(params), [params])

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

            {/* Группы параметров */}
            <div className="mb-3">
              <div className="btn-group" role="group">
                {Object.entries(groupedParams).map(([key, group]) => (
                  <CButton
                    key={key}
                    color={activeGroup === key ? 'primary' : 'outline-primary'}
                    variant={activeGroup === key ? 'solid' : 'outline'}
                    onClick={() => setActiveGroup(key)}
                    className="me-2"
                  >
                    {group.name}
                    <CBadge color="light" className="ms-2">
                      {group.params.length}
                    </CBadge>
                  </CButton>
                ))}
              </div>
            </div>

            {/* Таблица результатов */}
            <CTable responsive striped hover>
              <CTableHead>
                <CTableRow>
                  <CTableHeaderCell>Показатель</CTableHeaderCell>
                  <CTableHeaderCell>Единица</CTableHeaderCell>
                  <CTableHeaderCell>Символ</CTableHeaderCell>
                  {periodType === 'shift' ? (
                    selectedShiftIds.flatMap(shiftId => [
                      <CTableHeaderCell key={`h-block7-${shiftId}`}>Блок 7 (смена {shiftId})</CTableHeaderCell>,
                      <CTableHeaderCell key={`h-block8-${shiftId}`}>Блок 8 (смена {shiftId})</CTableHeaderCell>,
                      <CTableHeaderCell key={`h-pgu1-${shiftId}`}>ПГУ 1 (смена {shiftId})</CTableHeaderCell>,
                      <CTableHeaderCell key={`h-pgu2-${shiftId}`}>ПГУ 2 (смена {shiftId})</CTableHeaderCell>
                    ])
                  ) : (
                    [
                      <CTableHeaderCell key="h-block7">Блок 7</CTableHeaderCell>,
                      <CTableHeaderCell key="h-block8">Блок 8</CTableHeaderCell>,
                      <CTableHeaderCell key="h-pgu1">ПГУ 1</CTableHeaderCell>,
                      <CTableHeaderCell key="h-pgu2">ПГУ 2</CTableHeaderCell>
                    ]
                  )}
                </CTableRow>
              </CTableHead>
              <CTableBody>
                {renderResultsTable(groupedParams[activeGroup]?.params)}
              </CTableBody>
            </CTable>
          </CCardBody>
        </CCard>
      </CCol>
    </CRow>
  )
}

export default UrtAnalysis
