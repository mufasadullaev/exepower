import api from './api'

/**
 * Получить все параметры результатов ПГУ
 */
export const getPguResultParams = async () => {
  try {
    console.log('Запрос к API: /pgu-result-params')
    const response = await api.get('/pgu-result-params')
    console.log('Ответ API:', response.data)
    // API возвращает данные в response.data.data.params
    return response.data.data
  } catch (error) {
    console.error('Ошибка при получении параметров ПГУ:', error)
    throw error
  }
}

/**
 * Получить значения результатов ПГУ
 * @param {Object} params - Параметры запроса
 * @param {string} params.date - Дата
 * @param {string} params.periodType - Тип периода (shift, day, period)
 * @param {Array} params.equipmentIds - ID оборудования
 * @param {Array} params.shifts - Выбранные смены
 */
export const getPguResultValues = async (params) => {
  try {
    const response = await api.get('/pgu-result-values', { params })
    return response.data
  } catch (error) {
    console.error('Ошибка при получении результатов ПГУ:', error)
    throw error
  }
}

/**
 * Сохранить значения результатов ПГУ
 * @param {Object} data - Данные для сохранения
 * @param {string} data.date - Дата
 * @param {string} data.periodType - Тип периода
 * @param {Array} data.values - Массив значений
 */
export const savePguResultValues = async (data) => {
  try {
    const response = await api.post('/pgu-result-values', data)
    return response.data
  } catch (error) {
    console.error('Ошибка при сохранении результатов ПГУ:', error)
    throw error
  }
} 