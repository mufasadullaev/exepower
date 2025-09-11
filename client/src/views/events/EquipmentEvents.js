import React, { useState, useEffect } from 'react'
import { API_BASE_URL } from '../../config/api'
import {
  CCard,
  CCardBody,
  CCardHeader,
  CNav,
  CNavItem,
  CNavLink,
  CTabContent,
  CTabPane,
  CTable,
  CTableHead,
  CTableRow,
  CTableHeaderCell,
  CTableBody,
  CTableDataCell,
  CButton,
  CSpinner,
  CAlert,
  CModal,
  CModalHeader,
  CModalTitle,
  CModalBody,
  CModalFooter,
  CForm,
  CFormInput,
  CFormSelect,
  CFormLabel,
  CRow,
  CCol,
  CButtonGroup,
  CInputGroup,
  CInputGroupText,
  CFormSwitch
} from '@coreui/react'
import CIcon from '@coreui/icons-react'
import { cilCalendar, cilChevronLeft, cilChevronRight } from '@coreui/icons'
import DatePicker from 'react-datepicker'
import 'react-datepicker/dist/react-datepicker.css'
import authService from '../../services/authService'
import { equipmentToolService } from '../../services/equipmentToolService'
import { startReasonsService } from '../../services/startReasonsService'

// Типы оборудования
const EQUIPMENT_TYPES = {
  BLOCK: 1, // ТГ (Турбогенераторы)
  PGU: 2    // ПГУ (Парогазовые установки)
}

const EquipmentEvents = () => {
  // Состояние компонента
  const [activeEquipmentType, setActiveEquipmentType] = useState(EQUIPMENT_TYPES.BLOCK)
  const [equipmentList, setEquipmentList] = useState([])
  const [selectedEquipment, setSelectedEquipment] = useState(null)
  const [selectedDate, setSelectedDate] = useState(new Date())
  const [events, setEvents] = useState([])
  const [lastEvent, setLastEvent] = useState(null)  // Добавляем состояние для последнего события
  const [stopReasons, setStopReasons] = useState([])
  const [startReasons, setStartReasons] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  
  // Модальные окна
  const [eventModal, setEventModal] = useState(false)
  const [eventType, setEventType] = useState('pusk')
  const [eventData, setEventData] = useState({
    event_time: new Date(),
    reason_id: null,
    comment: ''
  })
  const [isEditing, setIsEditing] = useState(false)
  const [editingEventId, setEditingEventId] = useState(null)
  const [editingEventInfo, setEditingEventInfo] = useState(null)
  
  // Модальное окно для подтверждения удаления
  const [deleteModal, setDeleteModal] = useState(false)
  const [deletingEventId, setDeletingEventId] = useState(null)
  const [deletingEventInfo, setDeletingEventInfo] = useState(null)

  // Состояние инструментов
  const [toolStatus, setToolStatus] = useState({
    evaporator: 'off',
    aos: 'off'
  })
  const [toolsLoading, setToolsLoading] = useState(false)

  // Состояние для модального окна подтверждения переключения инструмента
  const [toolConfirmModal, setToolConfirmModal] = useState(false)
  const [pendingToolToggle, setPendingToolToggle] = useState(null)

  // Загрузка списка оборудования при изменении типа
  useEffect(() => {
    const fetchEquipment = async () => {
      setLoading(true)
      try {
        const equipmentType = activeEquipmentType === EQUIPMENT_TYPES.BLOCK ? 'block' : 'pgu'
        const response = await fetch(`${API_BASE_URL}/equipment?type=${equipmentType}`, {
          headers: { 'Authorization': `Bearer ${authService.getToken()}` }
        })
        const data = await response.json()
        
        if (!data.success && data.status !== 'success') {
          throw new Error(data.message || 'Ошибка загрузки оборудования')
        }
        
        let equipmentData = data.data?.equipment || [];
        
        // Фильтруем оборудование - убираем ОЧ 130
        equipmentData = equipmentData.filter(eq => !eq.name.includes('ОЧ-130') && !eq.name.includes('ПТ'));
        
        // Если это ПГУ, группируем ГТ и ПТ в ПГУ
        
        
        setEquipmentList(equipmentData);
        
        // Если есть оборудование, выбираем первое по умолчанию
        if (equipmentData.length > 0) {
          setSelectedEquipment(equipmentData[0])
        } else {
          setSelectedEquipment(null)
        }
      } catch (err) {
        console.error('Error fetching equipment:', err)
        setError(`Ошибка загрузки оборудования: ${err.message}`)
      } finally {
        setLoading(false)
      }
    }
    
    fetchEquipment()
  }, [activeEquipmentType])
  
  // Загрузка причин останова и пуска
  useEffect(() => {
    const fetchReasons = async () => {
      try {
        const [stopResponse, startResponse] = await Promise.all([
          fetch(`${API_BASE_URL}/stop-reasons`, {
            headers: { 'Authorization': `Bearer ${authService.getToken()}` }
          }),
          startReasonsService.getStartReasons()
        ])
        
        const stopData = await stopResponse.json()
        if (stopData.success || stopData.status === 'success') {
          setStopReasons(stopData.data?.reasons || [])
        }
        
        if (startResponse.status === 'success') {
          setStartReasons(startResponse.data?.reasons || [])
        }
      } catch (err) {
        console.error('Error fetching reasons:', err)
      }
    }
    
    fetchReasons()
  }, [])
  
  // Функция для загрузки и группировки событий
  const fetchAndGroupEvents = async (equipment, date) => {
    // Форматируем дату в формат YYYY-MM для API (только месяц и год)
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const formattedDate = `${year}-${month}`;
    
    let allEvents = [];
    
    // Если это группа ПГУ, получаем события для всех компонентов
    if (equipment.components && equipment.components.length > 0) {
      // Получаем события для каждого компонента ПГУ
      const eventsPromises = equipment.components.map(async (component) => {
        const response = await fetch(`${API_BASE_URL}/equipment-events?equipment_id=${component.id}&month=${formattedDate}&limit=50`, {
          headers: { 'Authorization': `Bearer ${authService.getToken()}` }
        });
        const data = await response.json();
        
        if (!data.success && data.status !== 'success') {
          throw new Error(`Ошибка загрузки событий для ${component.name}: ${data.message}`);
        }
        
        // Добавляем информацию о компоненте к каждому событию
        const events = data.data?.events || [];
        return events.map(event => ({
          ...event,
          equipment_name: component.name,
          original_id: event.id // Сохраняем оригинальный ID
        }));
      });
      
      // Ждем выполнения всех запросов
      const eventsArrays = await Promise.all(eventsPromises);
      
      // Объединяем все события в один массив
      const combinedEvents = eventsArrays.flat();
      
      // Группируем события по времени и типу
      const eventGroups = {};
      combinedEvents.forEach(event => {
        const key = `${event.event_type}_${new Date(event.event_time).getTime()}`;
        if (!eventGroups[key]) {
          eventGroups[key] = {
            ...event,
            equipment_components: [event.equipment_name],
            original_id: event.id // Сохраняем оригинальный ID
          };
        } else {
          eventGroups[key].equipment_components.push(event.equipment_name);
        }
      });
      
      // Преобразуем группы обратно в массив и сортируем по времени
      allEvents = Object.values(eventGroups).sort((a, b) => 
        new Date(b.event_time) - new Date(a.event_time)
      );
    } else {
      // Для обычного оборудования получаем события как раньше
      const response = await fetch(`${API_BASE_URL}/equipment-events?equipment_id=${equipment.id}&month=${formattedDate}&limit=50`, {
        headers: { 'Authorization': `Bearer ${authService.getToken()}` }
      });
      const data = await response.json();
      
      if (!data.success && data.status !== 'success') {
        throw new Error(data.message || 'Ошибка загрузки событий');
      }
      
      allEvents = data.data?.events || [];
      // Сохраняем последнее событие
      setLastEvent(data.data?.last_event || null);
    }
    
    return allEvents;
  };

  // Загрузка событий для выбранного оборудования и даты
  const fetchEvents = async () => {
    if (!selectedEquipment) return;
    
    setLoading(true);
    try {
      const events = await fetchAndGroupEvents(selectedEquipment, selectedDate);
      setEvents(events);
    } catch (err) {
      console.error('Error fetching events:', err);
      setError(`Ошибка загрузки событий: ${err.message}`);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchEvents();
  }, [selectedEquipment, selectedDate]);
  
  // Определение текущего состояния оборудования (работает или остановлено)
  const determineEquipmentStatus = () => {
    if (!lastEvent) return 'unknown'
    return lastEvent.event_type === 'pusk' ? 'running' : 'stopped'
  }
  
  // Обработчик нажатия на кнопку "Пуск" или "Останов"
  const handleEventButtonClick = (type) => {
    setEventType(type)
    setEventData({
      event_time: new Date(),
      reason_id: type === 'ostanov' ? stopReasons[0]?.id : (type === 'pusk' ? startReasons[0]?.id : null),
      comment: ''
    })
    setIsEditing(false)
    setEditingEventId(null)
    setError(null)
    setEventModal(true)
  }
  
  // Обработчик редактирования события
  const handleEditEvent = (event) => {
    setEventType(event.event_type);
    setEventData({
      event_time: new Date(event.event_time),
      reason_id: event.reason_id || null,
      comment: event.comment || ''
    });
    setIsEditing(true);
    setEditingEventId(event.original_id || event.id);
    setError(null);
    
    // Сохраняем информацию о событии для возможного редактирования всех компонентов ПГУ
    if (selectedEquipment.components && selectedEquipment.components.length > 0) {
      // Для групп ПГУ сохраняем полную информацию о событии
      setEditingEventInfo(event);
    }
    
    setEventModal(true);
  }
  
  // Обработчик сохранения события 
  const handleSaveEvent = async () => {
    try {
      // Создаем копию даты события
      const eventTime = new Date(eventData.event_time);
      
      // Преобразуем в строку ISO с сохранением локальной временной зоны
      const eventTimeStr = eventTime.getFullYear() + '-' +
                          String(eventTime.getMonth() + 1).padStart(2, '0') + '-' +
                          String(eventTime.getDate()).padStart(2, '0') + ' ' +
                          String(eventTime.getHours()).padStart(2, '0') + ':' +
                          String(eventTime.getMinutes()).padStart(2, '0') + ':00';
      
      const payload = {
        equipment_id: selectedEquipment.id,
        event_type: eventType,
        event_time: eventTimeStr,
        reason_id: eventData.reason_id,
        comment: eventData.comment
      };

      // Если это группа ПГУ, создаем или обновляем события для всех компонентов
      if (selectedEquipment.components && selectedEquipment.components.length > 0) {
        if (isEditing) {
          // Код для редактирования событий групп ПГУ
          const updatePromises = selectedEquipment.components.map(async (component) => {
            // Получаем все события компонента за текущий месяц
            const year = new Date(editingEventInfo.event_time).getFullYear();
            const month = String(new Date(editingEventInfo.event_time).getMonth() + 1).padStart(2, '0');
            const formattedDate = `${year}-${month}`;
            
            const response = await fetch(`${API_BASE_URL}/equipment-events?equipment_id=${component.id}&month=${formattedDate}&limit=50`, {
              headers: { 'Authorization': `Bearer ${authService.getToken()}` }
            });
            
            const data = await response.json();
            
            if (!data.success && data.status !== 'success') {
              throw new Error(`Ошибка загрузки событий для ${component.name}`);
            }
            
            const events = data.data?.events || [];
            
            // Ищем событие с тем же временем и типом
            const eventToUpdate = events.find(event => 
              new Date(event.event_time).getTime() === new Date(editingEventInfo.event_time).getTime() && 
              event.event_type === editingEventInfo.event_type
            );
            
            if (eventToUpdate) {
              // Клонируем payload и обновляем equipment_id для компонента
              const componentPayload = {...payload, equipment_id: component.id};
              
              const updateResponse = await fetch(`${API_BASE_URL}/equipment-events/${eventToUpdate.id}`, {
                method: 'PUT',
                headers: { 
                  'Content-Type': 'application/json',
                  'Authorization': `Bearer ${authService.getToken()}`
                },
                body: JSON.stringify(componentPayload)
              });
              
              const responseData = await updateResponse.json();
              if (!responseData.success && responseData.status !== 'success') {
                throw new Error(responseData.message || `Ошибка обновления события для ${component.name}`);
              }
              
              return responseData;
            }
            
            return null;
          });
          
          // Ждем выполнения всех запросов на обновление
          await Promise.all(updatePromises.filter(p => p !== null));
        } else {
          // Код для создания новых событий для групп ПГУ
          const savePromises = selectedEquipment.components.map(async (component) => {
            // Клонируем payload и обновляем equipment_id для компонента
            const componentPayload = {...payload, equipment_id: component.id};
            
            const saveResponse = await fetch(`${API_BASE_URL}/equipment-events`, {
              method: 'POST',
              headers: { 
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${authService.getToken()}`
              },
              body: JSON.stringify(componentPayload)
            });
            
            const responseData = await saveResponse.json();
            if (!responseData.success && responseData.status !== 'success') {
              throw new Error(responseData.message || `Ошибка создания события для ${component.name}`);
            }
            
            return responseData;
          });
          
          // Ждем выполнения всех запросов
          await Promise.all(savePromises);
        }
      } else {
        // Код для обычного оборудования
        const url = isEditing 
                  ? `${API_BASE_URL}/equipment-events/${editingEventId}`
        : `${API_BASE_URL}/equipment-events`;
        
        const method = isEditing ? 'PUT' : 'POST';
        
        const response = await fetch(url, {
          method: method,
          headers: { 
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${authService.getToken()}`
          },
          body: JSON.stringify(payload)
        });
        
        const responseData = await response.json();
        if (!responseData.success && responseData.status !== 'success') {
          throw new Error(responseData.message || 'Ошибка сохранения события');
        }
      }
      
      // Закрываем модальное окно и обновляем список событий
      setEventModal(false);
      await fetchEvents();
      
      // Если это был останов, обновляем статус инструментов
      if (eventType === 'ostanov') {
        await fetchToolStatus();
      }
      
    } catch (err) {
      console.error('Error saving event:', err);
      setError(err.message || 'Ошибка сохранения события');
    }
  }
  
  // Форматирование даты и времени
  const formatDateTime = (dateTimeStr) => {
    const date = new Date(dateTimeStr)
    return date.toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    })
  }
  
  // Навигация по датам
  const navigateDate = (direction) => {
    const newDate = new Date(selectedDate)
    newDate.setDate(newDate.getDate() + direction)
    setSelectedDate(newDate)
  }
  
  // Получение названия причины останова/типа пуска по ID
  const getReasonNameById = (event) => {
    if (event.event_type === 'ostanov') {
      return stopReasons.find(reason => reason.id === event.reason_id)?.name || 'Не указана';
    } else if (event.event_type === 'pusk') {
      return startReasons.find(reason => reason.id === event.reason_id)?.name || 'Не указан';
    }
    return 'Неизвестно';
  }
  
  // Отображение текущего состояния оборудования
  const equipmentStatus = determineEquipmentStatus()
  
  // Обработчик удаления события
  const handleDeleteEvent = (event) => {
    setDeletingEventId(event.original_id || event.id)
    setDeletingEventInfo(event)
    setDeleteModal(true)
  }
  
  // Подтверждение удаления события
  const confirmDeleteEvent = async () => {
    try {
      // Если это группа ПГУ, удаляем событие из всех компонентов
      if (selectedEquipment.components && selectedEquipment.components.length > 0) {
        // Для каждого компонента ПГУ находим соответствующее событие и удаляем его
        const deletePromises = selectedEquipment.components.map(async (component) => {
          // Получаем все события компонента за текущий месяц
          const year = selectedDate.getFullYear();
          const month = String(selectedDate.getMonth() + 1).padStart(2, '0');
          const formattedDate = `${year}-${month}`;
          
          const response = await fetch(`${API_BASE_URL}/equipment-events?equipment_id=${component.id}&month=${formattedDate}&limit=50`, {
            headers: { 'Authorization': `Bearer ${authService.getToken()}` }
          });
          
          const data = await response.json();
          
          if (!data.success && data.status !== 'success') {
            throw new Error(`Ошибка загрузки событий для ${component.name}`);
          }
          
          const events = data.data?.events || [];
          
          // Ищем событие с тем же временем и типом
          const eventToDelete = events.find(event => 
            new Date(event.event_time).getTime() === new Date(deletingEventInfo.event_time).getTime() && 
            event.event_type === deletingEventInfo.event_type
          );
          
          if (eventToDelete) {
            // Удаляем найденное событие
            const deleteResponse = await fetch(`${API_BASE_URL}/equipment-events/${eventToDelete.id}`, {
              method: 'DELETE',
              headers: { 'Authorization': `Bearer ${authService.getToken()}` }
            });
            
            return await deleteResponse.json();
          }
          
          return null;
        });
        
        // Ждем выполнения всех запросов на удаление
        await Promise.all(deletePromises.filter(p => p !== null));
      } else {
        // Для обычного оборудования удаляем событие как раньше
        const response = await fetch(`${API_BASE_URL}/equipment-events/${deletingEventId}`, {
          method: 'DELETE',
          headers: { 
            'Authorization': `Bearer ${authService.getToken()}`
          }
        });
        
        const data = await response.json();
        console.log('Delete response:', data);
        
        if (!data.success && data.status !== 'success') {
          throw new Error(data.message || 'Ошибка удаления события');
        }
      }
      
      // Обновляем список событий
      try {
        const updatedEvents = await fetchAndGroupEvents(selectedEquipment, selectedDate);
        setEvents(updatedEvents);
        
        // Обновляем статус инструментов, так как могло измениться состояние оборудования
        await fetchToolStatus();
      } catch (err) {
        console.error('Error refreshing events:', err);
        setError(`Ошибка обновления списка событий: ${err.message}`);
      }
      
      setDeleteModal(false);
    } catch (err) {
      console.error('Error deleting event:', err);
      setError(`Ошибка удаления события: ${err.message}`);
      setDeleteModal(false);
    }
  }
  
  // Загрузка статуса инструментов
  const fetchToolStatus = async () => {
    if (!selectedEquipment) return
    
    setToolsLoading(true)
    try {
      const response = await equipmentToolService.getToolStatus(selectedEquipment.id)
      if (response.status === 'success') {
        setToolStatus(response.data)
      }
    } catch (error) {
      console.error('Error fetching tool status:', error)
      setError('Ошибка при получении статуса инструментов')
    } finally {
      setToolsLoading(false)
    }
  }

  // Загружаем статус инструментов при изменении оборудования и после каждого события
  useEffect(() => {
    fetchToolStatus()
  }, [selectedEquipment, events])

  // Обработчик запроса на переключение инструмента
  const handleToolToggleRequest = (toolType) => {
    if (!selectedEquipment) return
    
    const newStatus = toolStatus[toolType] === 'on' ? 'off' : 'on'
    setPendingToolToggle({
      toolType,
      newStatus
    })
    
    // Инициализируем eventData с текущей датой и временем
    setEventData({
      event_time: new Date(),
      comment: ''
    })
    
    setToolConfirmModal(true)
  }

  // Обработчик подтверждения переключения
  const handleToolToggleConfirm = async () => {
    if (!pendingToolToggle) return

    const { toolType, newStatus } = pendingToolToggle
    setToolConfirmModal(false)
    
    try {
      setToolsLoading(true)
      
      // Создаем копию даты события
      const eventTime = new Date(eventData.event_time);
      
      // Преобразуем в строку ISO с сохранением локальной временной зоны
      const eventTimeStr = eventTime.getFullYear() + '-' +
                          String(eventTime.getMonth() + 1).padStart(2, '0') + '-' +
                          String(eventTime.getDate()).padStart(2, '0') + ' ' +
                          String(eventTime.getHours()).padStart(2, '0') + ':' +
                          String(eventTime.getMinutes()).padStart(2, '0') + ':00';
      
      const response = await equipmentToolService.toggleTool(
        selectedEquipment.id,
        toolType,
        newStatus === 'on',
        eventTimeStr,
        eventData.comment
      )
      
      if (response.status === 'success') {
        // Немедленно обновляем локальное состояние
        setToolStatus(prev => ({
          ...prev,
          [toolType]: newStatus
        }))
        // Затем запрашиваем актуальное состояние с сервера
        await fetchToolStatus()
        // Обновляем список событий
        await fetchEvents()
      } else {
        throw new Error(response.message || 'Ошибка при переключении инструмента')
      }
    } catch (error) {
      console.error(`Error toggling ${toolType}:`, error)
      setError(`Ошибка при ${newStatus === 'on' ? 'включении' : 'выключении'} ${toolType === 'evaporator' ? 'испарителя' : 'АОС'}`)
      // В случае ошибки запрашиваем актуальное состояние с сервера
      await fetchToolStatus()
    } finally {
      setToolsLoading(false)
      setPendingToolToggle(null)
    }
  }
  
  if (loading && !events.length) {
    return (
      <div className="d-flex justify-content-center my-5">
        <CSpinner color="primary" />
      </div>
    )
  }
  
  return (
    <CCard>
      <CCardHeader>
        <h4>Пуски и Остановы Оборудования</h4>
        <CNav variant="tabs">
          <CNavItem>
            <CNavLink
              active={activeEquipmentType === EQUIPMENT_TYPES.BLOCK}
              onClick={() => setActiveEquipmentType(EQUIPMENT_TYPES.BLOCK)}
            >
              Турбогенераторы (ТГ)
            </CNavLink>
          </CNavItem>
          <CNavItem>
            <CNavLink
              active={activeEquipmentType === EQUIPMENT_TYPES.PGU}
              onClick={() => setActiveEquipmentType(EQUIPMENT_TYPES.PGU)}
            >
              Парогазовые установки (ПГУ)
            </CNavLink>
          </CNavItem>
        </CNav>
      </CCardHeader>
      <CCardBody>
        {error && <CAlert color="danger">{error}</CAlert>}
        
        <CRow className="mb-3">
          <CCol md={4}>
          <CButtonGroup className="mb-3 w-100">
            {equipmentList.map(equipment => {
                // подменяем ГТ 1/ГТ 2 на ПГУ 1/ПГУ 2, остальные — как есть
                const displayName =
                equipment.name === 'ГТ 1' ? 'ПГУ 1' :
                equipment.name === 'ГТ 2' ? 'ПГУ 2' :
                equipment.name;

                return (
                <CButton
                    key={equipment.id}
                    color={selectedEquipment?.id === equipment.id ? 'primary' : 'outline-primary'}
                    onClick={() => setSelectedEquipment(equipment)}
                >
                    {displayName}
                </CButton>
                )
            })}
            </CButtonGroup>

          </CCol>
          <CCol md={5}>
            <div className="mb-2">
              <strong>Выбранный период: </strong>
              {selectedDate instanceof Date ? 
                new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric' }).format(selectedDate) 
                : 'Не выбран'}
            </div>
            <CInputGroup>
              <CButton color="secondary" onClick={() => {
                const newDate = new Date(selectedDate);
                newDate.setMonth(newDate.getMonth() - 1);
                setSelectedDate(newDate);
              }}>
                <CIcon icon={cilChevronLeft} />
              </CButton>
              <CInputGroupText>
                <CIcon icon={cilCalendar} />
              </CInputGroupText>
              <DatePicker
                selected={selectedDate}
                onChange={(date) => {
                  if (date) {
                    console.log('Month/Year selected:', date);
                    setSelectedDate(date);
                  }
                }}
                dateFormat="MM.yyyy"
                showMonthYearPicker
                className="form-control"
                popperPlacement="bottom-start"
                popperModifiers={[
                  {
                    name: 'preventOverflow',
                    options: {
                      rootBoundary: 'viewport',
                      tether: false,
                      altAxis: true,
                    },
                  }
                ]}
                customInput={
                  <input
                    type="text"
                    className="form-control"
                    style={{ minWidth: '120px' }}
                  />
                }
              />
              <CButton color="secondary" onClick={() => {
                const newDate = new Date(selectedDate);
                newDate.setMonth(newDate.getMonth() + 1);
                setSelectedDate(newDate);
              }}>
                <CIcon icon={cilChevronRight} />
              </CButton>
              <CButton color="info" onClick={() => setSelectedDate(new Date())}>
                Текущий месяц
              </CButton>
            </CInputGroup>
          </CCol>
          <CCol md={3}>
            {selectedEquipment && (
              <CButton 
                color={equipmentStatus === 'running' ? 'danger' : 'success'}
                className="w-100"
                onClick={() => handleEventButtonClick(equipmentStatus === 'running' ? 'ostanov' : 'pusk')}
              >
                {equipmentStatus === 'running' ? 'Останов' : 'Пуск'}
              </CButton>
            )}
          </CCol>
        </CRow>
        
        {/* Инструменты */}
        {selectedEquipment && activeEquipmentType === EQUIPMENT_TYPES.PGU && (
          <CRow className="mb-3">
            <CCol>
              <div className="d-flex align-items-center gap-4">
                <div className="tool-item-block">
                  <CFormSwitch
                    id="evaporatorSwitch"
                    label="Испаритель"
                    checked={toolStatus.evaporator === 'on'}
                    onChange={() => handleToolToggleRequest('evaporator')}
                    disabled={determineEquipmentStatus() !== 'running' || toolsLoading}
                    className={`tool-switch tool-switch--${toolStatus.evaporator}`}
                  />
                </div>
                <div className="tool-item-block">
                  <CFormSwitch
                    id="aosSwitch"
                    label="АОС"
                    checked={toolStatus.aos === 'on'}
                    onChange={() => handleToolToggleRequest('aos')}
                    disabled={determineEquipmentStatus() !== 'running' || toolsLoading}
                    className={`tool-switch tool-switch--${toolStatus.aos}`}
                  />
                </div>
                {determineEquipmentStatus() !== 'running' && (
                  <small className="text-muted">
                    Управление инструментами доступно только при запущенном оборудовании
                  </small>
                )}
              </div>
            </CCol>
          </CRow>
        )}
        
        {selectedEquipment ? (
          <>
            <h5>
              События для {selectedEquipment.name}
              {selectedEquipment.components && selectedEquipment.components.length > 0 && (
                <span className="text-muted small ms-2">
                  (включает {selectedEquipment.components.map(c => c.name).join(', ')})
                </span>
              )}
            </h5>
            {events.length > 0 ? (
              <div style={{ height: '400px', overflowY: 'auto' }}>
                <CTable bordered hover>
                  <CTableHead>
                    <CTableRow>
                      <CTableHeaderCell>Дата и время</CTableHeaderCell>
                      <CTableHeaderCell>Тип события</CTableHeaderCell>
                      <CTableHeaderCell>Причина (для останова) / Тип (для пуска)</CTableHeaderCell>
                      <CTableHeaderCell>Комментарий</CTableHeaderCell>
                      <CTableHeaderCell>Действия</CTableHeaderCell>
                    </CTableRow>
                  </CTableHead>
                  <CTableBody>
                    {events.map(event => (
                      <CTableRow key={event.original_id || event.id}>
                        <CTableDataCell>{formatDateTime(event.event_time)}</CTableDataCell>
                        <CTableDataCell>
                          {event.event_type === 'pusk' ? 'Пуск' : 'Останов'}
                        </CTableDataCell>
                        <CTableDataCell>
                          {event.reason_name || getReasonNameById(event)}
                        </CTableDataCell>
                        <CTableDataCell>
                          {event.comment || '-'}
                        </CTableDataCell>
                        <CTableDataCell>
                          <CButton 
                            color="primary" 
                            size="sm"
                            onClick={() => handleEditEvent(event)}
                            className="me-2"
                          >
                            Редактировать
                          </CButton>
                          <CButton 
                            color="danger" 
                            size="sm"
                            onClick={() => handleDeleteEvent(event)}
                          >
                            Удалить
                          </CButton>
                        </CTableDataCell>
                      </CTableRow>
                    ))}
                  </CTableBody>
                </CTable>
              </div>
            ) : (
              <CAlert color="info">
                Нет событий для отображения в выбранном периоде.
              </CAlert>
            )}
          </>
        ) : (
          <CAlert color="warning">
            Выберите оборудование для просмотра событий.
          </CAlert>
        )}
        
        {/* Модальное окно для пуска/останова */}
        <CModal visible={eventModal} onClose={() => setEventModal(false)}>
          <CModalHeader closeButton>
            <CModalTitle>
              {isEditing ? 'Редактирование события' : eventType === 'pusk' ? 'Пуск оборудования' : 'Останов оборудования'}
            </CModalTitle>
          </CModalHeader>
          <CModalBody>
            {error && <CAlert color="danger">{error}</CAlert>}
            <CForm>
              <div className="mb-3">
                <CFormLabel>Дата и время</CFormLabel>
                <DatePicker
                  selected={eventData.event_time}
                  onChange={date => setEventData({...eventData, event_time: date})}
                  showTimeSelect
                  timeFormat="HH:mm"
                  timeIntervals={15}
                  dateFormat="dd.MM.yyyy HH:mm"
                  className="form-control"
                />
              </div>
              
              {eventType === 'ostanov' && (
                <div className="mb-3">
                  <CFormLabel>Причина останова</CFormLabel>
                  <CFormSelect
                    value={eventData.reason_id || ''}
                    onChange={e => setEventData({...eventData, reason_id: parseInt(e.target.value)})}
                  >
                    {stopReasons.map(reason => (
                      <option key={reason.id} value={reason.id}>
                        {reason.name}
                      </option>
                    ))}
                  </CFormSelect>
                </div>
              )}

              {eventType === 'pusk' && (
                <div className="mb-3">
                  <CFormLabel>Тип пуска</CFormLabel>
                  <CFormSelect
                    value={eventData.reason_id || ''}
                    onChange={e => setEventData({...eventData, reason_id: parseInt(e.target.value)})}
                  >
                    {startReasons.map(reason => (
                      <option key={reason.id} value={reason.id} title={reason.description}>
                        {reason.name}
                      </option>
                    ))}
                  </CFormSelect>
                  {startReasons.find(r => r.id === parseInt(eventData.reason_id))?.description && (
                    <div className="form-text text-muted mt-1">
                      {startReasons.find(r => r.id === parseInt(eventData.reason_id)).description}
                    </div>
                  )}
                </div>
              )}
              
              <div className="mb-3">
                <CFormLabel>Комментарий</CFormLabel>
                <CFormInput
                  type="text"
                  value={eventData.comment || ''}
                  onChange={e => setEventData({...eventData, comment: e.target.value})}
                />
              </div>
            </CForm>
          </CModalBody>
          <CModalFooter>
            <CButton color="secondary" onClick={() => setEventModal(false)}>
              Отмена
            </CButton>
            <CButton color="primary" onClick={handleSaveEvent}>
              Сохранить
            </CButton>
          </CModalFooter>
        </CModal>
        
        {/* Модальное окно для подтверждения удаления */}
        <CModal visible={deleteModal} onClose={() => setDeleteModal(false)}>
          <CModalHeader closeButton>
            <CModalTitle>Подтверждение удаления</CModalTitle>
          </CModalHeader>
          <CModalBody>
            {deletingEventInfo && (
              <div>
                <p>Вы действительно хотите удалить это событие?</p>
                <p>
                  <strong>Тип:</strong> {deletingEventInfo.event_type === 'pusk' ? 'Пуск' : 'Останов'}<br />
                  <strong>Дата и время:</strong> {formatDateTime(deletingEventInfo.event_time)}<br />
                  {deletingEventInfo.event_type === 'ostanov' && deletingEventInfo.reason_id && (
                    <><strong>Причина останова:</strong> {getReasonNameById(deletingEventInfo)}<br /></>
                  )}
                  {deletingEventInfo.event_type === 'pusk' && deletingEventInfo.reason_id && (
                    <><strong>Тип пуска:</strong> {getReasonNameById(deletingEventInfo)}<br /></>
                  )}
                  {deletingEventInfo.comment && (
                    <><strong>Комментарий:</strong> {deletingEventInfo.comment}<br /></>
                  )}
                </p>
              </div>
            )}
          </CModalBody>
          <CModalFooter>
            <CButton color="secondary" onClick={() => setDeleteModal(false)}>
              Нет
            </CButton>
            <CButton color="danger" onClick={confirmDeleteEvent}>
              Да, удалить
            </CButton>
          </CModalFooter>
        </CModal>

        {/* Модальное окно для переключения инструмента с выбором даты и времени */}
        <CModal visible={toolConfirmModal} onClose={() => {
          setToolConfirmModal(false)
          setPendingToolToggle(null)
        }}>
          <CModalHeader closeButton>
            <CModalTitle>Переключение инструмента</CModalTitle>
          </CModalHeader>
          <CModalBody>
            {pendingToolToggle && (
              <CForm>
                <div className="mb-3">
                  <CFormLabel>
                    {pendingToolToggle.newStatus === 'on' ? 'Включение' : 'Выключение'} 
                    {' '}{pendingToolToggle.toolType === 'evaporator' ? 'испарителя' : 'АОС'}
                  </CFormLabel>
                </div>
                <div className="mb-3">
                  <CFormLabel>Дата и время события</CFormLabel>
                  <DatePicker
                    selected={eventData.event_time}
                    onChange={date => setEventData({...eventData, event_time: date})}
                    showTimeSelect
                    timeFormat="HH:mm"
                    timeIntervals={15}
                    dateFormat="dd.MM.yyyy HH:mm"
                    className="form-control"
                  />
                </div>
                <div className="mb-3">
                  <CFormLabel>Комментарий</CFormLabel>
                  <CFormInput
                    type="text"
                    value={eventData.comment || ''}
                    onChange={e => setEventData({...eventData, comment: e.target.value})}
                    placeholder="Опционально"
                  />
                </div>
              </CForm>
            )}
          </CModalBody>
          <CModalFooter>
            <CButton 
              color="secondary" 
              onClick={() => {
                setToolConfirmModal(false)
                setPendingToolToggle(null)
              }}
            >
              Отмена
            </CButton>
            <CButton 
              color={pendingToolToggle?.newStatus === 'on' ? 'success' : 'danger'} 
              onClick={handleToolToggleConfirm}
            >
              Сохранить
            </CButton>
          </CModalFooter>
        </CModal>
      </CCardBody>
    </CCard>
  )
}

export default EquipmentEvents 
