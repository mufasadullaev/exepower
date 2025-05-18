// Dashboard service for fetching dashboard data from the API
import api from './api'

export const dashboardService = {
  /**
   * Get dashboard data
   * @returns {Promise<Object>} - Dashboard data
   */
  getDashboardData: async () => {
    try {
      const response = await api.get('/dashboard');
      return response.data;
    } catch (error) {
      console.error('Error fetching dashboard data:', error);
      throw error;
    }
  },

  /**
   * Get parameters for equipment
   * @param {string} equipmentType - Type of equipment (block or pgu)
   * @returns {Promise<Array>} - Parameters list
   */
  getParameters: async (equipmentType) => {
    try {
      const response = await api.get(`/parameters?type=${equipmentType}`);
      return response.data.data;
    } catch (error) {
      console.error('Error fetching parameters:', error);
      throw error;
    }
  },

  /**
   * Get parameter values
   * @param {Object} queryParams - Query parameters
   * @returns {Promise<Array>} - Parameter values
   */
  getParameterValues: async (queryParams) => {
    try {
      const queryString = new URLSearchParams(queryParams).toString();
      const response = await api.get(`/parameter-values?${queryString}`);
      return response.data.data;
    } catch (error) {
      console.error('Error fetching parameter values:', error);
      throw error;
    }
  }
};

export default dashboardService; 