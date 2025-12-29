import api from './api'

export const blocksCalculationService = {
  performFullCalculation: async (calculationData) => {
    try {
      console.log('Отправка данных на сервер:', JSON.stringify(calculationData, null, 2))
      const response = await api.post('/blocks-calculations/perform', calculationData)
      return response.data
    } catch (error) {
      console.error('Ошибка при выполнении расчета Блоков:', error)
      if (error.response) {
        console.error('Статус ответа:', error.response.status)
        console.error('Данные ответа:', error.response.data)
        const errorMessage = error.response.data?.error?.message || error.response.data?.message || error.message
        throw new Error(errorMessage)
      }
      throw error
    }
  },

  getCalculationStatus: async (calculationId) => {
    try {
      const response = await api.get(`/blocks-calculations/status/${calculationId}`)
      return response.data
    } catch (error) {
      console.error('Ошибка при получении статуса расчета Блоков:', error)
      throw error
    }
  }
}

export default blocksCalculationService 