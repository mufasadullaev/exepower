import React, { useState, useEffect } from 'react'
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
  CSpinner,
  CBadge
} from '@coreui/react'
import { dashboardService } from '../../services/dashboardService'

const Dashboard = () => {
  const [loading, setLoading] = useState(true)
  const [dashboardRows, setDashboardRows] = useState([])
  const [activeShifts, setActiveShifts] = useState([])
  const [powerStats, setPowerStats] = useState({
    generation: 0,
    consumption: 0
  })
  const [workingHours, setWorkingHours] = useState([])

  useEffect(() => {
    loadDashboardData()
    // Обновляем данные каждые 5 минут
    const interval = setInterval(loadDashboardData, 5 * 60 * 1000)
    return () => clearInterval(interval)
  }, [])

  const loadDashboardData = async () => {
    try {
      setLoading(true)
      const response = await dashboardService.getDashboardData()
      const data = response.data

      setDashboardRows(data.dashboardRows)
      setActiveShifts(data.activeShifts)
      setPowerStats(data.powerStats)
      setWorkingHours(data.workingHours)
    } catch (error) {
      console.error('Error loading dashboard data:', error)
    } finally {
      setLoading(false)
    }
  }

  const getStatusBadge = (status) => {
    if (status.includes('Запущен') || status.includes('Включен')) {
      return <CBadge color="success">{status}</CBadge>
    } else if (status.includes('Остановлен') || status.includes('Выключен')) {
      return <CBadge color="danger">{status}</CBadge>
    }
    return <CBadge color="warning">{status}</CBadge>
  }

  if (loading) {
    return (
      <div className="d-flex justify-content-center align-items-center" style={{ height: '100vh' }}>
        <CSpinner color="primary" />
      </div>
    )
  }

  return (
    <>
      <CRow>
        <CCol md={8}>
          <CCard className="mb-4">
            <CCardHeader>
              <strong>Состояние оборудования</strong>
            </CCardHeader>
            <CCardBody>
              <CTable hover>
                <CTableHead>
                  <CTableRow>
                    <CTableHeaderCell>Оборудование</CTableHeaderCell>
                    <CTableHeaderCell>Состояние</CTableHeaderCell>
                    <CTableHeaderCell>Испаритель</CTableHeaderCell>
                    <CTableHeaderCell>АОС</CTableHeaderCell>
                  </CTableRow>
                </CTableHead>
                <CTableBody>
                  {dashboardRows.map((item, index) => (
                    <CTableRow key={index}>
                      <CTableDataCell>{item.name}</CTableDataCell>
                      <CTableDataCell>{getStatusBadge(item.status)}</CTableDataCell>
                      <CTableDataCell>{getStatusBadge(item.evaporator)}</CTableDataCell>
                      <CTableDataCell>{getStatusBadge(item.aos)}</CTableDataCell>
                    </CTableRow>
                  ))}
                </CTableBody>
              </CTable>
            </CCardBody>
          </CCard>
        </CCol>
      </CRow>
      <CRow>            
        <CCol md={4}>
          <CCard className="mb-4">
            <CCardHeader>
              <strong>Работающие вахты</strong>
            </CCardHeader>
            <CCardBody>
              <CTable hover>
                <CTableHead>
                  <CTableRow>
                    <CTableHeaderCell>Смена</CTableHeaderCell>
                    <CTableHeaderCell>Работающая вахта</CTableHeaderCell>
                  </CTableRow>
                </CTableHead>
                <CTableBody>
                  {activeShifts.map((shift, index) => (
                    <CTableRow key={index}>
                      <CTableDataCell>{shift.name}</CTableDataCell>
                      <CTableDataCell>
                        <CBadge color="info">
                          {shift.vahta}
                        </CBadge>
                      </CTableDataCell>
                    </CTableRow>
                  ))}
                </CTableBody>
              </CTable>
            </CCardBody>
          </CCard>
        </CCol>
        <CCol md={4}>
          <CCard className="mb-4">
            <CCardHeader>
              <strong>По станции (посл. неделя)</strong>
            </CCardHeader>
            <CCardBody>
              <div className="mb-3">
                <strong>Выработка энергии</strong>
                <div className="h4 m-2">
                  {powerStats.generation.toLocaleString()} МВт·ч
                </div>
              </div>
              <div>
                <h5>Расход энергии</h5>
                <div className="h4 m-2">
                  {powerStats.consumption.toLocaleString()} МВт·ч
                </div>
              </div>
            </CCardBody>
          </CCard>
        </CCol>
      </CRow>
    </>
  )
}

export default Dashboard
