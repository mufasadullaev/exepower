import api from './api'

const commonMeterService = {
  // Получение общих счетчиков
  getCommonMeters: async () => {
    try {
      const response = await api.get('/common-meters')
      return response.data.data || []
    } catch (error) {
      console.error('Ошибка при получении общих счетчиков:', error)
      throw error
    }
  },

  // Получение блоков ТГ7 и ТГ8
  getTgBlocks: async () => {
    try {
      const response = await api.get('/tg-blocks')
      console.log('API response for TG blocks:', response.data)
      
      // Проверяем разные возможные форматы ответа
      if (response.data && response.data.data) {
        return Array.isArray(response.data.data) ? response.data.data : []
      } else if (Array.isArray(response.data)) {
        return response.data
      } else {
        console.warn('Unexpected response format:', response.data)
        return []
      }
    } catch (error) {
      console.error('Ошибка при получении блоков ТГ:', error)
      throw error
    }
  },

  // Получение данных об использовании общих счетчиков за дату
  getCommonMeterUsage: async (date) => {
    try {
      const response = await api.get(`/common-meter-usage?date=${date}`)
      console.log('API response for common meter usage:', response.data)
      
      // Проверяем разные возможные форматы ответа
      if (response.data && response.data.data) {
        return Array.isArray(response.data.data) ? response.data.data : []
      } else if (Array.isArray(response.data)) {
        return response.data
      } else {
        console.warn('Unexpected response format:', response.data)
        return []
      }
    } catch (error) {
      console.error('Ошибка при получении данных об использовании:', error)
      throw error
    }
  },

  // Сохранение данных об использовании общих счетчиков
  saveCommonMeterUsage: async (data) => {
    try {
      const response = await api.post('/common-meter-usage', data)
      return response.data
    } catch (error) {
      console.error('Ошибка при сохранении данных об использовании:', error)
      throw error
    }
  }
}

export default commonMeterService
