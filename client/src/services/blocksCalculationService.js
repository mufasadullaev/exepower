import api from './api'

export const blocksCalculationService = {
  performFullCalculation: async (calculationData) => {
    try {
      const response = await api.post('/blocks-calculations/perform', calculationData)
      return response.data
    } catch (error) {
      console.error('Ошибка при выполнении расчета Блоков:', error)
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