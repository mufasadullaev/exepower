import axios from 'axios'
import { format } from 'date-fns'

const API_URL = '/api'

export const counterService = {
  // Получение типов счетчиков
  getMeterTypes: async () => {
    const response = await axios.get(`${API_URL}/meter-types`)
    return response.data.data
  },

  // Получение счетчиков по типу
  getMeters: async (typeId) => {
    const response = await axios.get(`${API_URL}/meters?type_id=${typeId}`)
    return response.data.data
  },

  // Получение показаний на дату
  getReadings: async (date) => {
    const formattedDate = format(date, 'yyyy-MM-dd')
    const response = await axios.get(`${API_URL}/meter-readings?date=${formattedDate}`)
    return response.data.data
  },

  // Сохранение показаний
  saveReadings: async (date, readings) => {
    const formattedDate = format(date, 'yyyy-MM-dd')
    const response = await axios.post(`${API_URL}/meter-readings`, {
      date: formattedDate,
      readings
    })
    return response.data
  },

  // Сохранение замены счетчика
  saveReplacement: async (meterId, replacementData) => {
    const response = await axios.post(`${API_URL}/meter-replacements`, {
      meter_id: meterId,
      ...replacementData
    })
    return response.data
  }
} 