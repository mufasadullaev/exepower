import { format } from 'date-fns'
import api from './api'

export const counterService = {
  getMeterTypes: async () => {
    const response = await api.get('/meter-types')
    return response.data.data
  },

  getMeters: async (typeId) => {
    const response = await api.get(`/meters?type_id=${typeId}`)
    return response.data.data
  },

  getReadings: async (date) => {
    const formattedDate = format(date, 'yyyy-MM-dd')
    const response = await api.get(`/meter-readings?date=${formattedDate}`)
    return response.data.data
  },

  saveReadings: async (date, readings) => {
    const formattedDate = format(date, 'yyyy-MM-dd')
    const response = await api.post('/meter-readings', {
      date: formattedDate,
      readings
    })
    return response.data
  },

  getReplacement: async (meterId, date) => {
    const formattedDate = format(date, 'yyyy-MM-dd')
    const response = await api.get(`/meter-replacements?meter_id=${meterId}&date=${formattedDate}`)
    return response.data.data
  },

  saveReplacement: async (meterId, replacementData) => {
    const response = await api.post('/meter-replacements', {
      meter_id: meterId,
      ...replacementData
    })
    return response.data
  },
  
  updateReplacement: async (replacementId, replacementData) => {
    const response = await api.put(`/meter-replacements/${replacementId}`, replacementData)
    return response.data
  },

  async cancelReplacement(meterId, date) {
    const response = await api.post('/meter-replacements/cancel', {
      meter_id: meterId,
      replacement_date: date
    })
    return response.data
  },

  /**
   * Массовая синхронизация данных счетчиков с pgu_fullparam_values
   * @returns {Promise} - Promise resolving to sync result
   */
  bulkSyncMeterReadings: async () => {
    try {
      const response = await api.post('/counters/bulk-sync')
      return response.data
    } catch (error) {
      console.error('Error during bulk sync:', error)
      throw error
    }
  }
} 