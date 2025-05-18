import api from './api'

/**
 * Service for working with functions and coefficients
 */
export const functionsService = {
  /**
   * Get all functions
   * @returns {Promise<Array>} - Array of functions
   */
  getAllFunctions: async () => {
    try {
      const response = await api.get('/functions');
      
      if (!response.data.success && response.data.status !== 'success') {
        throw new Error(response.data.message || 'Failed to fetch functions');
      }
      
      return response.data.data.functions || [];
    } catch (error) {
      console.error('Error fetching functions:', error);
      throw error;
    }
  },
  
  /**
   * Get coefficient sets for a function
   * @param {number} functionId - Function ID
   * @returns {Promise<Object>} - Function and coefficient sets
   */
  getCoeffSets: async (functionId) => {
    try {
      const response = await api.get(`/functions/${functionId}/coeff_sets`);
      
      if (!response.data.success && response.data.status !== 'success') {
        throw new Error(response.data.message || 'Failed to fetch coefficient sets');
      }
      
      return {
        function: response.data.data.function,
        coeffSets: response.data.data.coeff_sets || []
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
   * @returns {Promise<Object>} - Function, coefficient set, and coefficients
   */
  getCoefficients: async (functionId, setId) => {
    try {
      const response = await api.get(`/functions/${functionId}/coeff_sets/${setId}/coefficients`);
      
      if (!response.data.success && response.data.status !== 'success') {
        throw new Error(response.data.message || 'Failed to fetch coefficients');
      }
      
      return {
        function: response.data.data.function,
        coeffSet: response.data.data.coeff_set,
        coefficients: response.data.data.coefficients || []
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
   * @param {Array} coefficients - Coefficients to update
   * @returns {Promise<Object>} - Updated data
   */
  updateCoefficients: async (functionId, setId, coefficients) => {
    try {
      const response = await api.put(`/functions/${functionId}/coeff_sets/${setId}`, { 
        coefficients 
      });
      
      if (!response.data.success && response.data.status !== 'success') {
        throw new Error(response.data.message || 'Failed to update coefficients');
      }
      
      return response.data.data;
    } catch (error) {
      console.error(`Error updating coefficients for set ${setId}:`, error);
      throw error;
    }
  },
  
  /**
   * Create a new coefficient set
   * @param {number} functionId - Function ID
   * @param {number} xValue - X value for the set
   * @param {Array} coefficients - Coefficients for the set
   * @returns {Promise<Object>} - Created data
   */
  createCoeffSet: async (functionId, xValue, coefficients) => {
    try {
      const response = await api.post(`/functions/${functionId}/coeff_sets`, { 
        x_value: xValue,
        coefficients 
      });
      
      if (!response.data.success && response.data.status !== 'success') {
        throw new Error(response.data.message || 'Failed to create coefficient set');
      }
      
      return response.data.data;
    } catch (error) {
      console.error(`Error creating coefficient set for function ${functionId}:`, error);
      throw error;
    }
  }
};

export default functionsService; 