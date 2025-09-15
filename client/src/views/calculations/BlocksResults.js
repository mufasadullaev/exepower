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
  CAlert,
  CNav,
  CNavItem,
  CNavLink,
  CTabContent,
  CTabPane
} from '@coreui/react'
import { useLocation, useNavigate } from 'react-router-dom'
import CIcon from '@coreui/icons-react'
import { cilArrowLeft, cilFile } from '@coreui/icons'
import { getBlocksResultParams, getBlocksResultValues } from '../../services/blocksResultsService'
import './PguResults.scss'

const shiftNameById = { 1: 'Смена 1', 2: 'Смена 2', 3: 'Смена 3' }
const mapShiftNameToId = (n) => ({ shift1: 1, shift2: 2, shift3: 3 }[n])

const formatValue = (v) => {
  if (v === null || v === undefined) return '-'
  const num = Number(v)
  if (Number.isNaN(num)) return '-'
  return num.toFixed(2)
}

// Группировка параметров по подгруппам
const groupParameters = (params) => {
  const groups = {
    turbo: {
      name: 'Турбоагрегаты',
      params: []
    },
    boilers: {
      name: 'Котлы',
      params: []
    },
    other: {
      name: 'Прочие параметры',
      params: []
    }
  }

  params.forEach(param => {
    // Группировка на основе поля category из базы данных
    switch (param.category) {
      case '3a':
        groups.turbo.params.push(param)
        break
      case '3b':
        groups.boilers.params.push(param)
        break
      case '4':
        groups.other.params.push(param)
        break
      default:
        // Если category не задана, используем резервную логику по названию
        if (param.name.includes('турбо') || param.name.includes('ТГ') || param.symbol?.includes('т') || 
            param.name.includes('пара') || param.name.includes('конденсатор') || param.name.includes('цилиндр')) {
          groups.turbo.params.push(param)
        } else if (param.name.includes('котёл') || param.name.includes('котла') || param.symbol?.includes('к') ||
                   param.name.includes('топливо') || param.name.includes('газ') || param.name.includes('воздух')) {
          groups.boilers.params.push(param)
        } else {
          groups.other.params.push(param)
        }
        break
    }
  })

  return groups
}

const BlocksResults = () => {
  const location = useLocation()
  const navigate = useNavigate()
  
  const [loading, setLoading] = useState(true)
  const [params, setParams] = useState([])
  const [calculationResult, setCalculationResult] = useState(null)
  const [activeGroup, setActiveGroup] = useState('turbo')

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
      // Используем переданную дату или текущую дату как fallback
      const queryDate = date || new Date().toLocaleDateString('en-CA') // YYYY-MM-DD format
      const queryPeriodType = periodType || 'day'
      const query = { date: queryDate, periodType: queryPeriodType, blockIds: [7,8,9] }
      if (queryPeriodType === 'shift') query.shiftIds = selectedShiftIds
      
      console.log('BlocksResults fetchData:', { 
        originalDate: date, 
        queryDate, 
        queryPeriodType, 
        query 
      })
      
      const result = await getBlocksResultValues(query)
      if (result && result.params) setParams(result.params)
      else {
        const fallback = await getBlocksResultParams()
        setParams(fallback.params || [])
      }
    } catch (error) {
      console.error('Ошибка загрузки данных:', error)
      const fallback = await getBlocksResultParams()
      setParams(fallback.params || [])
    } finally {
      setLoading(false)
    }
  }

  const handleBack = () => navigate('/calculations')

  const handleExportExcel = () => {
    try {
      const header = ['Название', 'Ед. Измерения', 'Обозначение']
      const shiftsCols = periodType === 'shift' ? selectedShiftIds.flatMap(() => ['ТГ7','ТГ8','ОЧ-130']) : ['ТГ7','ТГ8','ОЧ-130']
      const excelData = [ [...header, ...(
        periodType === 'shift' ? selectedShiftIds.map(id => shiftNameById[id]).flatMap(name => [name,'','']) : []
      )] ]
      excelData.push([ '', '', '', ...shiftsCols ])

      params.forEach(param => {
        const row = [param.name, param.unit || '', param.symbol || '']
        if (periodType === 'shift') {
          selectedShiftIds.forEach(id => {
            const byShift = param.valuesByShift?.[id] || {}
            const tg7Value = byShift[7] || 0
            const tg8Value = byShift[8] || 0
            const och130Value = byShift[9] || 0
            row.push(formatValue(tg7Value))
            row.push(formatValue(tg8Value))
            row.push(formatValue(och130Value))
          })
        } else {
          const tg7Value = param.values?.[7] || 0
          const tg8Value = param.values?.[8] || 0
          const och130Value = param.values?.[9] || 0
          row.push(formatValue(tg7Value))
          row.push(formatValue(tg8Value))
          row.push(formatValue(och130Value))
        }
        excelData.push(row)
      })

      const csvContent = excelData.map(r => r.map(c => `"${c}"`).join(',')).join('\n')
      const BOM = '\uFEFF'
      const blob = new Blob([BOM + csvContent], { type: 'text/csv;charset=utf-8;' })
      const link = document.createElement('a')
      const url = URL.createObjectURL(blob)
      link.setAttribute('href', url)
      link.setAttribute('download', `blocks_results_${date || new Date().toISOString().split('T')[0]}.csv`)
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

  const renderResultsTable = (groupParams) => {
    if (!groupParams || groupParams.length === 0) {
      return (
        <CTableRow>
          <CTableDataCell colSpan={3 + (periodType === 'shift' ? selectedShiftIds.length * 3 : 3)} className="text-center">
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
          selectedShiftIds.flatMap(id => {
            const byShift = param.valuesByShift?.[id] || {}
            const tg7Value = byShift[7] || 0
            const tg8Value = byShift[8] || 0
            const och130Value = byShift[9] || 0
            return [
              <CTableDataCell key={`v1-${param.id}-${id}`}>{formatValue(tg7Value)}</CTableDataCell>,
              <CTableDataCell key={`v2-${param.id}-${id}`}>{formatValue(tg8Value)}</CTableDataCell>,
              <CTableDataCell key={`v3-${param.id}-${id}`}>{formatValue(och130Value)}</CTableDataCell>
            ]
          })
        ) : (
          [
            <CTableDataCell key={`v1-${param.id}`}>{formatValue(param.values?.[7])}</CTableDataCell>,
            <CTableDataCell key={`v2-${param.id}`}>{formatValue(param.values?.[8])}</CTableDataCell>,
            <CTableDataCell key={`v3-${param.id}`}>{formatValue(param.values?.[9])}</CTableDataCell>
          ]
        )}
      </CTableRow>
    ))
  }

  const groupedParams = useMemo(() => groupParameters(params), [params])

  return (
    <CRow className="pgu-results">
      <CCol xs={12}>
        <CCard className="mb-4">
          <CCardHeader>
            <CRow className="align-items-center">
              <CCol>
                <h4 className="mb-0">Результаты расчетов Блоков</h4>
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

            {loading ? (
              <div className="text-center py-4">
                <CSpinner />
                <p className="mt-2">Загрузка данных...</p>
              </div>
            ) : (
              <>
                {/* Табы для подгрупп */}
                <CNav variant="tabs" className="mb-4">
                  <CNavItem>
                    <CNavLink
                      active={activeGroup === 'turbo'}
                      onClick={() => setActiveGroup('turbo')}
                      style={{ cursor: 'pointer' }}
                    >
                      {groupedParams.turbo.name} ({groupedParams.turbo.params.length})
                    </CNavLink>
                  </CNavItem>
                  <CNavItem>
                    <CNavLink
                      active={activeGroup === 'boilers'}
                      onClick={() => setActiveGroup('boilers')}
                      style={{ cursor: 'pointer' }}
                    >
                      {groupedParams.boilers.name} ({groupedParams.boilers.params.length})
                    </CNavLink>
                  </CNavItem>
                  <CNavItem>
                    <CNavLink
                      active={activeGroup === 'other'}
                      onClick={() => setActiveGroup('other')}
                      style={{ cursor: 'pointer' }}
                    >
                      {groupedParams.other.name} ({groupedParams.other.params.length})
                    </CNavLink>
                  </CNavItem>
                </CNav>

                {/* Контент табов */}
                <CTabContent>
                  <CTabPane visible={activeGroup === 'turbo'}>
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
                                  <CTableHeaderCell key={`h1-${id}`} scope="col">ТГ7</CTableHeaderCell>,
                                  <CTableHeaderCell key={`h2-${id}`} scope="col">ТГ8</CTableHeaderCell>,
                                  <CTableHeaderCell key={`h3-${id}`} scope="col">ОЧ-130</CTableHeaderCell>
                                ]
                              ))
                            ) : (
                              [
                                <CTableHeaderCell key="h1" scope="col">ТГ7</CTableHeaderCell>,
                                <CTableHeaderCell key="h2" scope="col">ТГ8</CTableHeaderCell>,
                                <CTableHeaderCell key="h3" scope="col">ОЧ-130</CTableHeaderCell>
                              ]
                            )}
                          </CTableRow>
                        </CTableHead>
                        <CTableBody>
                          {renderResultsTable(groupedParams.turbo.params)}
                        </CTableBody>
                      </CTable>
                    </div>
                  </CTabPane>

                  <CTabPane visible={activeGroup === 'boilers'}>
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
                                  <CTableHeaderCell key={`h1-${id}`} scope="col">ТГ7</CTableHeaderCell>,
                                  <CTableHeaderCell key={`h2-${id}`} scope="col">ТГ8</CTableHeaderCell>,
                                  <CTableHeaderCell key={`h3-${id}`} scope="col">ОЧ-130</CTableHeaderCell>
                                ]
                              ))
                            ) : (
                              [
                                <CTableHeaderCell key="h1" scope="col">ТГ7</CTableHeaderCell>,
                                <CTableHeaderCell key="h2" scope="col">ТГ8</CTableHeaderCell>,
                                <CTableHeaderCell key="h3" scope="col">ОЧ-130</CTableHeaderCell>
                              ]
                            )}
                          </CTableRow>
                        </CTableHead>
                        <CTableBody>
                          {renderResultsTable(groupedParams.boilers.params)}
                        </CTableBody>
                      </CTable>
                    </div>
                  </CTabPane>

                  <CTabPane visible={activeGroup === 'other'}>
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
                                  <CTableHeaderCell key={`h1-${id}`} scope="col">ТГ7</CTableHeaderCell>,
                                  <CTableHeaderCell key={`h2-${id}`} scope="col">ТГ8</CTableHeaderCell>,
                                  <CTableHeaderCell key={`h3-${id}`} scope="col">ОЧ-130</CTableHeaderCell>
                                ]
                              ))
                            ) : (
                              [
                                <CTableHeaderCell key="h1" scope="col">ТГ7</CTableHeaderCell>,
                                <CTableHeaderCell key="h2" scope="col">ТГ8</CTableHeaderCell>,
                                <CTableHeaderCell key="h3" scope="col">ОЧ-130</CTableHeaderCell>
                              ]
                            )}
                          </CTableRow>
                        </CTableHead>
                        <CTableBody>
                          {renderResultsTable(groupedParams.other.params)}
                        </CTableBody>
                      </CTable>
                    </div>
                  </CTabPane>
                </CTabContent>
              </>
            )}
          </CCardBody>
        </CCard>
      </CCol>
    </CRow>
  )
}

export default BlocksResults 