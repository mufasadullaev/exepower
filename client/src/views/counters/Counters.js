import React, { useState, useEffect } from 'react'
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
  CFormInput,
  CFormSelect,
  CModal,
  CModalHeader,
  CModalTitle,
  CModalBody,
  CModalFooter,
  CForm,
  CFormLabel,
  CFormText,
  CAlert,
} from '@coreui/react'
import DatePicker from 'react-datepicker'
import 'react-datepicker/dist/react-datepicker.css'
import { counterService } from '../../services/counterService'
import { format } from 'date-fns'
import authService from '../../services/authService'
import CommonMeterUsageModal from '../../components/CommonMeterUsageModal'

const Counters = () => {
  const [selectedDate, setSelectedDate] = useState(new Date())
  const [meterTypes, setMeterTypes] = useState([])
  const [selectedType, setSelectedType] = useState(null)
  const [meters, setMeters] = useState([])
  const [readings, setReadings] = useState({})
  const [meterReplacements, setMeterReplacements] = useState({})
  const [showReplacementModal, setShowReplacementModal] = useState(false)
  const [showConfirmModal, setShowConfirmModal] = useState(false)
  const [selectedMeter, setSelectedMeter] = useState(null)
  const [replacementData, setReplacementData] = useState({
    id: null,
    old_serial: '',
    old_coefficient: '',
    old_scale: '',
    old_reading: '',
    new_serial: '',
    new_coefficient: '',
    new_scale: '',
    new_reading: '',
    replacement_time: '',
    downtime_min: 0,
    power_mw: 0,
    comment: ''
  })
  const [error, setError] = useState(null)
  const [success, setSuccess] = useState(null)
  const [modalError, setModalError] = useState(null)
  const [syncing, setSyncing] = useState(false)

  // Резервные счётчики (полноценный режим)
  const [reserveMeters, setReserveMeters] = useState([])
  const [primaryMeters, setPrimaryMeters] = useState([])
  const [activeReserveAssignments, setActiveReserveAssignments] = useState([])

  const [showReserveStartModal, setShowReserveStartModal] = useState(false)
  const [reserveStartForm, setReserveStartForm] = useState({
    reserve_meter_id: null,
    primary_meter_id: null,
    start_time: new Date(),
    start_reading: '',
    comment: ''
  })

  const [showReserveEndModal, setShowReserveEndModal] = useState(false)
  const [reserveEndForm, setReserveEndForm] = useState({
    assignment_id: null,
    end_time: new Date(),
    end_reading: '',
    comment: ''
  })

  // Общие счетчики
  const [showCommonMeterModal, setShowCommonMeterModal] = useState(false)
  const [selectedCommonMeter, setSelectedCommonMeter] = useState(null)

  // ID общих счетчиков
  const commonMeterIds = [48, 49, 50, 51, 52, 53]

  const isCommonMeter = (meterId) => {
    return commonMeterIds.includes(meterId)
  }

  const handleCommonMeterClick = (meter) => {
    setSelectedCommonMeter(meter)
    setShowCommonMeterModal(true)
  }

  const handleCommonMeterSave = () => {
    // Перезагружаем данные после сохранения
    loadReadings(selectedDate)
  }

  useEffect(() => {
    loadMeterTypes()
  }, [])

  useEffect(() => {
    if (meterTypes.length > 0 && !selectedType) {
      setSelectedType(meterTypes[0].id)
    }
  }, [meterTypes])

  useEffect(() => {
    if (selectedType) {
      loadMeters(selectedType)
      // Загружаем резервы и основные (тип 2) и активные назначения
      ;(async () => {
        try {
          const allType2 = await counterService.getMeters(2)
          setReserveMeters(allType2.filter(m => m.name?.includes('ТСР-3-6')))
          setPrimaryMeters(allType2.filter(m => m.name?.includes('ВСР')))
          const active = await counterService.getReserveAssignments(null, true)
          setActiveReserveAssignments(active || [])
        } catch (e) {
          // ignore silently
        }
      })()
    }
  }, [selectedType])

  useEffect(() => {
    if (meters.length > 0) {
      loadReadings(selectedDate)
      checkMeterReplacements(selectedDate)
    }
  }, [meters, selectedDate])

  const loadMeterTypes = async () => {
    try {
      const types = await counterService.getMeterTypes()
      setMeterTypes(types)
    } catch (error) {
      console.error('Error loading meter types:', error)
    }
  }

  const loadMeters = async (typeId) => {
    try {
      const meters = await counterService.getMeters(typeId)
      setMeters(meters)
    } catch (error) {
      console.error('Error loading meters:', error)
    }
  }

  const loadReadings = async (date) => {
    try {
      const readings = await counterService.getReadings(date)
      
      // Если нет показаний на текущую дату, пробуем загрузить r24 предыдущего дня
      if (Object.keys(readings).length === 0) {
        const prevDate = new Date(date)
        prevDate.setDate(prevDate.getDate() - 1)
        const prevReadings = await counterService.getReadings(prevDate)
        
        // Для каждого счетчика устанавливаем r0 из r24 предыдущего дня
        const newReadings = {}
        meters.forEach(meter => {
          const prevReading = prevReadings[meter.id]
          if (prevReading && prevReading.r24) {
            newReadings[meter.id] = {
              r0: prevReading.r24,
              r8: null,
              r16: null,
              r24: null
            }
          }
        })
        setReadings(newReadings)
      } else {
        setReadings(readings)
      }
    } catch (error) {
      console.error('Error loading readings:', error)
    }
  }

  const checkMeterReplacements = async (date) => {
    try {
      const replacements = {}
      
      // Проверяем каждый счетчик на наличие замены на выбранную дату
      for (const meter of meters) {
        const replacement = await counterService.getReplacement(meter.id, date)
        if (replacement) {
          replacements[meter.id] = true
        }
      }
      
      setMeterReplacements(replacements)
    } catch (error) {
      console.error('Error checking meter replacements:', error)
    }
  }

  const handleTypeChange = (e) => {
    setSelectedType(e.target.value)
  }

  const handleReadingChange = (meterId, field, value) => {
    // Корректно обрабатываем пустые значения и NaN
    let processedValue = value
    if (value === '' || value === null || value === undefined) {
      processedValue = null
    } else if (typeof value === 'string' && value.trim() === '') {
      processedValue = null
    } else if (typeof value === 'number' && isNaN(value)) {
      processedValue = null
    }
    
    setReadings(prev => ({
      ...prev,
      [meterId]: {
        ...prev[meterId],
        [field]: processedValue
      }
    }))
  }

  const calculateShift = (start, end, meter) => {
    if (!start || !end || !meter) return null
    
    const diff = end - start
    return (diff * meter.coefficient_k) / 1000
  }

  const handleSaveReadings = async () => {
    try {
      setError(null)
      setSuccess(null)

      const user = authService.getUser()
      if (!user) {
        setError('Пользователь не авторизован')
        return
      }

      await counterService.saveReadings(selectedDate, readings)
      loadReadings(selectedDate)
      setSuccess('Показания успешно сохранены')
    } catch (error) {
      console.error('Error saving readings:', error)
      const errorMessage = error.response?.data?.error?.message || 'Произошла ошибка при сохранении показаний'
      setError(errorMessage)
    }
  }

  const handleCancelReplacement = async () => {
    try {
      if (!selectedMeter || !replacementData.id) {
        return;
      }

      setModalError(null);
      setShowConfirmModal(true);
    } catch (error) {
      console.error('Error cancelling replacement:', error);
      const errorMessage = error.response?.data?.error?.message || 'Произошла ошибка при отмене замены счетчика';
      setModalError(errorMessage);
    }
  }

  const confirmCancelReplacement = async () => {
    try {
      const user = authService.getUser()
      if (!user) {
        setModalError('Пользователь не авторизован')
        setShowConfirmModal(false)
        return
      }

      await counterService.cancelReplacement(selectedMeter.id, format(selectedDate, 'yyyy-MM-dd'))
      setShowConfirmModal(false)
      setShowReplacementModal(false)
      setSuccess('Замена счетчика отменена')
      loadMeters(selectedType)
      handleSaveReadings()
      checkMeterReplacements(selectedDate)
    } catch (error) {
      console.error('Error cancelling replacement:', error)
      const errorMessage = error.response?.data?.error?.message || 'Произошла ошибка при отмене замены счетчика'
      setModalError(errorMessage)
      setShowConfirmModal(false)
    }
  }

  const handleReplacementClick = async (meter) => {
    setSelectedMeter(meter)
    
    try {
      // Проверяем наличие замены на выбранную дату
      const replacement = await counterService.getReplacement(meter.id, selectedDate)
      
      if (replacement) {
        // Если есть данные о замене на выбранную дату, используем их
        setReplacementData({
          id: replacement.id,
          old_serial: replacement.old_serial,
          old_coefficient: replacement.old_coefficient,
          old_scale: replacement.old_scale,
          old_reading: replacement.old_reading,
          new_serial: replacement.new_serial,
          new_coefficient: replacement.new_coefficient,
          new_scale: replacement.new_scale,
          new_reading: replacement.new_reading,
          replacement_time: replacement.replacement_time,
          downtime_min: replacement.downtime_min,
          power_mw: replacement.power_mw,
          comment: replacement.comment || ''
        })
      } else {
        // Если данных о замене нет, используем текущие данные счетчика
        setReplacementData({
          id: null,
          old_serial: meter.serial_number,
          old_coefficient: meter.coefficient_k,
          old_scale: meter.scale,
          old_reading: '',
          new_serial: '',
          new_coefficient: meter.coefficient_k,
          new_scale: meter.scale,
          new_reading: '',
          replacement_time: '',
          downtime_min: 0,
          power_mw: 0,
          comment: ''
        })
      }

      setShowReplacementModal(true)
    } catch (error) {
      console.error('Error loading replacement data:', error)
      setError('Ошибка при загрузке данных о замене счетчика')
    }
  }

  const handleReplacementSave = async () => {
    try {
      const requiredFields = [
        'old_reading',
        'new_serial',
        'new_coefficient',
        'new_scale',
        'new_reading',
        'replacement_time'
      ]

      const emptyFields = requiredFields.filter(field => !replacementData[field] && replacementData[field] !== 0)
      if (emptyFields.length > 0) {
        setModalError('Заполните все поля')
        return
      }

      setModalError(null)
      
      const user = authService.getUser()
      if (!user) {
        setModalError('Пользователь не авторизован')
        return
      }
      
      const formattedData = {
        meter_id: selectedMeter.id,
        replacement_date: format(selectedDate, 'yyyy-MM-dd'),
        replacement_time: replacementData.replacement_time,
        old_serial: replacementData.old_serial,
        old_coefficient: parseFloat(replacementData.old_coefficient),
        old_scale: parseFloat(replacementData.old_scale),
        old_reading: parseFloat(replacementData.old_reading),
        new_serial: replacementData.new_serial,
        new_coefficient: parseFloat(replacementData.new_coefficient),
        new_scale: parseFloat(replacementData.new_scale),
        new_reading: parseFloat(replacementData.new_reading),
        downtime_min: parseInt(replacementData.downtime_min) || 0,
        power_mw: parseFloat(replacementData.power_mw) || 0,
        comment: replacementData.comment || ''
      }
      
      if (replacementData.id) {
        // Обновление существующей записи
        await counterService.updateReplacement(replacementData.id, formattedData)
        setSuccess('Данные о замене счетчика успешно обновлены')
      } else {
        // Создание новой записи
        await counterService.saveReplacement(selectedMeter.id, formattedData)
        setSuccess('Замена счетчика успешно сохранена')
      }
      
      setShowReplacementModal(false)
      loadMeters(selectedType)
      handleSaveReadings()
      checkMeterReplacements(selectedDate)
    } catch (error) {
      console.error('Error saving replacement:', error)
      const errorMessage = error.response?.data?.error?.message || 'Произошла ошибка при сохранении замены счетчика'
      setModalError(errorMessage)
    }
  }

  const handleBulkSync = async () => {
    if (!window.confirm('Выполнить массовую синхронизацию данных счетчиков с параметрами ПГУ? Это может занять некоторое время.')) {
      return
    }
    
    setSyncing(true)
    setError(null)
    setSuccess(null)
    
    try {
      const result = await counterService.bulkSyncMeterReadings()
      setSuccess(`Синхронизация завершена. Обработано: ${result.data.synced_count}, ошибок: ${result.data.error_count}`)
    } catch (error) {
      setError('Ошибка при синхронизации: ' + (error.response?.data?.message || error.message))
    } finally {
      setSyncing(false)
    }
  }

  return (
    <CRow>
      <CCol xs={12}>
        <CCard className="mb-4">
          <CCardHeader>
            <strong>Счётчики</strong>
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
            <div className="mb-3">
              <CRow>
                <CCol md={4}>
                  <CFormLabel>Тип счётчика</CFormLabel>
                  <CFormSelect
                    value={selectedType || ''}
                    onChange={handleTypeChange}
                  >
                    {meterTypes.map(type => (
                      <option key={type.id} value={type.id}>
                        {type.name}
                      </option>
                    ))}
                  </CFormSelect>
                </CCol>
                <CCol md={4}>
                  <CFormLabel>Дата</CFormLabel>
                  <DatePicker
                    selected={selectedDate}
                    onChange={setSelectedDate}
                    dateFormat="dd.MM.yyyy"
                    className="form-control"
                  />
                </CCol>
                <CCol md={4} className="d-flex align-items-end">
                  <CButton 
                    color="primary" 
                    onClick={handleBulkSync}
                    disabled={syncing}
                    className="me-2"
                  >
                    {syncing ? 'Синхронизация...' : 'Синхронизация с ПГУ'}
                  </CButton>
                  <CButton 
                    color="success" 
                    onClick={handleSaveReadings}
                  >
                    Сохранить
                  </CButton>
                </CCol>
              </CRow>
            </div>

            <CTable>
              <CTableHead>
                <CTableRow>
                  <CTableHeaderCell>Счётчик</CTableHeaderCell>
                  <CTableHeaderCell>Коэф.</CTableHeaderCell>
                  <CTableHeaderCell>R0</CTableHeaderCell>
                  <CTableHeaderCell>R8</CTableHeaderCell>
                  <CTableHeaderCell>R16</CTableHeaderCell>
                  <CTableHeaderCell>R24</CTableHeaderCell>
                  <CTableHeaderCell>С1</CTableHeaderCell>
                  <CTableHeaderCell>С2</CTableHeaderCell>
                  <CTableHeaderCell>С3</CTableHeaderCell>
                  <CTableHeaderCell>СИтого</CTableHeaderCell>
                  <CTableHeaderCell>Действия</CTableHeaderCell>
                </CTableRow>
              </CTableHead>
              <CTableBody>
                {meters.map(meter => {
                  const reading = readings[meter.id] || {}
                  const shift1 = parseFloat(reading.shift1) || 0
                  const shift2 = parseFloat(reading.shift2) || 0
                  const shift3 = parseFloat(reading.shift3) || 0
                  const total = parseFloat(reading.total) || 0
                  // effective_shift теперь показывает только резервную добавку
                  const reserve1 = reading.effective_shift1 != null ? parseFloat(reading.effective_shift1) : 0
                  const reserve2 = reading.effective_shift2 != null ? parseFloat(reading.effective_shift2) : 0
                  const reserve3 = reading.effective_shift3 != null ? parseFloat(reading.effective_shift3) : 0
                  const reserveTotal = reading.effective_total != null ? parseFloat(reading.effective_total) : 0

                  return (
                    <CTableRow key={meter.id}>
                      <CTableDataCell>
                        {meter.name}
                      </CTableDataCell>
                      <CTableDataCell>
                        {meter.coefficient_k}
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="number"
                          value={reading.r0 || ''}
                          onChange={e => handleReadingChange(meter.id, 'r0', e.target.value === '' ? null : parseFloat(e.target.value))}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="number"
                          value={reading.r8 || ''}
                          onChange={e => handleReadingChange(meter.id, 'r8', e.target.value === '' ? null : parseFloat(e.target.value))}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="number"
                          value={reading.r16 || ''}
                          onChange={e => handleReadingChange(meter.id, 'r16', e.target.value === '' ? null : parseFloat(e.target.value))}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="number"
                          value={reading.r24 || ''}
                          onChange={e => handleReadingChange(meter.id, 'r24', e.target.value === '' ? null : parseFloat(e.target.value))}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        <div>{shift1.toFixed(3)}</div>
                        {selectedType == 2 && reserve1 > 0 && (
                          <div style={{fontSize: '0.8em', color: '#0066cc', fontWeight: 'bold'}}>
                            +{reserve1.toFixed(3)}
                          </div>
                        )}
                      </CTableDataCell>
                      <CTableDataCell>
                        <div>{shift2.toFixed(3)}</div>
                        {selectedType == 2 && reserve2 > 0 && (
                          <div style={{fontSize: '0.8em', color: '#0066cc', fontWeight: 'bold'}}>
                            +{reserve2.toFixed(3)}
                          </div>
                        )}
                      </CTableDataCell>
                      <CTableDataCell>
                        <div>{shift3.toFixed(3)}</div>
                        {selectedType == 2 && reserve3 > 0 && (
                          <div style={{fontSize: '0.8em', color: '#0066cc', fontWeight: 'bold'}}>
                            +{reserve3.toFixed(3)}
                          </div>
                        )}
                      </CTableDataCell>
                      <CTableDataCell>
                        <div>{total.toFixed(3)}</div>
                        {selectedType == 2 && reserveTotal > 0 && (
                          <div style={{fontSize: '0.8em', color: '#0066cc', fontWeight: 'bold'}}>
                            +{reserveTotal.toFixed(3)}
                          </div>
                        )}
                      </CTableDataCell>
                      <CTableDataCell>
                        <CButton
                          color={meterReplacements[meter.id] ? "warning" : "primary"}
                          size="sm"
                          onClick={() => handleReplacementClick(meter)}
                          className="me-2"
                        >
                          Смена
                        </CButton>
                        {isCommonMeter(meter.id) && (
                          <CButton
                            color="info"
                            size="sm"
                            onClick={() => handleCommonMeterClick(meter)}
                          >
                            Блоки
                          </CButton>
                        )}
                      </CTableDataCell>
                    </CTableRow>
                  )
                })}
              </CTableBody>
            </CTable>

            {/* Резервные счетчики (6а/6б) - показываем только для типа "Расход на собственные нужды" */}
            {selectedType == 2 && (
              <>
                <hr className="my-4" />
                <h5>Резервные счетчики (6а / 6б)</h5>

                {/* Активные назначения */}
                <div className="mb-3">
                  <strong>Активные назначения:</strong>
                  {activeReserveAssignments.length ? (
                    <CTable className="mt-2" small>
                      <CTableHead>
                        <CTableRow>
                          <CTableHeaderCell>ID</CTableHeaderCell>
                          <CTableHeaderCell>Резерв</CTableHeaderCell>
                          <CTableHeaderCell>Основной</CTableHeaderCell>
                          <CTableHeaderCell>Старт</CTableHeaderCell>
                          <CTableHeaderCell>Старт. показ.</CTableHeaderCell>
                        </CTableRow>
                      </CTableHead>
                      <CTableBody>
                        {activeReserveAssignments.map(a => (
                          <CTableRow key={a.id}>
                            <CTableDataCell>{a.id}</CTableDataCell>
                            <CTableDataCell>{reserveMeters.find(m => m.id === a.reserve_meter_id)?.name || a.reserve_meter_id}</CTableDataCell>
                            <CTableDataCell>{primaryMeters.find(m => m.id === a.primary_meter_id)?.name || a.primary_meter_id}</CTableDataCell>
                            <CTableDataCell>{a.start_time}</CTableDataCell>
                            <CTableDataCell>{a.start_reading}</CTableDataCell>
                          </CTableRow>
                        ))}
                      </CTableBody>
                    </CTable>
                  ) : (
                    <div className="text-muted">Нет активных назначений</div>
                  )}
                </div>

                <div className="mb-3 d-flex gap-2">
                  <CButton color="primary" onClick={() => {
                    setReserveStartForm(prev => ({
                      reserve_meter_id: reserveMeters[0]?.id || null,
                      primary_meter_id: primaryMeters[0]?.id || null,
                      start_time: new Date(),
                      start_reading: '',
                      comment: ''
                    }))
                    setShowReserveStartModal(true)
                  }}>Начать обслуживание</CButton>

                  <CButton color="success" disabled={!activeReserveAssignments.length} onClick={() => {
                    const first = activeReserveAssignments[0]
                    setReserveEndForm(prev => ({
                      assignment_id: first?.id || null,
                      end_time: new Date(),
                      end_reading: '',
                      comment: ''
                    }))
                    setShowReserveEndModal(true)
                  }}>Завершить обслуживание</CButton>
                </div>
              </>
            )}

          </CCardBody>
        </CCard>
      </CCol>

      <CModal
        visible={showReplacementModal}
        onClose={() => {
          setShowReplacementModal(false)
          setModalError(null)
        }}
        size="lg"
      >
        <CModalHeader>
          <CModalTitle>Замена счётчика</CModalTitle>
        </CModalHeader>
        <CModalBody>
          {modalError && (
            <CAlert color="danger" dismissible onClose={() => setModalError(null)}>
              {modalError}
            </CAlert>
          )}
          <CForm>
            <CRow>
              <CCol md={6}>
                <CFormLabel>Серийный номер старого</CFormLabel>
                <CFormInput
                  type="text"
                  value={replacementData.old_serial}
                  readOnly
                />
              </CCol>
              <CCol md={6}>
                <CFormLabel>Коэффициент старого</CFormLabel>
                <CFormInput
                  type="number"
                  value={replacementData.old_coefficient}
                  readOnly
                />
              </CCol>
            </CRow>
            <CRow className="mt-3">
              <CCol md={6}>
                <CFormLabel>Шкала старого</CFormLabel>
                <CFormInput
                  type="number"
                  value={replacementData.old_scale}
                  onChange={e => setReplacementData(prev => ({ ...prev, old_scale: parseFloat(e.target.value) }))}
                />
              </CCol>
              <CCol md={6}>
                <CFormLabel>Показание старого</CFormLabel>
                <CFormInput
                  type="number"
                  value={replacementData.old_reading}
                  onChange={e => setReplacementData(prev => ({ ...prev, old_reading: parseFloat(e.target.value) }))}
                />
              </CCol>
            </CRow>
            <CRow className="mt-3">
              <CCol md={6}>
                <CFormLabel>Серийный номер нового</CFormLabel>
                <CFormInput
                  type="text"
                  value={replacementData.new_serial}
                  onChange={e => setReplacementData(prev => ({ ...prev, new_serial: e.target.value }))}
                />
              </CCol>
              <CCol md={6}>
                <CFormLabel>Коэффициент нового</CFormLabel>
                <CFormInput
                  type="number"
                  value={replacementData.new_coefficient}
                  onChange={e => setReplacementData(prev => ({ ...prev, new_coefficient: parseFloat(e.target.value) }))}
                />
              </CCol>
            </CRow>
            <CRow className="mt-3">
              <CCol md={6}>
                <CFormLabel>Шкала нового</CFormLabel>
                <CFormInput
                  type="number"
                  value={replacementData.new_scale}
                  onChange={e => setReplacementData(prev => ({ ...prev, new_scale: parseFloat(e.target.value) }))}
                />
              </CCol>
              <CCol md={6}>
                <CFormLabel>Показание нового</CFormLabel>
                <CFormInput
                  type="number"
                  value={replacementData.new_reading}
                  onChange={e => setReplacementData(prev => ({ ...prev, new_reading: parseFloat(e.target.value) }))}
                />
              </CCol>
            </CRow>
            <CRow className="mt-3">
              <CCol md={4}>
                <CFormLabel>Время замены</CFormLabel>
                <CFormInput
                  type="time"
                  value={replacementData.replacement_time}
                  onChange={e => setReplacementData(prev => ({ ...prev, replacement_time: e.target.value }))}
                />
              </CCol>
              <CCol md={4}>
                <CFormLabel>Время простоя (мин)</CFormLabel>
                <CFormInput
                  type="number"
                  value={replacementData.downtime_min}
                  onChange={e => setReplacementData(prev => ({ ...prev, downtime_min: parseInt(e.target.value) }))}
                />
              </CCol>
              <CCol md={4}>
                <CFormLabel>Мощность (МВт)</CFormLabel>
                <CFormInput
                  type="number"
                  value={replacementData.power_mw}
                  onChange={e => setReplacementData(prev => ({ ...prev, power_mw: parseFloat(e.target.value) }))}
                />
              </CCol>
            </CRow>
            <CRow className="mt-3">
              <CCol>
                <CFormLabel>Комментарий</CFormLabel>
                <CFormInput
                  type="text"
                  value={replacementData.comment}
                  onChange={e => setReplacementData(prev => ({ ...prev, comment: e.target.value }))}
                />
              </CCol>
            </CRow>
          </CForm>
        </CModalBody>
        <CModalFooter>
          <CButton color="secondary" onClick={() => {
            setShowReplacementModal(false)
            setModalError(null)
          }}>
            Отмена
          </CButton>
          {replacementData.id && (
            <CButton color="danger" onClick={handleCancelReplacement}>
              Отменить замену
            </CButton>
          )}
          <CButton color="primary" onClick={handleReplacementSave}>
            Сохранить
          </CButton>
        </CModalFooter>
      </CModal>

      {/* Модальное окно подтверждения удаления */}
      <CModal 
        visible={showConfirmModal} 
        onClose={() => setShowConfirmModal(false)}
        alignment="center"
      >
        <CModalHeader className="bg-danger text-white">
          <CModalTitle>Подтверждение отмены замены</CModalTitle>
        </CModalHeader>
        <CModalBody>
          <p>Вы действительно хотите отменить замену счетчика?</p>
          <p><strong>Внимание:</strong> Это действие нельзя будет отменить.</p>
          <p>Исходные показания и параметры счетчика будут восстановлены.</p>
        </CModalBody>
        <CModalFooter>
          <CButton color="secondary" onClick={() => setShowConfirmModal(false)}>
            Отмена
          </CButton>
          <CButton color="danger" onClick={confirmCancelReplacement}>
            Удалить данные о замене
          </CButton>
        </CModalFooter>
      </CModal>

      {/* Модалка старта резерва */}
      <CModal visible={showReserveStartModal} onClose={() => setShowReserveStartModal(false)}>
        <CModalHeader>
          <CModalTitle>Начать обслуживание резервом</CModalTitle>
        </CModalHeader>
        <CModalBody>
          <CForm>
            <CRow className="mb-3">
              <CCol md={6}>
                <CFormLabel>Резерв</CFormLabel>
                <CFormSelect
                  value={reserveStartForm.reserve_meter_id || ''}
                  onChange={e => setReserveStartForm(prev => ({ ...prev, reserve_meter_id: parseInt(e.target.value) }))}
                >
                  {reserveMeters.map(m => (
                    <option key={m.id} value={m.id}>{m.name}</option>
                  ))}
                </CFormSelect>
              </CCol>
              <CCol md={6}>
                <CFormLabel>Основной (ВСР)</CFormLabel>
                <CFormSelect
                  value={reserveStartForm.primary_meter_id || ''}
                  onChange={e => setReserveStartForm(prev => ({ ...prev, primary_meter_id: parseInt(e.target.value) }))}
                >
                  {primaryMeters.map(m => (
                    <option key={m.id} value={m.id}>{m.name}</option>
                  ))}
                </CFormSelect>
              </CCol>
            </CRow>
            <CRow className="mb-3">
              <CCol md={6}>
                <CFormLabel>Время старта</CFormLabel>
                <DatePicker
                  selected={reserveStartForm.start_time}
                  onChange={d => setReserveStartForm(prev => ({ ...prev, start_time: d }))}
                  showTimeSelect
                  timeFormat="HH:mm"
                  timeIntervals={15}
                  dateFormat="dd.MM.yyyy HH:mm"
                  className="form-control"
                />
              </CCol>
              <CCol md={6}>
                <CFormLabel>Стартовое показание</CFormLabel>
                <CFormInput
                  type="number"
                  value={reserveStartForm.start_reading}
                  onChange={e => setReserveStartForm(prev => ({ ...prev, start_reading: e.target.value }))}
                  placeholder="если пусто — возьмём конец прошлого назначения"
                />
              </CCol>
            </CRow>
            <CRow>
              <CCol>
                <CFormLabel>Комментарий</CFormLabel>
                <CFormInput
                  type="text"
                  value={reserveStartForm.comment}
                  onChange={e => setReserveStartForm(prev => ({ ...prev, comment: e.target.value }))}
                />
              </CCol>
            </CRow>
          </CForm>
        </CModalBody>
        <CModalFooter>
          <CButton color="secondary" onClick={() => setShowReserveStartModal(false)}>Отмена</CButton>
          <CButton color="primary" onClick={async () => {
            try {
              const toStr = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}:00`
              await counterService.startReserveAssignment({
                reserve_meter_id: reserveStartForm.reserve_meter_id,
                primary_meter_id: reserveStartForm.primary_meter_id,
                start_time: toStr(new Date(reserveStartForm.start_time)),
                ...(reserveStartForm.start_reading !== '' ? { start_reading: parseFloat(reserveStartForm.start_reading) } : {}),
                comment: reserveStartForm.comment
              })
              setShowReserveStartModal(false)
              setSuccess('Назначение резерва начато')
              const active = await counterService.getReserveAssignments(null, true)
              setActiveReserveAssignments(active || [])
            } catch (e) {
              setError(e.response?.data?.error?.message || 'Ошибка старта назначения резерва')
            }
          }}>Сохранить</CButton>
        </CModalFooter>
      </CModal>

      {/* Модалка завершения резерва */}
      <CModal visible={showReserveEndModal} onClose={() => setShowReserveEndModal(false)}>
        <CModalHeader>
          <CModalTitle>Завершить обслуживание резервом</CModalTitle>
        </CModalHeader>
        <CModalBody>
          <CForm>
            <CRow className="mb-3">
              <CCol md={12}>
                <CFormLabel>Активное назначение</CFormLabel>
                <CFormSelect
                  value={reserveEndForm.assignment_id || ''}
                  onChange={e => setReserveEndForm(prev => ({ ...prev, assignment_id: parseInt(e.target.value) }))}
                >
                  {activeReserveAssignments.map(a => (
                    <option key={a.id} value={a.id}>
                      #{a.id} — {reserveMeters.find(m => m.id === a.reserve_meter_id)?.name || a.reserve_meter_id} → {primaryMeters.find(m => m.id === a.primary_meter_id)?.name || a.primary_meter_id}
                    </option>
                  ))}
                </CFormSelect>
              </CCol>
            </CRow>
            <CRow className="mb-3">
              <CCol md={6}>
                <CFormLabel>Время окончания</CFormLabel>
                <DatePicker
                  selected={reserveEndForm.end_time}
                  onChange={d => setReserveEndForm(prev => ({ ...prev, end_time: d }))}
                  showTimeSelect
                  timeFormat="HH:mm"
                  timeIntervals={15}
                  dateFormat="dd.MM.yyyy HH:mm"
                  className="form-control"
                />
              </CCol>
              <CCol md={6}>
                <CFormLabel>Конечное показание</CFormLabel>
                <CFormInput
                  type="number"
                  value={reserveEndForm.end_reading}
                  onChange={e => setReserveEndForm(prev => ({ ...prev, end_reading: e.target.value }))}
                />
              </CCol>
            </CRow>
            <CRow>
              <CCol>
                <CFormLabel>Комментарий</CFormLabel>
                <CFormInput
                  type="text"
                  value={reserveEndForm.comment}
                  onChange={e => setReserveEndForm(prev => ({ ...prev, comment: e.target.value }))}
                />
              </CCol>
            </CRow>
          </CForm>
        </CModalBody>
        <CModalFooter>
          <CButton color="secondary" onClick={() => setShowReserveEndModal(false)}>Отмена</CButton>
          <CButton color="success" onClick={async () => {
            try {
              const toStr = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}:00`
              await counterService.endReserveAssignment(reserveEndForm.assignment_id, {
                end_time: toStr(new Date(reserveEndForm.end_time)),
                end_reading: parseFloat(reserveEndForm.end_reading),
                comment: reserveEndForm.comment
              })
              setShowReserveEndModal(false)
              setSuccess('Назначение резерва завершено')
              const active = await counterService.getReserveAssignments(null, true)
              setActiveReserveAssignments(active || [])
              // обновим показания для отображения effective_*
              await loadReadings(selectedDate)
            } catch (e) {
              setError(e.response?.data?.error?.message || 'Ошибка завершения назначения резерва')
            }
          }}>Сохранить</CButton>
        </CModalFooter>
      </CModal>

      {/* Модальное окно для общих счетчиков */}
      <CommonMeterUsageModal
        show={showCommonMeterModal}
        onClose={() => setShowCommonMeterModal(false)}
        meter={selectedCommonMeter}
        date={format(selectedDate, 'yyyy-MM-dd')}
        onSave={handleCommonMeterSave}
      />
    </CRow>
  )
}

export default Counters 