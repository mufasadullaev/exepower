/**
 * API Service - Centralized Axios configuration
 */
import axios from 'axios'
import authService from './authService'

// Создаем экземпляр axios с предустановленной базовой URL
const api = axios.create({
  baseURL: 'http://exepower/api'
})

// Добавляем перехватчик для всех запросов к API
api.interceptors.request.use(
  config => {
    const token = authService.getToken()
    if (token) {
      config.headers['Authorization'] = `Bearer ${token}`
    }
    return config
  },
  error => {
    console.error('Request error:', error)
    return Promise.reject(error)
  }
)

// Добавляем перехватчик для обработки ответов
api.interceptors.response.use(
  response => response,
  error => {
    // Если получен ответ 401 (Unauthorized)
    if (error.response && error.response.status === 401) {
      console.log('Unauthorized, redirecting to login')
      // Очищаем данные авторизации
      authService.logout()
      // Перенаправляем на страницу входа
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export default api 