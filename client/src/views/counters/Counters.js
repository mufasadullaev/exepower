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
    setReadings(prev => ({
      ...prev,
      [meterId]: {
        ...prev[meterId],
        [field]: value
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
                  <CFormLabel style={{ display: 'block' }}>Дата</CFormLabel>
                  <DatePicker
                    selected={selectedDate}
                    onChange={date => setSelectedDate(date)}
                    className="form-control"
                    dateFormat="dd.MM.yyyy"
                  />
                </CCol>
              </CRow>
            </div>

            <CTable>
              <CTableHead>
                <CTableRow>
                  <CTableHeaderCell>Счётчик</CTableHeaderCell>
                  <CTableHeaderCell>Коэффициент</CTableHeaderCell>
                  <CTableHeaderCell>R0</CTableHeaderCell>
                  <CTableHeaderCell>R8</CTableHeaderCell>
                  <CTableHeaderCell>R16</CTableHeaderCell>
                  <CTableHeaderCell>R24</CTableHeaderCell>
                  <CTableHeaderCell>Смена 1</CTableHeaderCell>
                  <CTableHeaderCell>Смена 2</CTableHeaderCell>
                  <CTableHeaderCell>Смена 3</CTableHeaderCell>
                  <CTableHeaderCell>Итого</CTableHeaderCell>
                  <CTableHeaderCell>Действия</CTableHeaderCell>
                </CTableRow>
              </CTableHead>
              <CTableBody>
                {meters.map(meter => {
                  const reading = readings[meter.id] || {}
                  // Преобразуем значения смен в числа
                  const shift1 = parseFloat(reading.shift1) || 0
                  const shift2 = parseFloat(reading.shift2) || 0
                  const shift3 = parseFloat(reading.shift3) || 0
                  const total = parseFloat(reading.total) || 0

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
                          onChange={e => handleReadingChange(meter.id, 'r0', parseFloat(e.target.value))}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="number"
                          value={reading.r8 || ''}
                          onChange={e => handleReadingChange(meter.id, 'r8', parseFloat(e.target.value))}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="number"
                          value={reading.r16 || ''}
                          onChange={e => handleReadingChange(meter.id, 'r16', parseFloat(e.target.value))}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="number"
                          value={reading.r24 || ''}
                          onChange={e => handleReadingChange(meter.id, 'r24', parseFloat(e.target.value))}
                        />
                      </CTableDataCell>
                      <CTableDataCell>{shift1.toFixed(3)}</CTableDataCell>
                      <CTableDataCell>{shift2.toFixed(3)}</CTableDataCell>
                      <CTableDataCell>{shift3.toFixed(3)}</CTableDataCell>
                      <CTableDataCell>{total.toFixed(3)}</CTableDataCell>
                      <CTableDataCell>
                        <CButton
                          color={meterReplacements[meter.id] ? "warning" : "primary"}
                          size="sm"
                          onClick={() => handleReplacementClick(meter)}
                        >
                          Смена
                        </CButton>
                      </CTableDataCell>
                    </CTableRow>
                  )
                })}
              </CTableBody>
            </CTable>

            <div className="mt-3">
              <CButton color="primary" onClick={handleSaveReadings}>
                Сохранить
              </CButton>
            </div>
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
    </CRow>
  )
}

export default Counters 