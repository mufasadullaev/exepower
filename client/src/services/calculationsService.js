import api from './api'

// Функция для расчета работающих вахт на определенную дату
export const calculateActiveVakhtas = (date) => {
  const currentDate = new Date(date)
  const referenceDate = new Date('2025-04-01') // Опорная дата - 1 апреля 2025
  
  const daysDiff = Math.floor((currentDate - referenceDate) / (1000 * 60 * 60 * 24))
  const patternIndex = daysDiff % 8
  
  // Паттерны вахт (такие же как в dashboard)
  const patterns = [
    ['3', '3', 'B', '1', '1', '2', '2', 'B'], // Вахта 1
    ['1', '2', '2', 'B', '3', '3', 'B', '1'], // Вахта 2
    ['2', 'B', '3', '3', 'B', '1', '1', '2'], // Вахта 3
    ['B', '1', '1', '2', '2', 'B', '3', '3']  // Вахта 4
  ]
  
  const activeVakhtas = []
  
  // Для каждой смены (1, 2, 3) определяем работающую вахту
  for (let shiftNumber = 1; shiftNumber <= 3; shiftNumber++) {
    let activeVahta = null
    
    // Проверяем все 4 вахты
    for (let vahtaNumber = 0; vahtaNumber < 4; vahtaNumber++) {
      const shiftValue = patterns[vahtaNumber][patternIndex]
      if (shiftValue == shiftNumber) {
        activeVahta = vahtaNumber + 1
        break
      }
    }
    
    activeVakhtas.push({
      shiftNumber,
      shiftName: `Смена ${shiftNumber}`,
      activeVahta: activeVahta ? `Вахта №${activeVahta}` : 'Нет активной вахты'
    })
  }
  
  return activeVakhtas
}

// Функция для получения данных о сменах
export const getShifts = async () => {
  try {
    const response = await api.get('/shifts')
    return response.data
  } catch (error) {
    console.error('Ошибка при получении смен:', error)
    return []
  }
}

// Функция для выполнения расчетов
export const performCalculations = async (calculationData) => {
  try {
    const response = await api.post('/calculations', calculationData)
    return response.data
  } catch (error) {
    console.error('Ошибка при выполнении расчетов:', error)
    throw error
  }
}

export default {
  calculateActiveVakhtas,
  getShifts,
  performCalculations
} 