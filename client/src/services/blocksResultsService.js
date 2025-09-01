import api from './api'

export const getBlocksResultParams = async () => {
  try {
    const response = await api.get('/blocks-result-params')
    return response.data.data
  } catch (error) {
    console.error('Ошибка при получении параметров Блоков:', error)
    throw error
  }
}

export const getBlocksResultValues = async (params) => {
  try {
    const response = await api.get('/blocks-result-values', { params })
    return response.data.data
  } catch (error) {
    console.error('Ошибка при получении результатов Блоков:', error)
    throw error
  }
}

export const saveBlocksResultValues = async (data) => {
  try {
    const response = await api.post('/blocks-result-values', data)
    return response.data
  } catch (error) {
    console.error('Ошибка при сохранении результатов Блоков:', error)
    throw error
  }
} 