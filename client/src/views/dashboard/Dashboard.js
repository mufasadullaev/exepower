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
  const [equipmentStatus, setEquipmentStatus] = useState([])
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
      
      setEquipmentStatus(data.equipmentStatus)
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
    if (status === 'Запущен') {
      return <CBadge color="success">{status}</CBadge>
    } else if (status.includes('Остановлен')) {
      return <CBadge color="danger">{status}</CBadge>
    }
    return <CBadge color="secondary">{status}</CBadge>
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
        <CCol md={6}>
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
                  </CTableRow>
                </CTableHead>
                <CTableBody>
                  {equipmentStatus.map((item, index) => (
                    <CTableRow key={index}>
                      <CTableDataCell>{item.name}</CTableDataCell>
                      <CTableDataCell>{getStatusBadge(item.status)}</CTableDataCell>
                    </CTableRow>
                  ))}
                </CTableBody>
              </CTable>
            </CCardBody>
          </CCard>
        </CCol>

        <CCol md={6}>
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
      </CRow>

      <CRow>
        <CCol md={6}>
          <CCard className="mb-4">
            <CCardHeader>
              <strong>Статистика по станции (за последнюю неделю)</strong>
            </CCardHeader>
            <CCardBody>
              <div className="mb-3">
                <h4>Выработка энергии</h4>
                <div className="h2 mb-3">
                  {powerStats.generation.toLocaleString()} МВт·ч
                </div>
              </div>
              <div>
                <h4>Расход энергии</h4>
                <div className="h2">
                  {powerStats.consumption.toLocaleString()} МВт·ч
                </div>
              </div>
            </CCardBody>
          </CCard>
        </CCol>

        <CCol md={6}>
          <CCard className="mb-4">
            <CCardHeader>
              <strong>Время работы за последнюю неделю</strong>
            </CCardHeader>
            <CCardBody>
              <CTable hover>
                <CTableHead>
                  <CTableRow>
                    <CTableHeaderCell>Оборудование</CTableHeaderCell>
                    <CTableHeaderCell>Тип</CTableHeaderCell>
                    <CTableHeaderCell>Дней работы</CTableHeaderCell>
                    <CTableHeaderCell>Часов работы</CTableHeaderCell>
                  </CTableRow>
                </CTableHead>
                <CTableBody>
                  {workingHours.map((item, index) => (
                    <CTableRow key={index}>
                      <CTableDataCell>{item.name}</CTableDataCell>
                      <CTableDataCell>{item.type_name}</CTableDataCell>
                      <CTableDataCell>{item.working_days}</CTableDataCell>
                      <CTableDataCell>{Math.round(item.working_hours)}</CTableDataCell>
                    </CTableRow>
                  ))}
                </CTableBody>
              </CTable>
            </CCardBody>
          </CCard>
        </CCol>
      </CRow>
    </>
  )
}

export default Dashboard
