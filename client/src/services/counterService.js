import axios from 'axios'
import { format } from 'date-fns'

const API_URL = '/api'

export const counterService = {
  getMeterTypes: async () => {
    const response = await axios.get(`${API_URL}/meter-types`)
    return response.data.data
  },

  getMeters: async (typeId) => {
    const response = await axios.get(`${API_URL}/meters?type_id=${typeId}`)
    return response.data.data
  },

  getReadings: async (date) => {
    const formattedDate = format(date, 'yyyy-MM-dd')
    const response = await axios.get(`${API_URL}/meter-readings?date=${formattedDate}`)
    return response.data.data
  },

  saveReadings: async (date, readings) => {
    const formattedDate = format(date, 'yyyy-MM-dd')
    const response = await axios.post(`${API_URL}/meter-readings`, {
      date: formattedDate,
      readings
    })
    return response.data
  },

  saveReplacement: async (meterId, replacementData) => {
    const response = await axios.post(`${API_URL}/meter-replacements`, {
      meter_id: meterId,
      ...replacementData
    })
    return response.data
  },

  async cancelReplacement(meterId, date) {
    const response = await axios.post(`${API_URL}/meter-replacements/cancel`, {
      meter_id: meterId,
      replacement_date: date
    })
    return response.data
  }
} 