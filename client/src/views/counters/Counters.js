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
  const [showReplacementModal, setShowReplacementModal] = useState(false)
  const [selectedMeter, setSelectedMeter] = useState(null)
  const [replacementData, setReplacementData] = useState({
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

      // Получаем текущего пользователя
      const user = authService.getUser()
      if (!user) {
        setError('Пользователь не авторизован')
        return
      }

      // Добавляем user_id к каждому показанию
      const readingsWithUser = Object.entries(readings).reduce((acc, [meterId, reading]) => {
        acc[meterId] = {
          ...reading,
          user_id: user.id
        }
        return acc
      }, {})

      await counterService.saveReadings(selectedDate, readingsWithUser)
      loadReadings(selectedDate)
      setSuccess('Показания успешно сохранены')
    } catch (error) {
      console.error('Error saving readings:', error)
      const errorMessage = error.response?.data?.error?.message || 'Произошла ошибка при сохранении показаний'
      setError(errorMessage)
    }
  }

  const handleReplacementClick = (meter) => {
    setSelectedMeter(meter)
    setReplacementData({
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
    setShowReplacementModal(true)
  }

  const handleReplacementSave = async () => {
    try {
      // Валидация полей
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
      
      // Получаем текущего пользователя
      const user = authService.getUser()
      if (!user) {
        setModalError('Пользователь не авторизован')
        return
      }
      
      // Форматируем данные для отправки
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
        comment: replacementData.comment || '',
        user_id: user.id
      }

      await counterService.saveReplacement(selectedMeter.id, formattedData)
      setShowReplacementModal(false)
      setSuccess('Замена счетчика успешно сохранена')
      loadMeters(selectedType)
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
                  const shift1 = calculateShift(reading.r0, reading.r8, meter)
                  const shift2 = calculateShift(reading.r8, reading.r16, meter)
                  const shift3 = calculateShift(reading.r16, reading.r24, meter)
                  const total = shift1 + shift2 + shift3

                  return (
                    <CTableRow key={meter.id}>
                      <CTableDataCell>
                        {meter.name})
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
                      <CTableDataCell>{shift1?.toFixed(3)}</CTableDataCell>
                      <CTableDataCell>{shift2?.toFixed(3)}</CTableDataCell>
                      <CTableDataCell>{shift3?.toFixed(3)}</CTableDataCell>
                      <CTableDataCell>{total?.toFixed(3)}</CTableDataCell>
                      <CTableDataCell>
                        <CButton
                          color="primary"
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
          <CButton color="primary" onClick={handleReplacementSave}>
            Сохранить
          </CButton>
        </CModalFooter>
      </CModal>
    </CRow>
  )
}

export default Counters 