import React, { useEffect, useState } from 'react'
import { API_BASE_URL } from '../../config/api'
import { 
  CCard, 
  CCardBody, 
  CTable, 
  CTableHead, 
  CTableRow, 
  CTableHeaderCell, 
  CTableBody, 
  CTableDataCell, 
  CSpinner, 
  CAlert,
  CFormSelect,
  CFormInput,
  CButton,
  CRow,
  CCol
} from '@coreui/react'
import authService from '../../services/authService'
import './PguParams.scss'

// Предопределенные смены
const defaultShifts = [
  { id: '1', name: 'Смена 1' },
  { id: '2', name: 'Смена 2' },
  { id: '3', name: 'Смена 3' }
];

// Функция определения видимости поля для конкретного оборудования и параметра
const isFieldVisible = (parameterId, equipmentId, equipmentName) => {
  const paramId = parseInt(parameterId);
  const eqId = parseInt(equipmentId);
  
  // Определяем тип оборудования
  const isGT = equipmentName?.includes('ГТ'); // Газовая турбина
  const isPT = equipmentName?.includes('ПТ'); // Паровая турбина
  
  // Параметры только для ПТ1/ПТ2
  const ptOnlyParams = [11, 12, 18, 19]; // Отпуск тепла, cosφПТ, температура градирни
  
  // Параметры только для ГТ1/ГТ2  
  const gtOnlyParams = [13, 14, 15, 16, 17, 20, 21, 22, 23, 24, 25, 26, 27, 28]; // Давление, влажность, температуры, газ, топливо
  
  // Общие параметры (показываем для всех)
  const commonParams = []; // Все параметры теперь привязаны к конкретному типу оборудования
  
  if (ptOnlyParams.includes(paramId)) {
    return isPT; // Показываем только для ПТ
  }
  
  if (gtOnlyParams.includes(paramId)) {
    return isGT; // Показываем только для ГТ
  }
  
  if (commonParams.includes(paramId)) {
    return true; // Показываем для всех
  }
  
  // По умолчанию показываем
  return true;
};

const PguParams = () => {
  const [loading, setLoading] = useState(true)
  const [loadingValues, setLoadingValues] = useState(false)
  const [error, setError] = useState(null)
  const [params, setParams] = useState([])
  const [units, setUnits] = useState([])
  const [selectedShift, setSelectedShift] = useState('1')
  const [selectedDate, setSelectedDate] = useState(new Date().toISOString().split('T')[0])
  const [values, setValues] = useState({})
  const [shifts, setShifts] = useState(defaultShifts)
  const [dataLoaded, setDataLoaded] = useState(false)

  // Функция загрузки значений для выбранной даты и смены
  const loadValuesForDateAndShift = async (date, shift) => {
    if (units.length === 0 || params.length === 0) return
    
    setLoadingValues(true)
    
    try {
      // Инициализируем пустые значения для всех параметров и блоков
      const initialValues = {}
      params.forEach(param => {
        initialValues[param.id] = {}
        units.forEach(unit => {
          initialValues[param.id][unit.id] = '' // Пустая строка вместо '0'
        })
      })
      
      // Загружаем значения для каждого блока отдельно
      for (const unit of units) {
        try {
          // API требует equipment_id
          const valuesRes = await fetch(`${API_BASE_URL}/parameter-values?date=${date}&shift_id=${shift}&equipment_id=${unit.id}`, {
            headers: { 'Authorization': `Bearer ${authService.getToken()}` }
          })
          const valuesData = await valuesRes.json()
          console.log(`Values for unit ${unit.id} response:`, valuesData)
          
          // Если API вернул значения, заполняем ими наш объект values
          if ((valuesData.success || valuesData.status === 'success') && valuesData.data?.values) {
            const dbValues = valuesData.data.values
            
            // Заполняем значения из базы данных
            dbValues.forEach(item => {
              if (initialValues[item.parameter_id]) {
                initialValues[item.parameter_id][unit.id] = item.value
              }
            })
          }
        } catch (unitError) {
          console.warn(`Error loading values for unit ${unit.id}:`, unitError)
        }
      }
      
      // Устанавливаем значения (из БД или нули)
      setValues(initialValues)
      setDataLoaded(true)
    } catch (e) {
      console.error('Error loading values for date and shift:', e)
    } finally {
      setLoadingValues(false)
    }
  }

  // Загрузка блоков и параметров при инициализации
  useEffect(() => {
    const fetchData = async () => {
      setLoading(true)
      setError(null)
      try {
        // Получаем список оборудования типа ПГУ (ГТ1, ПТ1, ГТ2, ПТ2)
        const eqRes = await fetch(`${API_BASE_URL}/equipment?type=pgu`, {
          headers: { 'Authorization': `Bearer ${authService.getToken()}` }
        })
        const eqData = await eqRes.json()
        console.log('Equipment response:', eqData)
        
        if (!eqData.success && eqData.status !== 'success') {
          throw new Error(eqData.message || 'Ошибка загрузки оборудования')
        }
        
        // Используем данные из ответа
        const equipmentData = eqData.data?.equipment || []
        setUnits(equipmentData)
        
        // Получаем параметры для ПГУ
        const paramRes = await fetch(`${API_BASE_URL}/parameters?equipment_type_id=2`, {
          headers: { 'Authorization': `Bearer ${authService.getToken()}` }
        })
        const paramData = await paramRes.json()
        console.log('Parameters response:', paramData)
        
        if (!paramData.success && paramData.status !== 'success') {
          throw new Error(paramData.message || 'Ошибка загрузки параметров')
        }
        setParams(paramData.data?.parameters || [])
        
      } catch (e) {
        console.error('Error in main fetch:', e)
        setError(e.message)
      } finally {
        setLoading(false)
      }
    }
    
    fetchData()
  }, [])
  
  // Загрузка значений после загрузки блоков и параметров
  useEffect(() => {
    if (!loading && units.length > 0 && params.length > 0 && !dataLoaded) {
      loadValuesForDateAndShift(selectedDate, selectedShift)
    }
  }, [loading, units, params, dataLoaded])
  
  // Загрузка значений при изменении даты или смены
  useEffect(() => {
    if (!loading && dataLoaded) {
      loadValuesForDateAndShift(selectedDate, selectedShift)
    }
  }, [selectedDate, selectedShift])
  
  const handleFocus = (paramId, unitId, e) => {
    // Если значение равно "0", очищаем поле при фокусе
    if (e.target.value === '0') {
      e.target.value = '';
      handleValueChange(paramId, unitId, '');
    }
  }

  const handleBlur = (paramId, unitId, e) => {
    // При потере фокуса оставляем как есть - пустое поле остается пустым
    // Не принудительно ставим "0"
  }

  const normalizeDecimalValue = (value) => {
    // Заменяем запятую на точку для правильного парсинга
    return value.toString().replace(',', '.');
  }

  const handleValueChange = (paramId, unitId, value) => {
    // Нормализуем значение
    const normalizedValue = normalizeDecimalValue(value);
    setValues(prev => ({
      ...prev,
      [paramId]: {
        ...prev[paramId],
        [unitId]: normalizedValue
      }
    }))
  }
  
  const handleSave = async () => {
    try {
      setError(null)
      setLoadingValues(true)
      
      let hasValuesToSave = false;
      let savedCount = 0;
      
      // Перебираем все блоки
      for (const unit of units) {
        // Собираем все значения для текущего блока
        const unitValues = [];
        
        // Перебираем все параметры для текущего блока
        for (const param of params) {
          const paramId = param.id;
          const unitId = unit.id;
          
          // Проверяем видимость поля - не сохраняем скрытые поля
          if (!isFieldVisible(paramId, unitId, unit.name)) {
            continue;
          }
          
          // Получаем значение для текущего параметра и блока
          const value = values[paramId]?.[unitId];
          
          // Пропускаем пустые значения (но сохраняем 0 если он введен пользователем)
          if (value === null || value === undefined || value === '0' || value.toString().trim() === '') {
            continue;
          }
          
          // Добавляем значение в массив для текущего блока
          unitValues.push({
            parameterId: parseInt(paramId),
            value: value
          });
        }
        
        // Если нет значений для сохранения, пропускаем запрос
        if (unitValues.length === 0) {
          console.log(`No non-zero values to save for unit ${unit.id}, skipping`);
          continue;
        }
        
        hasValuesToSave = true;
        console.log(`Saving values for unit ${unit.id}:`, unitValues);
        
        try {
          // Формируем данные для API в точном соответствии с требованиями API
          const requestData = {
            equipmentId: parseInt(unit.id),
            date: selectedDate,
            shiftId: parseInt(selectedShift),
            values: unitValues
          };
          
          console.log('Request data:', JSON.stringify(requestData));
          
          // Отправляем запрос на сохранение
          const response = await fetch(`${API_BASE_URL}/parameter-values`, {
            method: 'POST',
            headers: { 
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${authService.getToken()}`
            },
            body: JSON.stringify(requestData)
          });
          
          // Обработка ответа
          if (!response.ok) {
            const errorText = await response.text();
            let errorMessage = `Ошибка HTTP: ${response.status}`;
            
            try {
              const errorData = JSON.parse(errorText);
              if (errorData.message) {
                errorMessage = errorData.message;
              }
            } catch (e) {
              console.error('Failed to parse error response:', e);
            }
            
            throw new Error(errorMessage);
          }
          
          const data = await response.json();
          console.log(`API success response for unit ${unit.id}:`, data);
          
          if (!data.success && data.status !== 'success') {
            throw new Error(data.message || `Ошибка при сохранении данных`);
          }
          
          savedCount += unitValues.length;
        } catch (unitError) {
          console.error(`Error saving values for unit ${unit.id}:`, unitError);
          throw new Error(`Ошибка сохранения для блока ${unit.name}: ${unitError.message}`);
        }
      }
      
      if (!hasValuesToSave) {
        alert('Нет данных для сохранения. Введите значения, отличные от нуля.');
      } else {
        alert(`Данные успешно сохранены. Сохранено значений: ${savedCount}`);
        
        // Перезагрузим значения, чтобы отобразить актуальные данные
        loadValuesForDateAndShift(selectedDate, selectedShift);
      }
    } catch (e) {
      setError(`Ошибка при сохранении: ${e.message}`);
    } finally {
      setLoadingValues(false);
    }
  }

  if (loading) return <CSpinner color="primary" />
  if (error) return <CAlert color="danger">{error}</CAlert>
  
  // Если нет данных после загрузки
  if (units.length === 0) {
    return <CAlert color="warning">Нет данных о блоках ПГУ. Пожалуйста, проверьте соединение с сервером.</CAlert>
  }

  return (
    <CCard>
      <CCardBody>
        <h4>Параметры ПГУ (ГТ1, ПТ1, ГТ2, ПТ2)</h4>
        
        <CRow className="mb-3">
          <CCol md={3}>
            <CFormSelect 
              label="Смена"
              value={selectedShift}
              onChange={(e) => setSelectedShift(e.target.value)}
              disabled={loadingValues}
            >
              {shifts.map(shift => (
                <option key={shift.id} value={shift.id}>
                  {shift.name}
                </option>
              ))}
            </CFormSelect>
          </CCol>
          <CCol md={3}>
            <CFormInput
              type="date"
              label="Дата"
              value={selectedDate}
              onChange={(e) => setSelectedDate(e.target.value)}
              disabled={loadingValues}
            />
          </CCol>
          <CCol md={3} className="d-flex align-items-end">
            <CButton color="primary" onClick={handleSave} disabled={loadingValues}>
              {loadingValues ? 'Загрузка...' : 'Сохранить'}
            </CButton>
          </CCol>
        </CRow>
        
        {loadingValues && (
          <div className="text-center my-3">
            <CSpinner size="sm" /> Загрузка значений...
          </div>
        )}
        
        <CTable bordered hover responsive>
          <CTableHead>
            <CTableRow>
              <CTableHeaderCell>#</CTableHeaderCell>
              <CTableHeaderCell>Наименование</CTableHeaderCell>
              <CTableHeaderCell>Обозначение</CTableHeaderCell>
              {units.map(unit => (
                <CTableHeaderCell key={unit.id}>{unit.name}</CTableHeaderCell>
              ))}
            </CTableRow>
          </CTableHead>
          <CTableBody>
            {params.map((param, idx) => (
              <CTableRow key={param.id}>
                <CTableDataCell>{idx + 1}</CTableDataCell>
                <CTableDataCell>{param.name}</CTableDataCell>
                <CTableDataCell>{param.unit}</CTableDataCell>
                {units.map(unit => (
                  <CTableDataCell key={unit.id}>
                    {isFieldVisible(param.id, unit.id, unit.name) ? (
                    <CFormInput
                      type="number"
                      step="any"
                      value={values[param.id]?.[unit.id] || ''}
                      onChange={(e) => handleValueChange(param.id, unit.id, e.target.value)}
                      onFocus={(e) => handleFocus(param.id, unit.id, e)}
                      onBlur={(e) => handleBlur(param.id, unit.id, e)}
                      style={{ minWidth: '80px' }}
                      disabled={loadingValues}
                      lang="en-US"
                    />
                    ) : (
                      // Пустая ячейка для недоступных полей
                      <div style={{ height: '38px' }}></div>
                    )}
                  </CTableDataCell>
                ))}
              </CTableRow>
            ))}
          </CTableBody>
        </CTable>
      </CCardBody>
    </CCard>
  )
}

export default PguParams 