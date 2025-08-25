import api from './api'

export const pguCalculationService = {
  /**
   * Выполнить полный расчет ПГУ
   * @param {Object} calculationData - Данные для расчета
   * @returns {Promise<Object>} - Результаты расчета
   */
  performFullCalculation: async (calculationData) => {
    try {
      const response = await api.post('/pgu-calculations/perform', calculationData)
      return response.data
    } catch (error) {
      console.error('Ошибка при выполнении расчета ПГУ:', error)
      throw error
    }
  },

  /**
   * Получить статус расчета
   * @param {string} calculationId - ID расчета
   * @returns {Promise<Object>} - Статус расчета
   */
  getCalculationStatus: async (calculationId) => {
    try {
      const response = await api.get(`/pgu-calculations/status/${calculationId}`)
      return response.data
    } catch (error) {
      console.error('Ошибка при получении статуса расчета:', error)
      throw error
    }
  }
} 