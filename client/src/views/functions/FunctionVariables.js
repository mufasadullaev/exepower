import React, { useState, useEffect } from 'react'
import {
  CCard,
  CCardBody,
  CCardHeader,
  CRow,
  CCol,
  CFormSelect,
  CFormInput,
  CForm,
  CButton,
  CSpinner,
  CAlert,
  CModal,
  CModalHeader,
  CModalTitle,
  CModalBody,
  CModalFooter,
  CListGroup,
  CListGroupItem
} from '@coreui/react'
import functionsService from '../../services/functionsService'

const FunctionVariables = () => {
  // State variables
  const [functions, setFunctions] = useState([])
  const [selectedFunction, setSelectedFunction] = useState(null)
  const [coeffSets, setCoeffSets] = useState([])
  const [selectedSetId, setSelectedSetId] = useState(null)
  const [coefficients, setCoefficients] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadingCoeffs, setLoadingCoeffs] = useState(false)
  const [error, setError] = useState(null)
  const [success, setSuccess] = useState(null)
  const [showModal, setShowModal] = useState(false)
  const [showFunctionDetails, setShowFunctionDetails] = useState(false)
  
  // Load all functions on component mount
  useEffect(() => {
    const loadFunctions = async () => {
      try {
        setLoading(true)
        setError(null)
        
        const functionsData = await functionsService.getAllFunctions()
        setFunctions(functionsData)
        
        // Don't auto-select a function anymore
        setSelectedFunction(null)
        setCoeffSets([])
        setSelectedSetId(null)
        setCoefficients([])
        setShowFunctionDetails(false)
      } catch (err) {
        setError('Ошибка загрузки функций: ' + err.message)
      } finally {
        setLoading(false)
      }
    }
    
    loadFunctions()
  }, [])
  
  // Load coefficient sets for a function
  const loadCoeffSets = async (functionId) => {
    try {
      setLoadingCoeffs(true)
      setError(null)
      
      const data = await functionsService.getCoeffSets(functionId)
      setCoeffSets(data.coeffSets)
      
      // Select the first set by default
      if (data.coeffSets.length > 0) {
        setSelectedSetId(data.coeffSets[0].id)
        await loadCoefficients(functionId, data.coeffSets[0].id)
      } else {
        setCoefficients([])
        setSelectedSetId(null)
      }
    } catch (err) {
      setError('Ошибка загрузки наборов коэффициентов: ' + err.message)
    } finally {
      setLoadingCoeffs(false)
    }
  }
  
  // Load coefficients for a set
  const loadCoefficients = async (functionId, setId) => {
    try {
      setLoadingCoeffs(true)
      setError(null)
      
      const data = await functionsService.getCoefficients(functionId, setId)
      setCoefficients(data.coefficients)
    } catch (err) {
      setError('Ошибка загрузки коэффициентов: ' + err.message)
    } finally {
      setLoadingCoeffs(false)
    }
  }
  
  // Handle function selection
  const handleFunctionSelect = async (func) => {
    setSelectedFunction(func)
    await loadCoeffSets(func.id)
    setShowFunctionDetails(true)
  }
  
  // Handle back button click
  const handleBackClick = () => {
    setShowFunctionDetails(false)
    setSelectedFunction(null)
    setCoeffSets([])
    setSelectedSetId(null)
    setCoefficients([])
  }
  
  // Handle coefficient set selection change
  const handleSetChange = async (e) => {
    const setId = e.target.value
    
    if (setId && selectedFunction) {
      setSelectedSetId(setId)
      await loadCoefficients(selectedFunction.id, setId)
    }
  }
  
  // Handle coefficient value change
  const handleCoefficientChange = (id, value) => {
    // Validate input to allow only numbers and decimal point
    if (value === '' || /^-?\d*\.?\d*$/.test(value)) {
      setCoefficients(coeffs => 
        coeffs.map(coeff => 
          coeff.id === id ? { ...coeff, coeff_value: value } : coeff
        )
      )
    }
  }
  
  // Handle save button click
  const handleSaveClick = () => {
    setShowModal(true)
  }
  
  // Handle save confirmation
  const handleSaveConfirm = async () => {
    try {
      setLoadingCoeffs(true)
      setError(null)
      setSuccess(null)
      
      // Prepare coefficients data for API
      const coeffsData = coefficients.map(coeff => ({
        id: coeff.id,
        value: coeff.coeff_value
      }))
      
      await functionsService.updateCoefficients(
        selectedFunction.id,
        selectedSetId,
        coeffsData
      )
      
      setShowModal(false)
      setSuccess('Коэффициенты успешно сохранены')
      
      // Clear success message after 5 seconds
      setTimeout(() => {
        setSuccess(null)
      }, 5000)
    } catch (err) {
      setError('Ошибка сохранения коэффициентов: ' + err.message)
    } finally {
      setLoadingCoeffs(false)
    }
  }
  
  // Render function list
  const renderFunctionList = () => {
    return (
      <CCol md={12}>
        <h5 className="mb-3">Выберите функцию:</h5>
        <div className="mb-3 d-flex">
          <div style={{ width: '50%' }}><strong>Наименование</strong></div>
          <div style={{ width: '25%' }}><strong>Обозначение</strong></div>
          <div style={{ width: '25%' }}><strong>Размерность</strong></div>
        </div>
        <CListGroup>
          {functions.length > 0 ? (
            functions.map(func => (
              <CListGroupItem 
                key={func.id}
                component="button"
                onClick={() => handleFunctionSelect(func)}
                className="d-flex align-items-center"
              >
                <div style={{ width: '50%' }}>{func.name}</div>
                <div style={{ width: '25%' }}>{func.symbol}</div>
                <div style={{ width: '25%' }}>{func.unit || '-'}</div>
              </CListGroupItem>
            ))
          ) : (
            <CListGroupItem>Нет доступных функций</CListGroupItem>
          )}
        </CListGroup>
      </CCol>
    )
  }
  
  // Render function details
  const renderFunctionDetails = () => {
    return (
      <CCol md={12}>
        <h5 className="mb-3">
          {selectedFunction.name} ({selectedFunction.symbol})
          {selectedFunction.unit && (
            <small className="text-muted ms-2">[{selectedFunction.unit}]</small>
          )}
        </h5>
        
        {loadingCoeffs ? (
          <div className="text-center my-5">
            <CSpinner color="primary" />
            <p className="mt-2">Загрузка коэффициентов...</p>
          </div>
        ) : (
          <>
            <CRow className="mb-4">
              <CCol md={6}>
                <CFormSelect
                  label="Значение X"
                  value={selectedSetId || ''}
                  onChange={handleSetChange}
                  disabled={coeffSets.length === 0}
                >
                  {coeffSets.map(set => (
                    <option key={set.id} value={set.id}>
                      {set.x_value}
                    </option>
                  ))}
                </CFormSelect>
              </CCol>
            </CRow>
            
            {coefficients.length > 0 ? (
              <CForm>
                <CRow>
                  {coefficients.map(coeff => (
                    <CCol md={4} key={coeff.id} className="mb-3">
                      <CFormInput
                        label={`A${coeff.coeff_index}`}
                        type="text"
                        value={coeff.coeff_value}
                        onChange={(e) => handleCoefficientChange(coeff.id, e.target.value)}
                      />
                    </CCol>
                  ))}
                </CRow>
                
                <CRow className="mt-4">
                  <CCol>
                    <CButton 
                      color="secondary" 
                      onClick={handleBackClick}
                      className="me-2"
                    >
                      Назад
                    </CButton>
                    <CButton 
                      color="primary" 
                      onClick={handleSaveClick}
                    >
                      Сохранить
                    </CButton>
                  </CCol>
                </CRow>
              </CForm>
            ) : (
              <div>
                <p>Нет доступных коэффициентов</p>
                <CButton 
                  color="secondary" 
                  onClick={handleBackClick}
                >
                  Назад
                </CButton>
              </div>
            )}
          </>
        )}
      </CCol>
    )
  }
  
  return (
    <CCard>
      <CCardHeader>
        <h4>Переменные ПГУ</h4>
      </CCardHeader>
      <CCardBody>
        {loading ? (
          <div className="text-center my-5">
            <CSpinner color="primary" />
            <p className="mt-2">Загрузка функций...</p>
          </div>
        ) : (
          <>
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
            
            <CRow>
              {showFunctionDetails ? renderFunctionDetails() : renderFunctionList()}
            </CRow>
          </>
        )}
        
        {/* Confirmation Modal */}
        <CModal visible={showModal} onClose={() => setShowModal(false)}>
          <CModalHeader>
            <CModalTitle>Подтверждение</CModalTitle>
          </CModalHeader>
          <CModalBody>
            Вы уверены, что хотите сохранить изменения?
          </CModalBody>
          <CModalFooter>
            <CButton color="secondary" onClick={() => setShowModal(false)}>
              Нет
            </CButton>
            <CButton color="primary" onClick={handleSaveConfirm}>
              Да
            </CButton>
          </CModalFooter>
        </CModal>
      </CCardBody>
    </CCard>
  )
}

export default FunctionVariables 