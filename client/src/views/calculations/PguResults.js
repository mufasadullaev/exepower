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
  CSpinner
} from '@coreui/react'
import { useLocation, useNavigate } from 'react-router-dom'
import CIcon from '@coreui/icons-react'
import { cilArrowLeft, cilFile } from '@coreui/icons'
import { getPguResultParams } from '../../services/pguResultsService'
import './PguResults.scss'

const PguResults = () => {
  const location = useLocation()
  const navigate = useNavigate()
  
  // Состояние для загрузки
  const [loading, setLoading] = useState(true)
  
  // Состояние для данных из pgu_result_params
  const [params, setParams] = useState([])

  // Получаем параметры из URL или location state
  const searchParams = new URLSearchParams(location.search)
  const date = searchParams.get('date') || location.state?.date
  const periodType = searchParams.get('periodType') || location.state?.periodType
  const shifts = searchParams.get('shifts') || location.state?.shifts

  useEffect(() => {
    // Загружаем данные из pgu_result_params
    fetchPguResultParams()
  }, [])

  const fetchPguResultParams = async () => {
    try {
      setLoading(true)
      const response = await getPguResultParams()
      if (response && response.params) {
        setParams(response.params)
      }
    } catch (error) {
      console.error('Ошибка загрузки данных:', error)
    } finally {
      setLoading(false)
    }
  }

  // Обработчик возврата к расчетам
  const handleBack = () => {
    navigate('/calculations')
  }

  // Обработчик экспорта в Excel
  const handleExportExcel = () => {
    try {
      // Создаем данные для Excel
      const excelData = [
        ['Название', 'Ед. Измерения', 'Обозначение', 'ПГУ1', 'ПГУ2', 'ТЭС']
      ]

      // Добавляем данные параметров
      params.forEach(param => {
        excelData.push([
          param.name,
          param.unit || '',
          param.symbol || '',
          '-',
          '-',
          '-'
        ])
      })

      // Создаем CSV строку
      const csvContent = excelData.map(row => 
        row.map(cell => `"${cell}"`).join(',')
      ).join('\n')

      // Создаем BOM для правильной кодировки UTF-8
      const BOM = '\uFEFF'
      const blob = new Blob([BOM + csvContent], { 
        type: 'text/csv;charset=utf-8;' 
      })

      // Создаем ссылку для скачивания
      const link = document.createElement('a')
      const url = URL.createObjectURL(blob)
      link.setAttribute('href', url)
      link.setAttribute('download', `pgu_results_${date || new Date().toISOString().split('T')[0]}.csv`)
      link.style.visibility = 'hidden'
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      URL.revokeObjectURL(url)
    } catch (error) {
      console.error('Ошибка при экспорте в Excel:', error)
      alert('Ошибка при экспорте файла')
    }
  }

  return (
    <CRow className="pgu-results">
      <CCol xs={12}>
        <CCard className="mb-4">
          <CCardHeader>
            <CRow className="align-items-center">
              <CCol>
                <h4 className="mb-0">Результаты расчетов ПГУ</h4>
                <small className="text-muted">
                  {periodType === 'shift' ? 'Смена' : 
                   periodType === 'day' ? 'Сутки' : 'Период'} - {date}
                </small>
              </CCol>
              <CCol xs="auto">
                <div className="d-flex gap-2">
                  <CButton
                    color="outline-secondary"
                    size="sm"
                    onClick={handleBack}
                  >
                    <CIcon icon={cilArrowLeft} className="me-1" />
                    Назад
                  </CButton>
                  <CButton
                    color="outline-success"
                    size="sm"
                    onClick={handleExportExcel}
                    disabled={!params || params.length === 0}
                  >
                    <CIcon icon={cilFile} className="me-1" />
                    Скачать Excel
                  </CButton>
                </div>
              </CCol>
            </CRow>
          </CCardHeader>
          <CCardBody>
            {loading ? (
              <div className="text-center py-4">
                <CSpinner />
                <p className="mt-2">Загрузка данных...</p>
              </div>
            ) : (
              <CTable hover responsive>
                <CTableHead>
                  <CTableRow>
                    <CTableHeaderCell scope="col">Название</CTableHeaderCell>
                    <CTableHeaderCell scope="col">Ед. Измерения</CTableHeaderCell>
                    <CTableHeaderCell scope="col">Обозначение</CTableHeaderCell>
                    <CTableHeaderCell scope="col">ПГУ1</CTableHeaderCell>
                    <CTableHeaderCell scope="col">ПГУ2</CTableHeaderCell>
                    <CTableHeaderCell scope="col">ТЭС</CTableHeaderCell>
                  </CTableRow>
                </CTableHead>
                                  <CTableBody>
                    {params && params.length > 0 ? (
                      params.map((param) => (
                        <CTableRow key={param.id}>
                          <CTableDataCell>
                            <strong>{param.name}</strong>
                          </CTableDataCell>
                          <CTableDataCell>{param.unit}</CTableDataCell>
                          <CTableDataCell>{param.symbol}</CTableDataCell>
                          <CTableDataCell>-</CTableDataCell>
                          <CTableDataCell>-</CTableDataCell>
                          <CTableDataCell>-</CTableDataCell>
                        </CTableRow>
                      ))
                    ) : (
                      <CTableRow>
                        <CTableDataCell colSpan={6} className="text-center">
                          Нет данных для отображения
                        </CTableDataCell>
                      </CTableRow>
                    )}
                  </CTableBody>
              </CTable>
            )}
          </CCardBody>
        </CCard>
      </CCol>
    </CRow>
  )
}

export default PguResults 