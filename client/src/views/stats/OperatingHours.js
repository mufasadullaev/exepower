import React, { useState, useEffect } from 'react'
import {
  CCard,
  CCardBody,
  CCardHeader,
  CTable,
  CTableHead,
  CTableRow,
  CTableHeaderCell,
  CTableBody,
  CTableDataCell,
  CSpinner,
  CAlert,
  CRow,
  CCol,
  CInputGroup,
  CInputGroupText,
  CButton,
  CFormSelect
} from '@coreui/react'
import CIcon from '@coreui/icons-react'
import { cilCalendar, cilChevronLeft, cilChevronRight } from '@coreui/icons'
import DatePicker from 'react-datepicker'
import 'react-datepicker/dist/react-datepicker.css'
import authService from '../../services/authService'
import { API_BASE_URL } from '../../config/api'

const OperatingHours = () => {
  // Состояние компонента
  const [startDate, setStartDate] = useState(new Date(new Date().getFullYear(), new Date().getMonth(), 1)) // Первый день текущего месяца
  const [endDate, setEndDate] = useState(new Date()) // Текущая дата
  const [equipmentList, setEquipmentList] = useState([])
  const [equipmentType, setEquipmentType] = useState('all') // all, block, pgu
  const [statistics, setStatistics] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  // Загрузка списка оборудования
  useEffect(() => {
    const fetchEquipment = async () => {
      setLoading(true)
      try {
        // Формируем URL в зависимости от выбранного типа оборудования
        let url = `${API_BASE_URL}/equipment`;
        if (equipmentType !== 'all') {
          url += `?type=${equipmentType}`;
        }
        
        const response = await fetch(url, {
          headers: { 'Authorization': `Bearer ${authService.getToken()}` }
        })
        const data = await response.json()
        
        if (!data.success && data.status !== 'success') {
          throw new Error(data.message || 'Ошибка загрузки оборудования')
        }
        
        let equipmentData = data.data?.equipment || [];
        
        // Фильтруем оборудование - убираем ОЧ 130 и ПТ
        equipmentData = equipmentData.filter(eq => 
          !eq.name.includes('ОЧ-130') && 
          !eq.name.includes('ПТ')
        );
        
        setEquipmentList(equipmentData)
      } catch (err) {
        console.error('Error fetching equipment:', err)
        setError(`Ошибка загрузки оборудования: ${err.message}`)
      } finally {
        setLoading(false)
      }
    }
    
    fetchEquipment()
  }, [equipmentType])

  // Загрузка статистики наработки
  useEffect(() => {
    const fetchStatistics = async () => {
      if (equipmentList.length === 0) return;
      
      setLoading(true)
      try {
        // Форматируем даты для API
        const formattedStartDate = startDate.toISOString().split('T')[0]
        const formattedEndDate = endDate.toISOString().split('T')[0]
        
        // Получаем статистику наработки для каждого оборудования
        const statsPromises = equipmentList.map(async (equipment) => {
          const response = await fetch(
            `${API_BASE_URL}/equipment-stats?equipment_id=${equipment.id}&start_date=${formattedStartDate}&end_date=${formattedEndDate}`,
            {
              headers: { 'Authorization': `Bearer ${authService.getToken()}` }
            }
          )
          const data = await response.json()
          
          if (!data.success && data.status !== 'success') {
            throw new Error(`Ошибка загрузки статистики для ${equipment.name}: ${data.message}`)
          }
          
          // Рассчитываем часы работы на основе событий
          const stats = data.data || {}
          const operatingHours = calculateOperatingHours(stats.events || [])
          
          return {
            id: equipment.id,
            name: equipment.name,
            operatingHours: operatingHours,
            startCount: stats.start_count || 0,
            stopCount: stats.stop_count || 0
          }
        })
        
        const results = await Promise.all(statsPromises)
        setStatistics(results)
      } catch (err) {
        console.error('Error fetching statistics:', err)
        setError(`Ошибка загрузки статистики: ${err.message}`)
      } finally {
        setLoading(false)
      }
    }
    
    fetchStatistics()
  }, [equipmentList, startDate, endDate])

  // Функция для расчета часов работы на основе событий пуска и останова
  const calculateOperatingHours = (events) => {
    if (!events || events.length === 0) return 0;
    
    // Сортируем события по времени
    const sortedEvents = [...events].sort((a, b) => 
      new Date(a.event_time) - new Date(b.event_time)
    );
    
    let totalHours = 0;
    let lastStartTime = null;
    const now = new Date();
    
    // Проходим по всем событиям
    for (let i = 0; i < sortedEvents.length; i++) {
      const event = sortedEvents[i];
      const eventTime = new Date(event.event_time);
      
      if (event.event_type === 'pusk') {
        // Если нашли пуск, запоминаем время
        lastStartTime = eventTime;
      } else if (event.event_type === 'ostanov' && lastStartTime) {
        // Если нашли останов и есть время пуска, рассчитываем время работы
        const hoursWorked = (eventTime - lastStartTime) / (1000 * 60 * 60); // миллисекунды в часы
        totalHours += hoursWorked;
        lastStartTime = null;
      }
    }
    
    // Если последнее событие - пуск, считаем до текущего момента
    if (lastStartTime) {
      const hoursWorked = (now - lastStartTime) / (1000 * 60 * 60);
      totalHours += hoursWorked;
    }
    
    return Math.round(totalHours * 10) / 10; // Округляем до 1 десятичного знака
  }

  // Форматирование даты
  const formatDate = (date) => {
    return date.toLocaleDateString('ru-RU');
  }

  // Навигация по месяцам
  const navigateMonth = (direction) => {
    const newStartDate = new Date(startDate);
    const newEndDate = new Date(endDate);
    
    newStartDate.setMonth(newStartDate.getMonth() + direction);
    
    // Если перемещаемся вперед и конечная дата не текущий месяц, 
    // то двигаем и конечную дату
    if (direction > 0 && endDate < new Date()) {
      newEndDate.setMonth(newEndDate.getMonth() + direction);
    } else if (direction < 0) {
      // Если перемещаемся назад, всегда двигаем конечную дату
      newEndDate.setMonth(newEndDate.getMonth() + direction);
    }
    
    setStartDate(newStartDate);
    setEndDate(newEndDate);
  }

  // Выбор текущего месяца
  const selectCurrentMonth = () => {
    const now = new Date();
    setStartDate(new Date(now.getFullYear(), now.getMonth(), 1));
    setEndDate(new Date());
  }

  if (loading && !statistics.length) {
    return (
      <div className="d-flex justify-content-center my-5">
        <CSpinner color="primary" />
      </div>
    )
  }

  return (
    <CCard>
      <CCardHeader>
        <h4>Статистика наработки оборудования</h4>
      </CCardHeader>
      <CCardBody>
        {error && <CAlert color="danger">{error}</CAlert>}
        
        <CRow className="mb-3">
          <CCol md={4}>
            <CFormSelect
              value={equipmentType}
              onChange={(e) => setEquipmentType(e.target.value)}
              className="mb-3"
            >
              <option value="all">Все типы оборудования</option>
              <option value="block">Только блоки (ТГ)</option>
              <option value="pgu">Только ПГУ</option>
            </CFormSelect>
          </CCol>
          <CCol md={8}>
            <div className="mb-2">
              <strong>Период: </strong>
              {formatDate(startDate)} - {formatDate(endDate)}
            </div>
            <CInputGroup>
              <CButton color="secondary" onClick={() => navigateMonth(-1)}>
                <CIcon icon={cilChevronLeft} />
              </CButton>
              <CInputGroupText>
                <CIcon icon={cilCalendar} />
              </CInputGroupText>
              <DatePicker
                selected={startDate}
                onChange={(date) => setStartDate(date)}
                dateFormat="dd.MM.yyyy"
                className="form-control"
                customInput={
                  <input
                    type="text"
                    className="form-control"
                    style={{ minWidth: '120px' }}
                  />
                }
              />
              <CInputGroupText>-</CInputGroupText>
              <DatePicker
                selected={endDate}
                onChange={(date) => setEndDate(date)}
                dateFormat="dd.MM.yyyy"
                className="form-control"
                customInput={
                  <input
                    type="text"
                    className="form-control"
                    style={{ minWidth: '120px' }}
                  />
                }
              />
              <CButton color="secondary" onClick={() => navigateMonth(1)}>
                <CIcon icon={cilChevronRight} />
              </CButton>
              <CButton color="info" onClick={selectCurrentMonth}>
                Текущий месяц
              </CButton>
            </CInputGroup>
          </CCol>
        </CRow>
        
        <CTable bordered hover>
          <CTableHead>
            <CTableRow>
              <CTableHeaderCell>Оборудование</CTableHeaderCell>
              <CTableHeaderCell>Часы работы</CTableHeaderCell>
              <CTableHeaderCell>Количество пусков</CTableHeaderCell>
              <CTableHeaderCell>Количество остановов</CTableHeaderCell>
            </CTableRow>
          </CTableHead>
          <CTableBody>
            {statistics.length > 0 ? (
              statistics.map(stat => {
                const displayName =
                stat.name === 'ГТ 1' ? 'ПГУ 1' :
                stat.name === 'ГТ 2' ? 'ПГУ 2' :
                stat.name;
                return (
                <CTableRow key={stat.id}>
                  <CTableDataCell>{displayName}</CTableDataCell>
                  <CTableDataCell>{stat.operatingHours} ч</CTableDataCell>
                  <CTableDataCell>{stat.startCount}</CTableDataCell>
                  <CTableDataCell>{stat.stopCount}</CTableDataCell>
                </CTableRow>
              )
            })
            ) : (
              <CTableRow>
                <CTableDataCell colSpan="4" className="text-center">
                  Нет данных для отображения
                </CTableDataCell>
              </CTableRow>
            )}
          </CTableBody>
        </CTable>
      </CCardBody>
    </CCard>
  )
}

export default OperatingHours 