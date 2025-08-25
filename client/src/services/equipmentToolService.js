import api from './api'

export const equipmentToolService = {
  // Получить текущий статус инструментов для оборудования
  getToolStatus: async (equipmentId) => {
    try {
      const response = await api.get(`/equipment-tools/status/${equipmentId}`)
      return response.data
    } catch (error) {
      console.error('Error fetching tool status:', error)
      throw error
    }
  },

  // Включить/выключить инструмент
  toggleTool: async (equipmentId, toolType, isOn, eventTime = null, comment = '') => {
    try {
      const payload = {
        equipment_id: equipmentId,
        tool_type: toolType,
        event_type: isOn ? 'on' : 'off'
      }
      
      // Добавляем время события и комментарий если они переданы
      if (eventTime) {
        payload.event_time = eventTime
      }
      if (comment) {
        payload.comment = comment
      }
      
      const response = await api.post('/equipment-tools/toggle', payload)
      return response.data
    } catch (error) {
      console.error('Error toggling tool:', error)
      throw error
    }
  }
}

export default equipmentToolService 