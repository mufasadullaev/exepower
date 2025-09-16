import React, { useState, useEffect } from 'react'
import {
  CModal,
  CModalHeader,
  CModalTitle,
  CModalBody,
  CModalFooter,
  CButton,
  CForm,
  CFormLabel,
  CFormInput,
  CFormSelect,
  CAlert,
  CTable,
  CTableHead,
  CTableRow,
  CTableHeaderCell,
  CTableBody,
  CTableDataCell,
  CRow,
  CCol,
  CCard,
  CCardBody,
  CCardHeader,
  CCardTitle
} from '@coreui/react'
import commonMeterService from '../services/commonMeterService'

const CommonMeterUsageModal = ({ 
  show, 
  onClose, 
  meter, 
  date, 
  onSave 
}) => {
  const [tgBlocks, setTgBlocks] = useState([])
  const [usageData, setUsageData] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [success, setSuccess] = useState(null)

  useEffect(() => {
    if (show && meter) {
      setError(null)
      setSuccess(null)
      loadTgBlocks()
      loadExistingUsage()
    }
  }, [show, meter, date])

  const loadTgBlocks = async () => {
    try {
      const blocks = await commonMeterService.getTgBlocks()
      console.log('TG Blocks data received:', blocks, 'Type:', typeof blocks, 'Is Array:', Array.isArray(blocks))
      
      // Принудительно приводим к массиву
      let blocksArray = []
      if (Array.isArray(blocks)) {
        blocksArray = blocks
      } else if (blocks && typeof blocks === 'object' && blocks.data && Array.isArray(blocks.data)) {
        blocksArray = blocks.data
      } else {
        console.log('Blocks is not an array, setting empty array')
        blocksArray = []
      }
      
      setTgBlocks(blocksArray)
    } catch (error) {
      console.error('Ошибка при загрузке блоков ТГ:', error)
      setError('Ошибка при загрузке блоков ТГ: ' + (error.response?.data?.error?.message || error.message))
      setTgBlocks([])
    }
  }

  const loadExistingUsage = async () => {
    try {
      const usage = await commonMeterService.getCommonMeterUsage(date)
      console.log('Usage data received:', usage, 'Type:', typeof usage, 'Is Array:', Array.isArray(usage))
      
      // Принудительно приводим к массиву
      let usageArray = []
      if (Array.isArray(usage)) {
        usageArray = usage
      } else if (usage && typeof usage === 'object' && usage.data && Array.isArray(usage.data)) {
        usageArray = usage.data
      } else {
        console.log('Usage is not an array, setting empty array')
        usageArray = []
      }
      
      const meterUsage = usageArray.find(u => u && u.meter_id === meter.id)
      if (meterUsage && meterUsage.blocks) {
        setUsageData(Array.isArray(meterUsage.blocks) ? meterUsage.blocks : [])
      } else {
        setUsageData([])
      }
    } catch (error) {
      console.error('Ошибка при загрузке существующих данных:', error)
      setError('Ошибка при загрузке существующих данных: ' + (error.response?.data?.error?.message || error.message))
      setUsageData([])
    }
  }

  const addBlockUsage = () => {
    setUsageData([...usageData, {
      equipment_id: '',
      start_time: '',
      end_time: '',
      start_reading: '',
      end_reading: ''
    }])
  }

  const removeBlockUsage = (index) => {
    const newData = usageData.filter((_, i) => i !== index)
    setUsageData(newData)
  }

  const updateBlockUsage = (index, field, value) => {
    const newData = [...usageData]
    newData[index][field] = value
    setUsageData(newData)
  }

  const validateUsageData = () => {
    for (let i = 0; i < usageData.length; i++) {
      const block = usageData[i]
      if (!block.equipment_id) {
        setError(`Блок не выбран для записи ${i + 1}`)
        return false
      }
      if (!block.start_time || !block.end_time) {
        setError(`Время работы не указано для записи ${i + 1}`)
        return false
      }
      if (block.start_time >= block.end_time) {
        setError(`Время начала должно быть меньше времени окончания для записи ${i + 1}`)
        return false
      }
      if (!block.start_reading || !block.end_reading) {
        setError(`Показания не указаны для записи ${i + 1}`)
        return false
      }
      if (parseFloat(block.start_reading) < 0 || parseFloat(block.end_reading) < 0) {
        setError(`Показания не могут быть отрицательными для записи ${i + 1}`)
        return false
      }
    }
    return true
  }

  const handleSave = async () => {
    try {
      setError(null)
      setSuccess(null)

      // Убрано ограничение на минимальное количество записей

      if (!validateUsageData()) {
        return
      }

      setLoading(true)

      await commonMeterService.saveCommonMeterUsage({
        date,
        meter_id: meter.id,
        usage_data: usageData
      })

      setSuccess('Данные об использовании общих счетчиков сохранены')
      onSave()
      onClose()
    } catch (error) {
      console.error('Ошибка при сохранении:', error)
      const errorMessage = error.response?.data?.error?.message || 'Произошла ошибка при сохранении'
      setError(errorMessage)
    } finally {
      setLoading(false)
    }
  }

  const calculateEnergyConsumed = (startReading, endReading, coefficient) => {
    if (!startReading || !endReading || !coefficient) return 0
    const delta = parseFloat(endReading) - parseFloat(startReading)
    return (delta * parseFloat(coefficient)) / 1000
  }

  return (
    <CModal size="xl" visible={show} onClose={onClose}>
      <CModalHeader>
        <CModalTitle>
          Использование счетчика {meter?.name} за {date}
        </CModalTitle>
      </CModalHeader>
      <CModalBody>
        {error && <CAlert color="danger">{error}</CAlert>}
        {success && <CAlert color="success">{success}</CAlert>}

        <CCard>
          <CCardHeader>
            <CCardTitle>Работа блоков на общем счетчике</CCardTitle>
          </CCardHeader>
          <CCardBody>
            <div className="mb-3">
              <CButton color="primary" onClick={addBlockUsage}>
                Добавить блок
              </CButton>
            </div>

            {Array.isArray(usageData) && usageData.length > 0 && (
              <CTable responsive>
                <CTableHead>
                  <CTableRow>
                    <CTableHeaderCell>Блок</CTableHeaderCell>
                    <CTableHeaderCell>Время начала</CTableHeaderCell>
                    <CTableHeaderCell>Время окончания</CTableHeaderCell>
                    <CTableHeaderCell>Начальное показание</CTableHeaderCell>
                    <CTableHeaderCell>Конечное показание</CTableHeaderCell>
                    <CTableHeaderCell>Потреблено (МВт⋅ч)</CTableHeaderCell>
                    <CTableHeaderCell>Действия</CTableHeaderCell>
                  </CTableRow>
                </CTableHead>
                <CTableBody>
                  {usageData.map((block, index) => (
                    <CTableRow key={index}>
                      <CTableDataCell>
                        <CFormSelect
                          value={block.equipment_id}
                          onChange={(e) => updateBlockUsage(index, 'equipment_id', e.target.value)}
                        >
                          <option value="">Выберите блок</option>
                          {Array.isArray(tgBlocks) && tgBlocks.map(blockOption => (
                            <option key={blockOption.id} value={blockOption.id}>
                              {blockOption.name}
                            </option>
                          ))}
                        </CFormSelect>
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="time"
                          value={block.start_time}
                          onChange={(e) => updateBlockUsage(index, 'start_time', e.target.value)}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="time"
                          value={block.end_time}
                          onChange={(e) => updateBlockUsage(index, 'end_time', e.target.value)}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="number"
                          step="0.001"
                          value={block.start_reading}
                          onChange={(e) => updateBlockUsage(index, 'start_reading', e.target.value)}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        <CFormInput
                          type="number"
                          step="0.001"
                          value={block.end_reading}
                          onChange={(e) => updateBlockUsage(index, 'end_reading', e.target.value)}
                        />
                      </CTableDataCell>
                      <CTableDataCell>
                        {calculateEnergyConsumed(
                          block.start_reading, 
                          block.end_reading, 
                          meter?.coefficient_k
                        ).toFixed(3)}
                      </CTableDataCell>
                      <CTableDataCell>
                        <CButton
                          color="danger"
                          size="sm"
                          onClick={() => removeBlockUsage(index)}
                        >
                          Удалить
                        </CButton>
                      </CTableDataCell>
                    </CTableRow>
                  ))}
                </CTableBody>
              </CTable>
            )}

            {(!Array.isArray(usageData) || usageData.length === 0) && (
              <div className="text-center text-muted py-4">
                Нет данных о работе блоков. Нажмите "Добавить блок" для создания записи.
              </div>
            )}
          </CCardBody>
        </CCard>
      </CModalBody>
      <CModalFooter>
        <CButton color="secondary" onClick={onClose}>
          Отмена
        </CButton>
        <CButton 
          color="primary" 
          onClick={handleSave}
          disabled={loading}
        >
          {loading ? 'Сохранение...' : 'Сохранить'}
        </CButton>
      </CModalFooter>
    </CModal>
  )
}

export default CommonMeterUsageModal
