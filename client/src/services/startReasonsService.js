import api from './api'

export const startReasonsService = {
  // Получить список типов пусков
  getStartReasons: async () => {
    try {
      const response = await api.get('/start-reasons')
      return response.data
    } catch (error) {
      console.error('Error fetching start reasons:', error)
      throw error
    }
  }
}

export default startReasonsService 