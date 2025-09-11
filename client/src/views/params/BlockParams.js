import React, { useEffect, useState } from 'react'
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
import './BlockParams.scss'

// Предопределенные параметры, если API не возвращает их
const defaultParams = [
  { id: 1, name: 'Выработка э/эн ТГ:', unit: 'тыс.kW/h' },
  { id: 2, name: 'Отпуск э/эн с шин:', unit: 'тыс.kW/h' },
  { id: 3, name: 'Э/эн СН:', unit: 'тыс.kW/h' }
];

// Предопределенные смены
const defaultShifts = [
  { id: '1', name: 'Смена 1' },
  { id: '2', name: 'Смена 2' },
  { id: '3', name: 'Смена 3' }
];

const BlockParams = () => {
  const [loading, setLoading] = useState(true)
  const [loadingValues, setLoadingValues] = useState(false)
  const [error, setError] = useState(null)
  const [params, setParams] = useState([])
  const [blocks, setBlocks] = useState([])
  const [selectedShift, setSelectedShift] = useState('1')
  const [selectedDate, setSelectedDate] = useState(new Date().toISOString().split('T')[0])
  const [values, setValues] = useState({})
  const [shifts, setShifts] = useState(defaultShifts)
  const [dataLoaded, setDataLoaded] = useState(false)

  // Функция загрузки значений для выбранной даты и смены
  const loadValuesForDateAndShift = async (date, shift) => {
    if (blocks.length === 0 || params.length === 0) return
    
    setLoadingValues(true)
    
    try {
      // Инициализируем пустые значения для всех параметров и блоков
      const initialValues = {}
      params.forEach(param => {
        initialValues[param.id] = {}
        blocks.forEach(block => {
          initialValues[param.id][block.id] = '' // Пустая строка вместо '0'
        })
      })
      
      // Загружаем значения для каждого блока отдельно
      for (const block of blocks) {
        try {
          // API требует equipment_id
          const valuesRes = await fetch(`http://exepower/api/parameter-values?date=${date}&shift_id=${shift}&equipment_id=${block.id}`, {
            headers: { 'Authorization': `Bearer ${authService.getToken()}` }
          })
          const valuesData = await valuesRes.json()
          console.log(`Values for block ${block.id} response:`, valuesData)
          
          // Если API вернул значения, заполняем ими наш объект values
          if ((valuesData.success || valuesData.status === 'success') && valuesData.data?.values) {
            const dbValues = valuesData.data.values
            
            // Заполняем значения из базы данных
            dbValues.forEach(item => {
              if (initialValues[item.parameter_id]) {
                initialValues[item.parameter_id][block.id] = item.value
              }
            })
          }
        } catch (blockError) {
          console.warn(`Error loading values for block ${block.id}:`, blockError)
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
        // Получаем список блоков (ТГ7, ТГ8)
        const eqRes = await fetch('http://exepower/api/equipment?type=block', {
          headers: { 'Authorization': `Bearer ${authService.getToken()}` }
        })
        const eqData = await eqRes.json()
        console.log('Equipment response:', eqData)
        
        if (!eqData.success && eqData.status !== 'success') throw new Error(eqData.message || 'Ошибка загрузки оборудования')
        
        // Используем данные из ответа
        const equipmentData = eqData.data?.equipment || []
        setBlocks(equipmentData)
        
        try {
          // Получаем параметры для блоков
          const paramRes = await fetch('http://exepower/api/parameters?equipment_type_id=1', {
            headers: { 'Authorization': `Bearer ${authService.getToken()}` }
          })
          const paramData = await paramRes.json()
          console.log('Parameters response:', paramData)
          
          if (paramData.success || paramData.status === 'success') {
            setParams(paramData.data?.parameters || defaultParams)
          } else {
            console.warn('Using default parameters due to API error')
            setParams(defaultParams)
          }
        } catch (paramError) {
          console.error('Error fetching parameters:', paramError)
          setParams(defaultParams)
        }
        
        // Используем предопределенные смены вместо запроса к API
        // так как эндпоинт /shifts не существует
      } catch (e) {
        console.error('Error in main fetch:', e)
        setError(e.message)
        // Если произошла ошибка, используем предопределенные параметры
        setParams(defaultParams)
      } finally {
        setLoading(false)
      }
    }
    
    fetchData()
  }, [])
  
  // Загрузка значений после загрузки блоков и параметров
  useEffect(() => {
    if (!loading && blocks.length > 0 && params.length > 0 && !dataLoaded) {
      loadValuesForDateAndShift(selectedDate, selectedShift)
    }
  }, [loading, blocks, params, dataLoaded])
  
  // Загрузка значений при изменении даты или смены
  useEffect(() => {
    if (!loading && dataLoaded) {
      loadValuesForDateAndShift(selectedDate, selectedShift)
    }
  }, [selectedDate, selectedShift])
  
  const handleFocus = (paramId, blockId, e) => {
    // Если значение равно "0", очищаем поле при фокусе
    if (e.target.value === '0') {
      e.target.value = '';
      handleValueChange(paramId, blockId, '');
    }
  }

  const handleBlur = (paramId, blockId, e) => {
    // При потере фокуса оставляем как есть - пустое поле остается пустым
    // Не принудительно ставим "0"
  }

  const normalizeDecimalValue = (value) => {
    // Заменяем запятую на точку для правильного парсинга
    return value.toString().replace(',', '.');
  }

  const handleValueChange = (paramId, blockId, value) => {
    // Нормализуем значение
    const normalizedValue = normalizeDecimalValue(value);
    setValues(prev => {
              const newValues = {
          ...prev,
          [paramId]: {
            ...prev[paramId],
            [blockId]: normalizedValue
          }
        }
      
      // Автоматический расчет для ОЧ-130 на основе ТГ7 и ТГ8
      if (blockId === 1 || blockId === 2) { // Если изменили ТГ7 или ТГ8
        const tg7Value = parseFloat(newValues[paramId]?.[1]   || 0);
        const tg8Value = parseFloat(newValues[paramId]?.[2] || 0);
        
        // Определяем формулу для расчета ОЧ-130 в зависимости от parameter_id
        let och130Value = null; // Используем null чтобы отличить "не вычисляется" от "равно 0"
        
        // Маппинг parameter_id к соответствующим cell (E14, E15, E20, E22, E30, E31)
        if (paramId === 29) { // E14 - P острого пара (СРЗНАЧ)
          och130Value = (tg7Value + tg8Value) / 2;
        } else if (paramId === 30) { // E15 - t острого пара (СРЗНАЧ)  
          och130Value = (tg7Value + tg8Value) / 2;
        } else if (paramId === 35) { // E20 - Выработка пара котлами (СУММ)
          och130Value = tg7Value + tg8Value;
        } else if (paramId === 37) { // E22 - Расход питательной воды (СУММ)
          och130Value = tg7Value + tg8Value;
        } else if (paramId === 45) { // E30 - В топлива за месяц (газ) (СУММ)
          och130Value = tg7Value + tg8Value;
        } else if (paramId === 46) { // E31 - В топлива за месяц (мазут) (СУММ)
          och130Value = tg7Value + tg8Value;
        }
        
        // Устанавливаем вычисленное значение для ОЧ-130 ТОЛЬКО если это вычисляемый параметр
        if (och130Value !== null) {
          if (!newValues[paramId]) {
            newValues[paramId] = {};
          }
          newValues[paramId][7] = och130Value.toFixed(2);
        }
      }
      
      return newValues;
    })
  }
  
  const handleSave = async () => {
    try {
      setError(null)
      setLoadingValues(true)
      
      let hasValuesToSave = false;
      let savedCount = 0;
      
      // Перебираем все блоки (ТГ7, ТГ8, ОЧ-130)
      for (const block of blocks) {
        // Собираем все значения для текущего блока
        const blockValues = [];
        
        // Перебираем все параметры для текущего блока
        for (const param of params) {
          const paramId = param.id;
          const blockId = block.id;
          
          // Пропускаем скрытые поля ОЧ-130 (E21, E23, E24, E25, E26, E27)
          if (blockId === 7 && [36, 38, 39, 40, 41, 42].includes(paramId)) {
            continue;
          }
          
          // Получаем значение для текущего параметра и блока
          const value = values[paramId]?.[blockId];
          
          // Пропускаем пустые значения (но сохраняем 0 если он введен пользователем)
          if (value === null || value === undefined || value === '0' || value.toString().trim() === '') {
            continue;
          }
          
          // Добавляем значение в массив для текущего блока
          blockValues.push({
            parameterId: parseInt(paramId),
            value: value
          });
        }
        
        // Если нет значений для сохранения, пропускаем запрос
        if (blockValues.length === 0) {
          console.log(`No non-zero values to save for block ${block.id}, skipping`);
          continue;
        }
        
        hasValuesToSave = true;
        console.log(`Saving values for block ${block.id}:`, blockValues);
        
        try {
          // Используем equipment_id напрямую из block.id
          const equipmentId = parseInt(block.id);
          
          // Формируем данные для API в точном соответствии с требованиями API
          const requestData = {
            equipmentId: equipmentId,
            date: selectedDate,
            shiftId: parseInt(selectedShift),
            values: blockValues
          };
          
          console.log('Request data:', JSON.stringify(requestData));
          
          // Отправляем запрос на сохранение
          const response = await fetch('http://exepower/api/parameter-values', {
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
          console.log(`API success response for block ${block.id}:`, data);
          
          if (!data.success && data.status !== 'success') {
            throw new Error(data.message || `Ошибка при сохранении данных`);
          }
          
          savedCount += blockValues.length;
        } catch (blockError) {
          console.error(`Error saving values for block ${block.id}:`, blockError);
          throw new Error(`Ошибка сохранения для блока ${block.name}: ${blockError.message}`);
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
  if (blocks.length === 0) {
    return <CAlert color="warning">Нет данных о блоках. Пожалуйста, проверьте соединение с сервером.</CAlert>
  }

  return (
    <CCard>
      <CCardBody>
        <h4>Параметры Блоков</h4>
        
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
              {blocks.map(block => (
                <CTableHeaderCell key={block.id}>{block.name}</CTableHeaderCell>
              ))}
            </CTableRow>
          </CTableHead>
          <CTableBody>
            {(params.length > 0 ? params : defaultParams).map((param, idx) => {
              // Определяем, является ли это вычисляемым параметром для ОЧ-130
              const isCalculatedParam = [29, 30, 35, 37, 45, 46].includes(param.id);
              
              // Определяем, скрывается ли поле ОЧ-130 для этого параметра (E21, E23, E24, E25, E26, E27)
              const isOch130Hidden = [36, 38, 39, 40, 41, 42].includes(param.id);
              
              return (
                <CTableRow key={param.id || idx}>
                  <CTableDataCell>{idx + 1}</CTableDataCell>
                  <CTableDataCell>{param.name}</CTableDataCell>
                  <CTableDataCell>{param.unit}</CTableDataCell>
                  {blocks.map(block => {
                    // Для блока ОЧ-130 (equipment_id = 7) применяем специальную логику
                    if (block.id === 7) {
                      return (
                        <CTableDataCell key={block.id}>
                          {isOch130Hidden ? (
                            <span style={{ color: '#999', fontStyle: 'italic' }}></span>
                          ) : (
                            <CFormInput
                              type="number"
                              value={values[param.id]?.[block.id] || ''}
                              onChange={(e) => handleValueChange(param.id, block.id, e.target.value)}
                              onFocus={!isCalculatedParam ? (e) => handleFocus(param.id, block.id, e) : undefined}
                              onBlur={!isCalculatedParam ? (e) => handleBlur(param.id, block.id, e) : undefined}
                              style={{ 
                                minWidth: '80px'
                              }}
                              disabled={loadingValues || isCalculatedParam}
                              readOnly={isCalculatedParam}
                              title={isCalculatedParam ? 'Автоматически вычисляется из ТГ7 и ТГ8' : ''}
                            />
                          )}
                        </CTableDataCell>
                      );
                                          } else {
                        // Обычные блоки ТГ7, ТГ8
                        return (
                          <CTableDataCell key={block.id}>
                            <CFormInput
                              type="number"
                              step="any"
                              value={values[param.id]?.[block.id] || ''}
                              onChange={(e) => handleValueChange(param.id, block.id, e.target.value)}
                              onFocus={(e) => handleFocus(param.id, block.id, e)}
                              onBlur={(e) => handleBlur(param.id, block.id, e)}
                              style={{ minWidth: '80px' }}
                              disabled={loadingValues}
                              lang="en-US"
                            />
                          </CTableDataCell>
                        );
                      }
                  })}
                </CTableRow>
              );
            })}
          </CTableBody>
        </CTable>
      </CCardBody>
    </CCard>
  )
}

export default BlockParams 