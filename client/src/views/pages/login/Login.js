import React, { useState, useEffect } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  CButton,
  CCard,
  CCardBody,
  CCardGroup,
  CCol,
  CContainer,
  CForm,
  CFormInput,
  CInputGroup,
  CInputGroupText,
  CRow,
  CAlert,
  CSpinner
} from '@coreui/react'
import CIcon from '@coreui/icons-react'
import { cilLockLocked, cilUser } from '@coreui/icons'
import authService from '../../../services/authService'

const Login = () => {
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()
  
  // Check if user is already authenticated
  useEffect(() => {
    const checkAuth = async () => {
      if (authService.isAuthenticated()) {
        try {
          const isValid = await authService.verifyToken();
          if (isValid) {
            navigate('/dashboard');
          }
        } catch (error) {
          // Token is invalid, do nothing and let the user log in
          console.error('Token verification error:', error);
        }
      }
    };
    
    checkAuth();
  }, [navigate]);
  
  const handleSubmit = async (e) => {
    e.preventDefault()
    setError('')
    setLoading(true)
    
    try {
      // Проверка на мастер-пароль (для обратной совместимости)
      const isMasterPassword = password === 'admin123' && !username
      
      let success
      if (isMasterPassword) {
        // Вход только по мастер-паролю
        success = await authService.login(password)
      } else {
        // Вход с именем пользователя
        if (!username) {
          setError('Пожалуйста, введите имя пользователя')
          setLoading(false)
          return
        }
        success = await authService.loginWithUsername(username, password)
      }

      if (success) {
        navigate('/dashboard')
      } else {
        setError('Неверное имя пользователя или пароль')
      }
    } catch (err) {
      console.error('Login error:', err)
      setError('Ошибка при входе в систему')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="bg-light min-vh-100 d-flex flex-row align-items-center">
      <CContainer>
        <CRow className="justify-content-center">
          <CCol md={8}>
            <CCardGroup>
              <CCard className="p-4">
                <CCardBody>
                  <CForm onSubmit={handleSubmit}>
                    <h1>Вход</h1>
                    <p className="text-medium-emphasis">Войдите в свою учетную запись</p>
                    
                    {error && (
                      <CAlert color="danger" dismissible>
                        {error}
                      </CAlert>
                    )}
                    
                    <CInputGroup className="mb-3">
                      <CInputGroupText>
                        <CIcon icon={cilUser} />
                      </CInputGroupText>
                      <CFormInput
                        placeholder="Имя пользователя"
                        autoComplete="username"
                        value={username}
                        onChange={(e) => setUsername(e.target.value)}
                      />
                    </CInputGroup>
                    
                    <CInputGroup className="mb-4">
                      <CInputGroupText>
                        <CIcon icon={cilLockLocked} />
                      </CInputGroupText>
                      <CFormInput
                        type="password"
                        placeholder="Пароль"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        required
                      />
                    </CInputGroup>
                    
                    <CRow>
                      <CCol xs={6}>
                        <CButton type="submit" color="primary" className="px-4" disabled={loading}>
                          {loading ? (
                            <>
                              <CSpinner size="sm" className="me-2" />
                              Вход...
                            </>
                          ) : 'Войти'}
                        </CButton>
                      </CCol>
                    </CRow>
                  </CForm>
                </CCardBody>
              </CCard>
              <CCard className="text-white bg-primary py-5" style={{ width: '44%' }}>
                <CCardBody className="text-center">
                  <div>
                    <h2>ЭлектроСтанция</h2>
                    <p>
                      Система учета параметров оборудования электростанции.
                      Введите свои учетные данные для доступа к системе.
                    </p>
                  </div>
                </CCardBody>
              </CCard>
            </CCardGroup>
          </CCol>
        </CRow>
      </CContainer>
    </div>
  )
}

export default Login
