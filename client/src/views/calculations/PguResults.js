import React, { useState, useEffect, useMemo } from 'react'
import {
  CCard,
  CCardBody,
  CCardHeader,
  CCol,
  CRow,
  CTable,
  CTableHead,
  CTableRow,
  CTableHeaderCell,
  CTableBody,
  CTableDataCell,
  CButton,
  CSpinner,
  CAlert
} from '@coreui/react'
import { useLocation, useNavigate } from 'react-router-dom'
import CIcon from '@coreui/icons-react'
import { cilArrowLeft, cilFile, cilCloudDownload } from '@coreui/icons'
import { getPguResultParams, getPguResultValues } from '../../services/pguResultsService'
import './PguResults.scss'

const shiftNameById = { 1: 'Смена 1', 2: 'Смена 2', 3: 'Смена 3' }
const mapShiftNameToId = (n) => ({ shift1: 1, shift2: 2, shift3: 3 }[n])

// Форматирование значений: для rows 54-69 = 4 знака, для остальных = 2 знака
const formatValue = (v, rowNum = null, paramId = null) => {
  if (v === null || v === undefined) return '-'
  const num = Number(v)
  if (Number.isNaN(num)) return '-'
  
  // Определяем количество знаков после запятой
  // Для rows 54-69: 4 знака, для остальных: 2 знака
  // Используем и rowNum и paramId для надежности
  const rowNumber = parseInt(rowNum, 10)
  const isHighPrecision = (rowNumber >= 54 && rowNumber <= 69) || 
                         (paramId >= 44 && paramId <= 59) // param_id для rows 54-69
  const decimalPlaces = isHighPrecision ? 4 : 2
  
  return num.toFixed(decimalPlaces)
}

const PguResults = () => {
  const location = useLocation()
  const navigate = useNavigate()
  
  const [loading, setLoading] = useState(true)
  const [params, setParams] = useState([])
  const [calculationResult, setCalculationResult] = useState(null)
  const [debugSpecialCells, setDebugSpecialCells] = useState(null)

  const searchParams = new URLSearchParams(location.search)
  const date = searchParams.get('date') || location.state?.date
  const periodType = searchParams.get('periodType') || location.state?.periodType
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
    if (calcResult) {
      setCalculationResult(calcResult)
      if (calcResult.debugSpecialCells) {
        setDebugSpecialCells(calcResult.debugSpecialCells)
      }
    }
    fetchData()
  }, [location.state])

  const fetchData = async () => {
    try {
      setLoading(true)
      const query = { date, periodType, pguIds: [1,2,3] }
      if (periodType === 'shift') query.shiftIds = selectedShiftIds
      const result = await getPguResultValues(query)
      if (result && result.params) setParams(result.params)
      else {
        const fallback = await getPguResultParams()
        setParams(fallback.params || [])
      }
    } catch (error) {
      console.error('Ошибка загрузки данных:', error)
      const fallback = await getPguResultParams()
      setParams(fallback.params || [])
    } finally {
      setLoading(false)
    }
  }

  const handleBack = () => navigate('/calculations')

  const handleExportExcel = () => {
    try {
      const header = ['Название', 'Ед. Измерения', 'Обозначение']
      const shiftsCols = periodType === 'shift' ? selectedShiftIds.flatMap(() => ['ПГУ1','ПГУ2','ТЭС']) : ['ПГУ1','ПГУ2','ТЭС']
      const excelData = [ [...header, ...(
        periodType === 'shift' ? selectedShiftIds.map(id => shiftNameById[id]).flatMap(name => [name,'','']) : []
      )] ]
      excelData.push([ '', '', '', ...shiftsCols ])

      params.forEach(param => {
        const row = [param.name, param.unit || '', param.symbol || '']
        if (periodType === 'shift') {
          selectedShiftIds.forEach(id => {
            const byShift = param.valuesByShift?.[id] || {}
                                    row.push(formatValue(byShift[1], param.row_num, param.id))
                        row.push(formatValue(byShift[2], param.row_num, param.id))
                        row.push(formatValue(byShift[3], param.row_num, param.id))
          })
        } else {
                      row.push(formatValue(param.values?.[1], param.row_num, param.id))
            row.push(formatValue(param.values?.[2], param.row_num, param.id))
            row.push(formatValue(param.values?.[3], param.row_num, param.id))
        }
        excelData.push(row)
      })

      const csvContent = excelData.map(r => r.map(c => `"${c}"`).join(',')).join('\n')
      const BOM = '\uFEFF'
      const blob = new Blob([BOM + csvContent], { type: 'text/csv;charset=utf-8;' })
      const link = document.createElement('a')
      const url = URL.createObjectURL(blob)
      link.setAttribute('href', url)
      link.setAttribute('download', `pgu_results_${date || new Date().toISOString().split('T')[0]}.csv`)
      link.style.visibility = 'hidden'
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      URL.revokeObjectURL(url)
    } catch (error) {
      console.error('Ошибка при экспорте в Excel:', error)
      alert('Ошибка при экспорте файла')
    }
  }

  const renderDebugSpecialCells = () => {
    if (!debugSpecialCells) return null

    return (
      <CCard className="mb-4">
        <CCardHeader>
          <h5>🔍 Debug: Special Cells - {debugSpecialCells.date}
            {debugSpecialCells.shift && ` (Смена ${debugSpecialCells.shift})`} 
            ({debugSpecialCells.periodType})
          </h5>
        </CCardHeader>
        <CCardBody>
          <CTable striped hover responsive>
            <CTableHead>
              <CTableRow>
                <CTableHeaderCell>Cell</CTableHeaderCell>
                <CTableHeaderCell>Название</CTableHeaderCell>
                <CTableHeaderCell>F (ПГУ1)</CTableHeaderCell>
                <CTableHeaderCell>G (ПГУ2)</CTableHeaderCell>
                <CTableHeaderCell>H (ТЭС)</CTableHeaderCell>
              </CTableRow>
            </CTableHead>
            <CTableBody>
              {debugSpecialCells.cells.map((cell) => (
                <CTableRow key={cell.row}>
                  <CTableDataCell><strong>{cell.row}</strong></CTableDataCell>
                  <CTableDataCell>{cell.title}</CTableDataCell>
                  <CTableDataCell className="text-end">
                    {cell.f !== null ? cell.f.toFixed(2) : '-'}
                  </CTableDataCell>
                  <CTableDataCell className="text-end">
                    {cell.g !== null ? cell.g.toFixed(2) : '-'}
                  </CTableDataCell>
                  <CTableDataCell className="text-end">
                    {cell.h !== null ? cell.h.toFixed(2) : '-'}
                  </CTableDataCell>
                </CTableRow>
              ))}
            </CTableBody>
          </CTable>
          <small className="text-muted">
            💡 Эти значения вычисляются динамически из equipment_events и meter_readings
          </small>
        </CCardBody>
      </CCard>
    )
  }

  return (
    <CRow className="pgu-results">
      <CCol xs={12}>
        <CCard className="mb-4">
          <CCardHeader>
            <CRow className="align-items-center">
              <CCol>
                <h4 className="mb-0">Результаты расчетов ПГУ</h4>
                <small className="text-muted">
                  {periodType === 'shift' ? 'Смена' : periodType === 'day' ? 'Сутки' : 'Период'} - {date}
                </small>
              </CCol>
              <CCol xs="auto">
                <div className="d-flex gap-2">
                  <CButton color="outline-secondary" size="sm" onClick={handleBack}>
                    <CIcon icon={cilArrowLeft} className="me-1" />
                    Назад
                  </CButton>
                  <CButton color="outline-success" size="sm" onClick={handleExportExcel} disabled={!params || params.length === 0}>
                    <CIcon icon={cilFile} className="me-1" />
                    Скачать Excel
                  </CButton>
                </div>
              </CCol>
            </CRow>
          </CCardHeader>
          <CCardBody>
            {calculationResult && (
              <CAlert color="success" className="mb-3">
                <strong>Расчет выполнен успешно!</strong><br />
                Рассчитано параметров: {calculationResult.calculatedParams}<br />
                Сохранено записей: {calculationResult.results}
              </CAlert>
            )}

            {renderDebugSpecialCells()}

            {loading ? (
              <div className="text-center py-4">
                <CSpinner />
                <p className="mt-2">Загрузка данных...</p>
              </div>
            ) : (
              <div className="table-scroll">
                <CTable hover responsive={false}>
                <CTableHead>
                    {periodType === 'shift' && (
                      <CTableRow>
                        <CTableHeaderCell scope="col" colSpan={3}></CTableHeaderCell>
                        {selectedShiftIds.map(id => (
                          <CTableHeaderCell key={`shg-${id}`} scope="col" colSpan={3} className="pgu-shift-header">
                            {shiftNameById[id]}
                          </CTableHeaderCell>
                        ))}
                      </CTableRow>
                    )}
                  <CTableRow>
                    <CTableHeaderCell scope="col">Название</CTableHeaderCell>
                    <CTableHeaderCell scope="col">Ед. Измерения</CTableHeaderCell>
                    <CTableHeaderCell scope="col">Обозначение</CTableHeaderCell>
                      {periodType === 'shift' ? (
                        selectedShiftIds.flatMap(id => (
                          [
                            <CTableHeaderCell key={`h1-${id}`} scope="col">ПГУ1</CTableHeaderCell>,
                            <CTableHeaderCell key={`h2-${id}`} scope="col">ПГУ2</CTableHeaderCell>,
                            <CTableHeaderCell key={`h3-${id}`} scope="col">ТЭС</CTableHeaderCell>
                          ]
                        ))
                      ) : (
                        [
                          <CTableHeaderCell key="h1" scope="col">ПГУ1</CTableHeaderCell>,
                          <CTableHeaderCell key="h2" scope="col">ПГУ2</CTableHeaderCell>,
                          <CTableHeaderCell key="h3" scope="col">ТЭС</CTableHeaderCell>
                        ]
                      )}
                  </CTableRow>
                </CTableHead>
                                  <CTableBody>
                    {params && params.length > 0 ? (
                      params.map((param) => (
                        <CTableRow key={param.id}>
                          <CTableDataCell><strong>{param.name}</strong></CTableDataCell>
                          <CTableDataCell>{param.unit}</CTableDataCell>
                          <CTableDataCell>{param.symbol}</CTableDataCell>
                          {periodType === 'shift' ? (
                            selectedShiftIds.flatMap(id => {
                              const byShift = param.valuesByShift?.[id] || {}
                              return [
                                <CTableDataCell key={`v1-${param.id}-${id}`}>{formatValue(byShift[1], param.row_num, param.id)}</CTableDataCell>,
                                <CTableDataCell key={`v2-${param.id}-${id}`}>{formatValue(byShift[2], param.row_num, param.id)}</CTableDataCell>,
                                <CTableDataCell key={`v3-${param.id}-${id}`}>{formatValue(byShift[3], param.row_num, param.id)}</CTableDataCell>
                              ]
                            })
                          ) : (
                            [
                              <CTableDataCell key={`v1-${param.id}`}>{formatValue(param.values?.[1], param.row_num, param.id)}</CTableDataCell>,
                              <CTableDataCell key={`v2-${param.id}`}>{formatValue(param.values?.[2], param.row_num, param.id)}</CTableDataCell>,
                              <CTableDataCell key={`v3-${param.id}`}>{formatValue(param.values?.[3], param.row_num, param.id)}</CTableDataCell>
                            ]
                          )}
                        </CTableRow>
                      ))
                    ) : (
                      <CTableRow>
                        <CTableDataCell colSpan={3 + (periodType === 'shift' ? selectedShiftIds.length * 3 : 3)} className="text-center">
                          Нет данных для отображения
                        </CTableDataCell>
                      </CTableRow>
                    )}
                  </CTableBody>
              </CTable>
              </div>
            )}
          </CCardBody>
        </CCard>
      </CCol>
    </CRow>
  )
}

export default PguResults 