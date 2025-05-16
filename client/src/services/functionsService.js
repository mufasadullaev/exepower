import authService from './authService';

// Используем абсолютный URL для API
const API_URL = 'http://exepower/api';

/**
 * Service for working with functions and coefficients
 */
const functionsService = {
  /**
   * Get all functions
   * @returns {Promise<Array>} Array of functions
   */
  getAllFunctions: async () => {
    try {
      const response = await fetch(`${API_URL}/functions`, {
        headers: {
          'Authorization': `Bearer ${authService.getToken()}`
        }
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      if (!data.success && data.status !== 'success') {
        throw new Error(data.message || 'Failed to fetch functions');
      }
      
      return data.data.functions || [];
    } catch (error) {
      console.error('Error fetching functions:', error);
      throw error;
    }
  },
  
  /**
   * Get coefficient sets for a function
   * @param {number} functionId - Function ID
   * @returns {Promise<Object>} Function data with coefficient sets
   */
  getCoeffSets: async (functionId) => {
    try {
      const response = await fetch(`${API_URL}/functions/${functionId}/coeff_sets`, {
        headers: {
          'Authorization': `Bearer ${authService.getToken()}`
        }
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      if (!data.success && data.status !== 'success') {
        throw new Error(data.message || 'Failed to fetch coefficient sets');
      }
      
      return {
        function: data.data.function,
        coeffSets: data.data.coeff_sets || []
      };
    } catch (error) {
      console.error(`Error fetching coefficient sets for function ${functionId}:`, error);
      throw error;
    }
  },
  
  /**
   * Get coefficients for a set
   * @param {number} functionId - Function ID
   * @param {number} setId - Coefficient set ID
   * @returns {Promise<Object>} Coefficient data
   */
  getCoefficients: async (functionId, setId) => {
    try {
      const response = await fetch(`${API_URL}/functions/${functionId}/coeff_sets/${setId}/coefficients`, {
        headers: {
          'Authorization': `Bearer ${authService.getToken()}`
        }
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      if (!data.success && data.status !== 'success') {
        throw new Error(data.message || 'Failed to fetch coefficients');
      }
      
      return {
        function: data.data.function,
        coeffSet: data.data.coeff_set,
        coefficients: data.data.coefficients || []
      };
    } catch (error) {
      console.error(`Error fetching coefficients for set ${setId}:`, error);
      throw error;
    }
  },
  
  /**
   * Update coefficients for a set
   * @param {number} functionId - Function ID
   * @param {number} setId - Coefficient set ID
   * @param {Array} coefficients - Array of coefficient objects with id and value
   * @returns {Promise<Object>} Response data
   */
  updateCoefficients: async (functionId, setId, coefficients) => {
    try {
      const response = await fetch(`${API_URL}/functions/${functionId}/coeff_sets/${setId}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authService.getToken()}`
        },
        body: JSON.stringify({ coefficients })
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      if (!data.success && data.status !== 'success') {
        throw new Error(data.message || 'Failed to update coefficients');
      }
      
      return data.data;
    } catch (error) {
      console.error(`Error updating coefficients for set ${setId}:`, error);
      throw error;
    }
  },
  
  /**
   * Create a new coefficient set
   * @param {number} functionId - Function ID
   * @param {number} xValue - X value for the set
   * @param {Array} coefficients - Array of coefficient objects with index and value
   * @returns {Promise<Object>} Response data
   */
  createCoeffSet: async (functionId, xValue, coefficients) => {
    try {
      const response = await fetch(`${API_URL}/functions/${functionId}/coeff_sets`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authService.getToken()}`
        },
        body: JSON.stringify({ 
          x_value: xValue,
          coefficients 
        })
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      if (!data.success && data.status !== 'success') {
        throw new Error(data.message || 'Failed to create coefficient set');
      }
      
      return data.data;
    } catch (error) {
      console.error(`Error creating coefficient set for function ${functionId}:`, error);
      throw error;
    }
  }
};

export default functionsService; 