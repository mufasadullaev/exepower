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
  toggleTool: async (equipmentId, toolType, isOn) => {
    try {
      const response = await api.post('/equipment-tools/toggle', {
        equipment_id: equipmentId,
        tool_type: toolType,
        event_type: isOn ? 'on' : 'off'
      })
      return response.data
    } catch (error) {
      console.error('Error toggling tool:', error)
      throw error
    }
  }
}

export default equipmentToolService 