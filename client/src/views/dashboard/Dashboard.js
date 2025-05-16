import React, { useState, useEffect } from 'react'
import {
  CButton,
  CButtonGroup,
  CCard,
  CCardBody,
  CCardFooter,
  CCol,
  CRow,
  CTable,
  CTableHead,
  CTableRow,
  CTableHeaderCell,
  CTableBody,
  CTableDataCell,
  CForm,
  CFormSelect,
  CFormInput,
  CSpinner,
  CAlert
} from '@coreui/react'
import { CChartLine } from '@coreui/react-chartjs'
import { getStyle, hexToRgba } from '@coreui/utils'
import CIcon from '@coreui/icons-react'
import { cilCloudDownload } from '@coreui/icons'
import dashboardService from '../../services/dashboardService'
import authService from '../../services/authService'

const Dashboard = () => {
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [dashboardData, setDashboardData] = useState({
    equipment: [],
    shifts: [],
    latestValues: [],
    stats: {
      equipmentCount: 0,
      parametersCount: 0,
      valuesCount: 0,
      lastUpdate: null
    }
  })
  
  // Состояние для формы ввода данных
  const [selectedEquipment, setSelectedEquipment] = useState('')
  const [selectedDate, setSelectedDate] = useState(new Date().toISOString().split('T')[0])
  const [selectedShift, setSelectedShift] = useState('')
  const [parameterValues, setParameterValues] = useState([])
  const [parameters, setParameters] = useState([])
  const [formSubmitting, setFormSubmitting] = useState(false)
  const [formSuccess, setFormSuccess] = useState(false)
  const [formError, setFormError] = useState(null)
  
  // Загрузка данных дашборда
  useEffect(() => {
    const fetchDashboardData = async () => {
      try {
        setLoading(true)
        setError(null)
        
        const data = await dashboardService.getDashboardData()
        setDashboardData(data)
        
        // Установка первого оборудования и смены по умолчанию
        if (data.equipment.length > 0 && !selectedEquipment) {
          setSelectedEquipment(data.equipment[0].id)
        }
        
        if (data.shifts.length > 0 && !selectedShift) {
          setSelectedShift(data.shifts[0].id)
        }
      } catch (err) {
        console.error('Error fetching dashboard data:', err)
        setError('Ошибка при загрузке данных дашборда')
      } finally {
        setLoading(false)
      }
    }
    
    fetchDashboardData()
  }, [])
  
  // Загрузка параметров при выборе оборудования
  useEffect(() => {
    const fetchParameters = async () => {
      if (!selectedEquipment) return
      
      try {
        const equipment = dashboardData.equipment.find(eq => eq.id === parseInt(selectedEquipment))
        if (!equipment) return
        
        // Получение параметров для типа оборудования
        const response = await fetch(`http://exepower/api/parameters?equipment_type_id=${equipment.type_id}`, {
          method: 'GET',
          headers: {
            'Authorization': `Bearer ${authService.getToken()}`
          }
        })
        
        const data = await response.json()
        
        if (data.success) {
          setParameters(data.data.parameters)
          
          // Инициализация значений параметров
          const initialValues = data.data.parameters.map(param => ({
            parameterId: param.id,
            name: param.name,
            unit: param.unit,
            value: ''
          }))
          
          setParameterValues(initialValues)
        }
      } catch (err) {
        console.error('Error fetching parameters:', err)
      }
    }
    
    fetchParameters()
  }, [selectedEquipment, dashboardData.equipment])
  
  // Обработчик изменения значения параметра
  const handleParameterValueChange = (parameterId, value) => {
    setParameterValues(prevValues => 
      prevValues.map(item => 
        item.parameterId === parameterId 
          ? { ...item, value } 
          : item
      )
    )
  }
  
  // Отправка формы
  const handleSubmit = async (e) => {
    e.preventDefault()
    
    if (!selectedEquipment || !selectedDate || !selectedShift) {
      setFormError('Пожалуйста, заполните все обязательные поля')
      return
    }
    
    try {
      setFormSubmitting(true)
      setFormError(null)
      setFormSuccess(false)
      
      // Подготовка данных для отправки
      const valuesToSubmit = parameterValues
        .filter(param => param.value !== '')
        .map(param => ({
          parameterId: param.parameterId,
          value: param.value
        }))
      
      if (valuesToSubmit.length === 0) {
        setFormError('Пожалуйста, введите хотя бы одно значение параметра')
        return
      }
      
      const requestData = {
        equipmentId: parseInt(selectedEquipment),
        date: selectedDate,
        shiftId: parseInt(selectedShift),
        values: valuesToSubmit
      }
      
      // Отправка данных на сервер
      const response = await fetch('http://exepower/api/parameter-values', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authService.getToken()}`
        },
        body: JSON.stringify(requestData)
      })
      
      const data = await response.json()
      
      if (data.success) {
        setFormSuccess(true)
        
        // Обновление данных дашборда
        const updatedData = await dashboardService.getDashboardData()
        setDashboardData(updatedData)
        
        // Очистка значений формы
        setParameterValues(prevValues => 
          prevValues.map(item => ({ ...item, value: '' }))
        )
      } else {
        setFormError(data.message || 'Произошла ошибка при сохранении данных')
      }
    } catch (err) {
      console.error('Error submitting form:', err)
      setFormError('Ошибка при отправке данных на сервер')
    } finally {
      setFormSubmitting(false)
    }
  }
  
  // Форматирование даты
  const formatDate = (dateString) => {
    if (!dateString) return '-'
    const date = new Date(dateString)
    return date.toLocaleDateString('ru-RU')
  }
  
  if (loading) {
    return (
      <div className="d-flex justify-content-center align-items-center" style={{ height: '400px' }}>
        <CSpinner color="primary" />
      </div>
    )
  }
  
  if (error) {
    return (
      <CAlert color="danger">
        {error}
      </CAlert>
    )
  }
  
  const user = authService.getUser()
  const canEditValues = user && (user.role === 'рядовой' || user.role === 'инженер' || user.role === 'менеджер')

  return (
    <>
      <CCard className="mb-4">
        <CCardBody>
          <CRow>
            <CCol sm={5}>
              <h4 id="traffic" className="card-title mb-0">
                Статистика
              </h4>
              <div className="small text-medium-emphasis">
                {dashboardData.stats.lastUpdate 
                  ? `Последнее обновление: ${formatDate(dashboardData.stats.lastUpdate)}` 
                  : 'Нет данных'}
              </div>
            </CCol>
            <CCol sm={7} className="d-none d-md-block">
              <CButton color="primary" className="float-end">
                <CIcon icon={cilCloudDownload} />
              </CButton>
            </CCol>
          </CRow>
          
          <CRow className="mt-3">
            <CCol xs={12} md={6} xl={3}>
              <div className="border-start border-start-4 border-start-info py-1 px-3 mb-3">
                <div className="text-medium-emphasis small">Оборудование</div>
                <div className="fs-5 fw-semibold">{dashboardData.stats.equipmentCount}</div>
                      </div>
                    </CCol>
            
            <CCol xs={12} md={6} xl={3}>
                      <div className="border-start border-start-4 border-start-danger py-1 px-3 mb-3">
                <div className="text-medium-emphasis small">Параметры</div>
                <div className="fs-5 fw-semibold">{dashboardData.stats.parametersCount}</div>
                      </div>
                    </CCol>
            
            <CCol xs={12} md={6} xl={3}>
                      <div className="border-start border-start-4 border-start-warning py-1 px-3 mb-3">
                <div className="text-medium-emphasis small">Записей</div>
                <div className="fs-5 fw-semibold">{dashboardData.stats.valuesCount}</div>
                      </div>
                    </CCol>
            
            <CCol xs={12} md={6} xl={3}>
                      <div className="border-start border-start-4 border-start-success py-1 px-3 mb-3">
                <div className="text-medium-emphasis small">Смены</div>
                <div className="fs-5 fw-semibold">{dashboardData.shifts.length}</div>
                      </div>
                    </CCol>
                  </CRow>
        </CCardBody>
      </CCard>

      {canEditValues && (
        <CCard className="mb-4">
          <CCardBody>
            <h4 className="card-title mb-3">Ввод параметров</h4>
            
            {formSuccess && (
              <CAlert color="success" dismissible>
                Данные успешно сохранены!
              </CAlert>
            )}
            
            {formError && (
              <CAlert color="danger" dismissible>
                {formError}
              </CAlert>
            )}
            
            <CForm onSubmit={handleSubmit}>
              <CRow className="mb-3">
                <CCol md={4}>
                  <CFormSelect 
                    label="Оборудование" 
                    value={selectedEquipment} 
                    onChange={(e) => setSelectedEquipment(e.target.value)}
                    required
                  >
                    <option value="">Выберите оборудование</option>
                    {dashboardData.equipment.map(item => (
                      <option key={item.id} value={item.id}>
                        {item.name}
                      </option>
                    ))}
                  </CFormSelect>
                </CCol>
                
                <CCol md={4}>
                  <CFormInput
                    type="date"
                    label="Дата"
                    value={selectedDate}
                    onChange={(e) => setSelectedDate(e.target.value)}
                    required
                  />
                </CCol>
                
                <CCol md={4}>
                  <CFormSelect 
                    label="Смена" 
                    value={selectedShift} 
                    onChange={(e) => setSelectedShift(e.target.value)}
                    required
                  >
                    <option value="">Выберите смену</option>
                    {dashboardData.shifts.map(shift => (
                      <option key={shift.id} value={shift.id}>
                        {shift.name} ({shift.start_time} - {shift.end_time})
                      </option>
                  ))}
                  </CFormSelect>
                </CCol>
              </CRow>

              <CCard className="mb-3">
                <CCardBody>
                  <h5>Значения параметров</h5>
                  
                  {parameters.length === 0 ? (
                    <div className="text-medium-emphasis">
                      Выберите оборудование для отображения параметров
                    </div>
                  ) : (
                    <CTable small responsive>
                      <CTableHead>
                  <CTableRow>
                          <CTableHeaderCell>Параметр</CTableHeaderCell>
                          <CTableHeaderCell>Единица измерения</CTableHeaderCell>
                          <CTableHeaderCell>Значение</CTableHeaderCell>
                  </CTableRow>
                </CTableHead>
                <CTableBody>
                        {parameterValues.map(param => (
                          <CTableRow key={param.parameterId}>
                            <CTableDataCell>{param.name}</CTableDataCell>
                            <CTableDataCell>{param.unit}</CTableDataCell>
                      <CTableDataCell>
                              <CFormInput
                                type="number"
                                step="0.01"
                                value={param.value}
                                onChange={(e) => handleParameterValueChange(param.parameterId, e.target.value)}
                                placeholder="Введите значение"
                              />
                      </CTableDataCell>
                    </CTableRow>
                  ))}
                </CTableBody>
              </CTable>
                  )}
                </CCardBody>
              </CCard>
              
              <div className="d-flex justify-content-end">
                <CButton 
                  type="submit" 
                  color="primary" 
                  disabled={formSubmitting || parameters.length === 0}
                >
                  {formSubmitting ? (
                    <>
                      <CSpinner size="sm" className="me-2" />
                      Сохранение...
                    </>
                  ) : 'Сохранить'}
                </CButton>
              </div>
            </CForm>
          </CCardBody>
        </CCard>
      )}
      
      <CCard className="mb-4">
        <CCardBody>
          <h4 className="card-title mb-3">Последние записи</h4>
          
          {dashboardData.latestValues.length === 0 ? (
            <div className="text-medium-emphasis">
              Нет данных для отображения
            </div>
          ) : (
            <CTable small responsive hover>
              <CTableHead>
                <CTableRow>
                  <CTableHeaderCell>Дата</CTableHeaderCell>
                  <CTableHeaderCell>Смена</CTableHeaderCell>
                  <CTableHeaderCell>Оборудование</CTableHeaderCell>
                  <CTableHeaderCell>Параметр</CTableHeaderCell>
                  <CTableHeaderCell>Значение</CTableHeaderCell>
                  <CTableHeaderCell>Оператор</CTableHeaderCell>
                </CTableRow>
              </CTableHead>
              <CTableBody>
                {dashboardData.latestValues.map((item, index) => (
                  <CTableRow key={index}>
                    <CTableDataCell>{formatDate(item.date)}</CTableDataCell>
                    <CTableDataCell>{item.shift_name}</CTableDataCell>
                    <CTableDataCell>{item.equipment_name}</CTableDataCell>
                    <CTableDataCell>{item.parameter_name}</CTableDataCell>
                    <CTableDataCell>
                      {item.value} {item.unit}
                    </CTableDataCell>
                    <CTableDataCell>{item.user_name}</CTableDataCell>
                  </CTableRow>
                ))}
              </CTableBody>
            </CTable>
          )}
            </CCardBody>
          </CCard>
    </>
  )
}

export default Dashboard
