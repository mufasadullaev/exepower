// Dashboard service for fetching dashboard data from the API
import authService from './authService';

// Используем абсолютный URL для API
const API_URL = 'http://exepower/api';

export const dashboardService = {
  /**
   * Get dashboard data
   * @returns {Promise<Object>} Dashboard data
   */
  getDashboardData: async () => {
    try {
      const token = authService.getToken();
      
      if (!token) {
        throw new Error('Authentication token not found');
      }
      
      console.log('Fetching dashboard data from:', API_URL + '/dashboard');
      const response = await fetch(`${API_URL}/dashboard`, {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      
      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Failed to fetch dashboard data');
      }
      
      const data = await response.json();
      console.log('Dashboard data response:', data);
      return data.data;
    } catch (error) {
      console.error('Error fetching dashboard data:', error);
      throw error;
    }
  }
};

export default dashboardService; 