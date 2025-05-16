import React, { useState, useEffect } from 'react'
import {
  CCard,
  CCardBody,
  CCardHeader,
  CRow,
  CCol,
  CForm,
  CFormSelect,
  CButton,
  CSpinner,
  CAlert,
  CTable,
  CTableHead,
  CTableRow,
  CTableHeaderCell,
  CTableBody,
  CTableDataCell,
  CModal,
  CModalHeader,
  CModalTitle,
  CModalBody,
  CModalFooter,
  CInputGroup,
  CFormInput,
  CFormLabel
} from '@coreui/react'
import DatePicker from 'react-datepicker'
import 'react-datepicker/dist/react-datepicker.css'
import { format } from 'date-fns'
import energyService from '../../services/energyService'

// Utility function to format numbers with dot as decimal separator
const formatNumber = (value, decimals = 3) => {
  if (value === undefined || value === null) return '';
  return Number(value).toFixed(decimals).replace(',', '.');
}

const EnergyMeters = () => {
  // State variables
  const [metrics, setMetrics] = useState([])
  const [selectedMetricId, setSelectedMetricId] = useState('')
  const [selectedDate, setSelectedDate] = useState(new Date())
  const [meters, setMeters] = useState([])
  const [shifts, setShifts] = useState([])
  const [readings, setReadings] = useState({})
  const [replacements, setReplacements] = useState({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [success, setSuccess] = useState(null)
  const [isDirty, setIsDirty] = useState(false)
  const [showReplacementModal, setShowReplacementModal] = useState(false)
  const [selectedMeter, setSelectedMeter] = useState(null)
  const [selectedMeterId, setSelectedMeterId] = useState(null)
  const [replacementData, setReplacementData] = useState({
    meter_id: '',
    date: '',
    replacement_time: '08:00',
    old_serial: '',
    old_coefficient: '',
    old_reading: '',
    new_serial: '',
    new_coefficient: '',
    new_reading: '',
    downtime_minutes: 0,
    power_at_replacement: '',
    has_existing_replacement: false,
    replacement_id: null
  })
  
  // Load metrics on component mount
  useEffect(() => {
    const loadMetrics = async () => {
      try {
        const metricsData = await energyService.getEnergyMetrics()
        const metricsArray = Array.isArray(metricsData) ? metricsData : []
        setMetrics(metricsArray)
        
        if (metricsArray.length > 0) {
          setSelectedMetricId(metricsArray[0].id)
        }
      } catch (err) {
        setError('Failed to load energy metrics')
        console.error(err)
        setMetrics([])
      }
    }
    
    loadMetrics()
  }, [])
  
  // Load meter readings when metric or date changes
  useEffect(() => {
    if (selectedMetricId) {
      loadMeterReadings()
    }
  }, [selectedMetricId, selectedDate])
  
  // Load meter readings data
  const loadMeterReadings = async () => {
    setLoading(true)
    setError(null)
    
    try {
      const formattedDate = format(selectedDate, 'yyyy-MM-dd')
      const data = await energyService.getMeterReadings(formattedDate, selectedMetricId)
      
      setMeters(data.meters || [])
      setShifts(data.shifts || [])
      setReadings(data.readings || {})
      setReplacements(data.replacements || {})
      setIsDirty(false)
    } catch (err) {
      setError('Failed to load meter readings')
      console.error(err)
    } finally {
      setLoading(false)
    }
  }
  
  // Handle metric change
  const handleMetricChange = (e) => {
    setSelectedMetricId(e.target.value)
  }
  
  // Handle date change
  const handleDateChange = (date) => {
    setSelectedDate(date)
  }
  
  // Handle reading change
  const handleReadingChange = (meterId, shiftId, field, value) => {
    setIsDirty(true)
    
    // Ensure value uses dot as decimal separator
    value = value.toString().replace(',', '.')
    
    setReadings(prevReadings => {
      const updatedReadings = { ...prevReadings }
      
      if (!updatedReadings[meterId]) {
        updatedReadings[meterId] = {}
      }
      
      if (!updatedReadings[meterId][shiftId]) {
        updatedReadings[meterId][shiftId] = {}
      }
      
      updatedReadings[meterId][shiftId] = {
        ...updatedReadings[meterId][shiftId],
        [field]: value
      }
      
      return updatedReadings
    })
  }
  
  // Get coefficient for a meter
  const getCoefficient = (meterId) => {
    // Получаем коэффициент из объекта счетчика
    const meter = meters.find(m => m.id === meterId)
    if (meter && meter.coefficient) {
      return parseFloat(meter.coefficient.toString().replace(',', '.'))
    }
    
    // Значение по умолчанию
    return 1.0
  }
  
  // Calculate consumption based on readings
  const calculateConsumption = (meterId, shiftId) => {
    const meterReadings = readings[meterId]
    if (!meterReadings) return 0
    
    const replacement = replacements[meterId]
    
    // Get readings based on shift
    let startReading = 0
    let endReading = 0
    
    // Set the readings based on shift
    if (shiftId === 1) {
      // Shift 1: R0 to R8
      startReading = meters.find(m => m.id === meterId)?.prev_reading || 0
      endReading = meterReadings[1]?.reading_end || 0
    } else if (shiftId === 2) {
      // Shift 2: R8 to R16
      startReading = meterReadings[1]?.reading_end || 0
      endReading = meterReadings[2]?.reading_end || 0
    } else if (shiftId === 3) {
      // Shift 3: R16 to R24
      startReading = meterReadings[2]?.reading_end || 0
      endReading = meterReadings[3]?.reading_end || 0
    }
    
    // Parse values as float
    startReading = parseFloat(startReading.toString().replace(',', '.'))
    endReading = parseFloat(endReading.toString().replace(',', '.'))
    
    // Get coefficient for this meter
    const coefficient = getCoefficient(meterId)
    
    // Check if there's a replacement in this shift
    if (replacement) {
      const replacementDate = new Date(replacement.replacement_dt)
      const replacementHour = replacementDate.getHours()
      
      // Determine which shift the replacement occurred in
      let replacementShift = null
      for (const shift of shifts) {
        const startHour = parseInt(shift.start_time.split(':')[0])
        const endHour = parseInt(shift.end_time.split(':')[0])
        
        // Handle shifts that span midnight
        if (startHour < endHour) {
          if (replacementHour >= startHour && replacementHour < endHour) {
            replacementShift = shift.id
            break
          }
        } else {
          if (replacementHour >= startHour || replacementHour < endHour) {
            replacementShift = shift.id
            break
          }
        }
      }
      
      // If the replacement occurred in this shift, calculate corrected consumption
      if (replacementShift === shiftId) {
        const oldReading = parseFloat(replacement.old_reading.toString().replace(',', '.'))
        const newReading = parseFloat(replacement.new_reading.toString().replace(',', '.'))
        const oldCoefficient = parseFloat(replacement.old_coefficient.toString().replace(',', '.'))
        const newCoefficient = parseFloat(replacement.new_coefficient.toString().replace(',', '.'))
        const downtimeMinutes = parseInt(replacement.downtime_minutes)
        const powerAtReplacement = parseFloat((replacement.power_at_replacement || 0).toString().replace(',', '.'))
        
        // Calculate parts
        const part1 = (endReading - newReading) * newCoefficient / 1000
        const part2 = (downtimeMinutes / 60) * powerAtReplacement
        const part3 = (oldReading - startReading) * oldCoefficient / 1000
        
        return part1 + part2 + part3
      }
    }
    
    // Normal calculation: (End - Start) * K / 1000
    return (endReading - startReading) * coefficient / 1000
  }
  
  // Calculate total consumption for a meter across all shifts
  const calculateTotalConsumption = (meterId) => {
    let total = 0
    
    // Sum up consumption for all three shifts
    total += calculateConsumption(meterId, 1) // Shift 1
    total += calculateConsumption(meterId, 2) // Shift 2
    total += calculateConsumption(meterId, 3) // Shift 3
    
    return total
  }
  
  // Handle row click to select a meter
  const handleRowClick = (meter) => {
    if (selectedMeterId === meter.id) {
      setSelectedMeterId(null);
      setSelectedMeter(null);
    } else {
      setSelectedMeterId(meter.id);
      setSelectedMeter(meter);
    }
  }

  // Open replacement modal for a meter
  const openReplacementModal = () => {
    if (!selectedMeter || !selectedMeterId) return;
    
    // Get current shift based on time of day
    const now = new Date();
    const hour = now.getHours();
    let currentShiftId = 1;
    
    if (hour >= 0 && hour < 8) {
      currentShiftId = 1;
    } else if (hour >= 8 && hour < 16) {
      currentShiftId = 2;
    } else {
      currentShiftId = 3;
    }
    
    // Default replacement time based on current shift
    let defaultTime = '07:00';
    if (currentShiftId === 2) {
      defaultTime = '15:00';
    } else if (currentShiftId === 3) {
      defaultTime = '23:00';
    }
    
    // Get meter data
    const coefficient = selectedMeter.coefficient || 1.0;
    const serial = selectedMeter.serial || '';
    
    // Check for existing replacement
    const hasExistingReplacement = replacements[selectedMeterId] && 
                                  new Date(replacements[selectedMeterId].replacement_dt).toDateString() === selectedDate.toDateString();
    
    // Initialize replacement data
    let initialReplacementData = {
      meter_id: selectedMeter.id,
      date: format(selectedDate, 'yyyy-MM-dd'),
      replacement_time: defaultTime,
      old_serial: serial,
      old_coefficient: coefficient.toString(),
      old_reading: '',
      new_serial: '',
      new_coefficient: '',
      new_reading: '',
      downtime_minutes: '0',
      power_at_replacement: '0',
      has_existing_replacement: hasExistingReplacement,
      replacement_id: hasExistingReplacement ? replacements[selectedMeterId].id : null
    };
    
    // If there's an existing replacement, load its data
    if (hasExistingReplacement) {
      const replacement = replacements[selectedMeterId];
      const replacementDate = new Date(replacement.replacement_dt);
      const hours = replacementDate.getHours().toString().padStart(2, '0');
      const minutes = replacementDate.getMinutes().toString().padStart(2, '0');
      
      initialReplacementData = {
        ...initialReplacementData,
        replacement_time: `${hours}:${minutes}`,
        old_serial: replacement.old_serial || serial,
        old_coefficient: replacement.old_coefficient.toString(),
        old_reading: replacement.old_reading.toString(),
        new_serial: replacement.new_serial || '',
        new_coefficient: replacement.new_coefficient.toString(),
        new_reading: replacement.new_reading.toString(),
        downtime_minutes: (replacement.downtime_minutes || '0').toString(),
        power_at_replacement: (replacement.power_at_replacement || '0').toString()
      };
    }
    
    setReplacementData(initialReplacementData);
    setShowReplacementModal(true);
  }
  
  // Handle replacement data change
  const handleReplacementChange = (field, value) => {
    // For numeric fields, ensure we use dot as decimal separator
    if (['old_coefficient', 'old_reading', 'new_coefficient', 'new_reading', 'power_at_replacement', 'downtime_minutes'].includes(field)) {
      value = value !== undefined && value !== null ? value.toString().replace(',', '.') : '';
    }
    
    setReplacementData(prev => ({
      ...prev,
      [field]: value
    }))
  }
  
  // Helper function to determine shift based on hour
  const getShiftForHour = (hour) => {
    if (hour >= 0 && hour < 8) return 1;
    if (hour >= 8 && hour < 16) return 2;
    return 3;
  }
  
  // Validate replacement data
  const validateReplacementData = () => {
    const { 
      old_serial,
      old_reading, 
      new_serial,
      new_reading, 
      new_coefficient,
      replacement_time,
      replacement_id
    } = replacementData;
    
    // Required fields
    if (!old_serial || !old_reading || !new_reading || !new_coefficient) {
      setError('Пожалуйста, заполните все обязательные поля');
      return false;
    }
    
    // Determine shift based on replacement time
    const timeHour = parseInt(replacement_time.split(':')[0], 10);
    const currentShiftId = getShiftForHour(timeHour);
    
    // Get readings for the selected meter and shift
    const meterReadings = readings[selectedMeterId] || {};
    const shiftReadings = meterReadings[currentShiftId] || {};
    
    const readingStart = parseFloat((shiftReadings.reading_start || 0).toString().replace(',', '.'));
    const readingEnd = parseFloat((shiftReadings.reading_end || 0).toString().replace(',', '.'));
    const oldReadingValue = parseFloat(old_reading.toString().replace(',', '.'));
    const newReadingValue = parseFloat(new_reading.toString().replace(',', '.'));
    
    // Check if this meter already has a replacement for this date and shift
    // Skip this check when updating an existing replacement
    if (!replacement_id) {
      const hasExistingReplacement = replacements[selectedMeterId] && 
                                  new Date(replacements[selectedMeterId].replacement_dt).toDateString() === selectedDate.toDateString() &&
                                  getShiftForHour(new Date(replacements[selectedMeterId].replacement_dt).getHours()) === currentShiftId;
      
      if (hasExistingReplacement) {
        setError('Для этого счетчика уже зарегистрирована замена в эту смену');
        return false;
      }
    }
    
    // Validate old reading is within range
    if (oldReadingValue < readingStart) {
      setError(`Показание старого счетчика должно быть больше ${formatNumber(readingStart)}`);
      return false;
    }
    
    // Validate new reading is non-negative
    if (newReadingValue < 0) {
      setError('Показание нового счетчика не может быть отрицательным');
      return false;
    }
    
    return true;
  }
  
  // Save replacement data
  const saveReplacement = async () => {
    try {
      setLoading(true);
      setError(null);
      
      // Format the data for the API
      const formattedData = {
        meter_id: parseInt(replacementData.meter_id),
        date: replacementData.date,
        replacement_time: replacementData.replacement_time,
        old_serial: selectedMeter.serial,
        old_coefficient: parseFloat(replacementData.old_coefficient.toString().replace(',', '.')),
        old_reading: parseFloat(replacementData.old_reading.toString().replace(',', '.')),
        new_coefficient: parseFloat(replacementData.new_coefficient.toString().replace(',', '.')),
        new_reading: parseFloat(replacementData.new_reading.toString().replace(',', '.')),
        downtime_minutes: parseInt(replacementData.downtime_minutes || 0),
        power_at_replacement: parseFloat((replacementData.power_at_replacement || 0).toString().replace(',', '.'))
      };
      
      // Add new_serial only if it's not empty
      if (replacementData.new_serial) {
        formattedData.new_serial = replacementData.new_serial;
      }
      
      // If there's an existing replacement, update it instead of creating a new one
      if (replacementData.replacement_id) {
        await energyService.updateMeterReplacement(replacementData.replacement_id, formattedData);
        setSuccess('Замена счетчика успешно обновлена');
      } else {
        await energyService.createMeterReplacement(formattedData);
        setSuccess('Замена счетчика успешно сохранена');
      }
      
      setShowReplacementModal(false);
      await loadMeterReadings();
    } catch (err) {
      setError('Ошибка при сохранении замены счетчика: ' + (err.message || err));
      console.error(err);
    } finally {
      setLoading(false);
    }
  }
  
  // Delete meter replacement
  const deleteReplacement = async () => {
    if (!replacementData.replacement_id) {
      setError('Не удалось найти ID замены счетчика');
      return;
    }
    
    try {
      setLoading(true);
      setError(null);
      
      await energyService.deleteMeterReplacement(replacementData.replacement_id);
      
      setSuccess('Замена счетчика успешно удалена');
      setShowReplacementModal(false);
      await loadMeterReadings();
    } catch (err) {
      setError('Ошибка при удалении замены счетчика: ' + (err.message || err));
      console.error(err);
    } finally {
      setLoading(false);
    }
  }
  
  // Save all readings
  const saveReadings = async () => {
    try {
      setLoading(true)
      setError(null)
      
      // Format readings for the API
      const formattedReadings = []
      
      for (const meterId in readings) {
        for (const shiftId in readings[meterId]) {
          const reading = readings[meterId][shiftId]
          const meter = meters.find(m => m.id === parseInt(meterId))
          
          // For shift 1, always use prev_reading as reading_start
          const readingStart = shiftId === '1' 
            ? (meter?.prev_reading || 0) 
            : (reading.reading_start !== undefined 
                ? reading.reading_start 
                : readings[meterId][parseInt(shiftId) - 1]?.reading_end || 0)
          
          // Рассчитываем consumption для этой записи
          const consumption = calculateConsumption(parseInt(meterId), parseInt(shiftId))
          
          formattedReadings.push({
            meter_id: parseInt(meterId),
            shift_id: parseInt(shiftId),
            reading_start: readingStart,
            reading_end: reading.reading_end || 0,
            consumption: consumption // Добавляем рассчитанное значение consumption
          })
        }
      }
      
      const formattedDate = format(selectedDate, 'yyyy-MM-dd')
      await energyService.saveMeterReadings(formattedDate, formattedReadings)
      
      // Check if we need to update the next day's readings (if any shift 3 readings were changed)
      const hasShift3Changes = Object.keys(readings).some(meterId => readings[meterId][3] !== undefined)
      
      if (hasShift3Changes) {
        await updateNextDayReadings()
      }
      
      setSuccess('Readings saved successfully')
      setIsDirty(false)
      
      // Reload data to get the updated readings
      await loadMeterReadings()
    } catch (err) {
      setError('Failed to save readings')
      console.error(err)
    } finally {
      setLoading(false)
    }
  }
  
  // Update next day's readings based on current day's shift 3 readings
  const updateNextDayReadings = async () => {
    try {
      // Calculate next day's date
      const nextDate = new Date(selectedDate)
      nextDate.setDate(nextDate.getDate() + 1)
      const formattedNextDate = format(nextDate, 'yyyy-MM-dd')
      
      // Get next day's readings
      const nextDayData = await energyService.getMeterReadings(formattedNextDate, selectedMetricId)
      
      // If there are no readings for the next day, no need to update
      if (!nextDayData.readings || Object.keys(nextDayData.readings).length === 0) {
        return
      }
      
      // Format readings for the API
      const formattedReadings = []
      
      // For each meter that has shift 3 readings in the current day
      for (const meterId in readings) {
        // Skip if no shift 3 reading for this meter
        if (!readings[meterId][3] || !readings[meterId][3].reading_end) {
          continue
        }
        
        // Get the shift 3 reading_end from the current day
        const shift3ReadingEnd = readings[meterId][3].reading_end
        
        // Check if this meter has shift 1 reading in the next day
        if (nextDayData.readings[meterId] && nextDayData.readings[meterId][1]) {
          const nextDayReadingEnd = nextDayData.readings[meterId][1].reading_end || 0
          const meter = meters.find(m => m.id === parseInt(meterId))
          const coefficient = meter?.coefficient || 1.0
          
          // Рассчитываем consumption для следующего дня
          const consumption = (nextDayReadingEnd - shift3ReadingEnd) * coefficient / 1000
          
          // Update the shift 1 reading_start for the next day
          formattedReadings.push({
            meter_id: parseInt(meterId),
            shift_id: 1,
            reading_start: shift3ReadingEnd,
            reading_end: nextDayReadingEnd,
            consumption: consumption // Добавляем рассчитанное значение consumption
          })
        }
      }
      
      // If there are readings to update, save them
      if (formattedReadings.length > 0) {
        await energyService.saveMeterReadings(formattedNextDate, formattedReadings)
      }
    } catch (err) {
      console.error('Error updating next day readings:', err)
      // Don't throw the error - this is a background operation
    }
  }
  
  return (
    <CCard>
      <CCardHeader>
        <h4>Счётчики электроэнергии</h4>
      </CCardHeader>
      <CCardBody>
        {error && (
          <CAlert color="danger" dismissible onClose={() => setError(null)}>
            {error}
          </CAlert>
        )}
        
        {success && (
          <CAlert color="success" dismissible onClose={() => setSuccess(null)}>
            {success}
          </CAlert>
        )}
        
        <CForm>
          <CRow className="mb-3">
            <CCol md={6}>
              <CFormSelect
                id="metric-select"
                label="Выберите метрику"
                value={selectedMetricId}
                onChange={handleMetricChange}
                disabled={loading}
              >
                <option value="">Выберите метрику...</option>
                {Array.isArray(metrics) && metrics.map(metric => (
                  <option key={metric.id} value={metric.id}>
                    {metric.name}
                  </option>
                ))}
              </CFormSelect>
            </CCol>
            
            <CCol md={6}>
              <div className="mb-3">
                <label className="form-label d-block">Выберите дату</label>
                <DatePicker
                  selected={selectedDate}
                  onChange={handleDateChange}
                  dateFormat="dd.MM.yyyy"
                  className="form-control"
                  disabled={loading}
                />
              </div>
            </CCol>
          </CRow>
          
          {loading ? (
            <div className="text-center my-5">
              <CSpinner color="primary" />
            </div>
          ) : (
            <>
              {meters.length > 0 && shifts.length > 0 ? (
                <>
                  <CTable bordered responsive>
                    <CTableHead>
                      <CTableRow>
                        <CTableHeaderCell>Наименование</CTableHeaderCell>
                        <CTableHeaderCell>Коэффициент</CTableHeaderCell>
                      </CTableRow>
                    </CTableHead>
                    <CTableBody>
                      {meters.map(meter => {
                        const hasReplacement = replacements[meter.id] !== undefined &&
                          new Date(replacements[meter.id].replacement_dt).toDateString() === selectedDate.toDateString();
                        
                        return (
                          <CTableRow 
                            key={meter.id} 
                            className={`${hasReplacement ? 'bg-warning bg-opacity-50' : ''} ${selectedMeterId === meter.id ? 'bg-info bg-opacity-10' : ''}`}
                            onClick={() => handleRowClick(meter)}
                            style={{ cursor: 'pointer' }}
                          >
                            <CTableDataCell>{meter.name}</CTableDataCell>
                            <CTableDataCell>
                              {formatNumber(meter.coefficient || 1.0)}
                            </CTableDataCell>
                          </CTableRow>
                        )
                      })}
                    </CTableBody>
                  </CTable>
                  
                  <div className="d-flex justify-content-between mt-3">
                    <CButton 
                      color="info" 
                      onClick={openReplacementModal}
                      disabled={!selectedMeterId}
                    >
                      Смена счётчика
                    </CButton>
                  </div>
                </>
              ) : (
                <CAlert color="info">
                  Нет данных для отображения. Выберите метрику и дату.
                </CAlert>
              )}
            </>
          )}
        </CForm>
        
        {/* Meter Replacement Modal */}
        <CModal
          visible={showReplacementModal}
          onClose={() => setShowReplacementModal(false)}
          backdrop="static"
        >
          <CModalHeader closeButton>
            <CModalTitle>
              {replacementData.has_existing_replacement ? 'Информация о замене счётчика' : 'Смена счётчика'}
            </CModalTitle>
          </CModalHeader>
          <CModalBody>
            {selectedMeter && (
              <CForm>
                <CRow className="mb-3">
                  <CCol md={12}>
                    <CFormLabel>Время замены</CFormLabel>
                    <CFormInput
                      type="time"
                      value={replacementData.replacement_time}
                      onChange={(e) => handleReplacementChange('replacement_time', e.target.value)}
                    />
                  </CCol>
                </CRow>
                
                <div className="border p-3 mb-3">
                  <h5>Старый счётчик</h5>
                  <CRow className="mb-3">
                    <CCol md={6}>
                      <CFormLabel>№</CFormLabel>
                      <CFormInput
                        value={selectedMeter.serial}
                        disabled
                      />
                    </CCol>
                    <CCol md={6}>
                      <CFormLabel>Коэффициент</CFormLabel>
                      <CFormInput
                        type="number"
                        step="0.001"
                        inputMode="decimal"
                        pattern="[0-9]*[.]?[0-9]*"
                        value={replacementData.old_coefficient}
                        onChange={(e) => handleReplacementChange('old_coefficient', e.target.value)}
                        disabled
                      />
                    </CCol>
                  </CRow>
                  <CRow className="mb-3">
                    <CCol md={6}>
                      <CFormLabel>Показание</CFormLabel>
                      <CFormInput
                        type="number"
                        step="0.001"
                        inputMode="decimal"
                        pattern="[0-9]*[.]?[0-9]*"
                        value={replacementData.old_reading}
                        onChange={(e) => handleReplacementChange('old_reading', e.target.value)}
                        required
                      />
                    </CCol>
                  </CRow>
                </div>
                
                <div className="border p-3 mb-3">
                  <h5>Новый счётчик</h5>
                  <CRow className="mb-3">
                    <CCol md={6}>
                      <CFormLabel>№</CFormLabel>
                      <CFormInput
                        value={replacementData.new_serial}
                        onChange={(e) => handleReplacementChange('new_serial', e.target.value)}
                        placeholder="Введите номер"
                      />
                    </CCol>
                    <CCol md={6}>
                      <CFormLabel>Коэффициент</CFormLabel>
                      <CFormInput
                        type="number"
                        step="0.001"
                        inputMode="decimal"
                        pattern="[0-9]*[.]?[0-9]*"
                        value={replacementData.new_coefficient}
                        onChange={(e) => handleReplacementChange('new_coefficient', e.target.value)}
                        required
                        placeholder="Введите коэффициент"
                      />
                    </CCol>
                  </CRow>
                  <CRow className="mb-3">
                    <CCol md={6}>
                      <CFormLabel>Показание</CFormLabel>
                      <CFormInput
                        type="number"
                        step="0.001"
                        inputMode="decimal"
                        pattern="[0-9]*[.]?[0-9]*"
                        value={replacementData.new_reading}
                        onChange={(e) => handleReplacementChange('new_reading', e.target.value)}
                        required
                        placeholder="Введите показание"
                      />
                    </CCol>
                  </CRow>
                </div>
                
                <CRow className="mb-3">
                  <CCol md={6}>
                    <CFormLabel>Время без учёта (минут)</CFormLabel>
                    <CFormInput
                      type="number"
                      value={replacementData.downtime_minutes}
                      onChange={(e) => handleReplacementChange('downtime_minutes', e.target.value)}
                      required
                    />
                  </CCol>
                  <CCol md={6}>
                    <CFormLabel>При мощности (МВт)</CFormLabel>
                    <CFormInput
                      type="number"
                      step="0.1"
                      inputMode="decimal"
                      pattern="[0-9]*[.]?[0-9]*"
                      value={replacementData.power_at_replacement}
                      onChange={(e) => handleReplacementChange('power_at_replacement', e.target.value)}
                    />
                  </CCol>
                </CRow>
              </CForm>
            )}
          </CModalBody>
          <CModalFooter>
            <CButton color="secondary" onClick={() => setShowReplacementModal(false)}>
              Отмена
            </CButton>
            {replacementData.has_existing_replacement ? (
              <>
                <CButton 
                  color="danger" 
                  onClick={deleteReplacement}
                >
                  Удалить замену
                </CButton>
                <CButton 
                  color="primary" 
                  onClick={saveReplacement}
                >
                  Сохранить изменения
                </CButton>
              </>
            ) : (
              <CButton 
                color="primary" 
                onClick={saveReplacement}
              >
                Сохранить
              </CButton>
            )}
          </CModalFooter>
        </CModal>
      </CCardBody>
    </CCard>
  )
}

export default EnergyMeters 