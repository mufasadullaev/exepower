import axios from 'axios';
import authService from './authService';

// Use environment variable for API URL
const API_URL = import.meta.env.VITE_API_URL || 'http://exepower/api';

/**
 * Service for working with energy metrics, meters, readings and replacements
 */
const energyService = {
  /**
   * Get all energy metrics
   * @returns {Promise<Array>} Array of energy metrics
   */
  getEnergyMetrics: async () => {
    try {
      const response = await fetch(`${API_URL}/energy_metrics`, {
        headers: {
          'Authorization': `Bearer ${authService.getToken()}`
        }
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      if (data.success === false) {
        throw new Error(data.message || 'Failed to fetch energy metrics');
      }
      
      return data.data.metrics || [];
    } catch (error) {
      console.error('Error fetching energy metrics:', error);
      throw error;
    }
  },
  
  /**
   * Get meters by energy metric
   * @param {number} metricId - Energy metric ID
   * @returns {Promise<Array>} Array of meters
   */
  getMeters: async (metricId) => {
    try {
      const url = metricId 
        ? `${API_URL}/meters?metric_id=${metricId}`
        : `${API_URL}/meters`;
        
      const response = await fetch(url, {
        headers: {
          'Authorization': `Bearer ${authService.getToken()}`
        }
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      if (data.success === false) {
        throw new Error(data.message || 'Failed to fetch meters');
      }
      
      return data.data.meters || [];
    } catch (error) {
      console.error('Error fetching meters:', error);
      throw error;
    }
  },
  
  /**
   * Get meter readings for a specific date and metric
   * @param {string} date - Date in YYYY-MM-DD format
   * @param {number} metricId - Energy metric ID
   * @returns {Promise<Object>} Object with readings data
   */
  getMeterReadings: async (date, metricId) => {
    try {
      const response = await fetch(`${API_URL}/meter_readings?date=${date}&metric_id=${metricId}`, {
        headers: {
          'Authorization': `Bearer ${authService.getToken()}`
        }
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      // Check for specific error about coefficient column
      if (data.status === "error" && data.message && data.message.includes("Unknown column 'coefficient'")) {
        console.warn("API error with coefficient column. Using fallback approach.");
        return await getMeterReadingsFallback(date, metricId);
      }
      
      // Handle other API errors
      if (data.status === "error") {
        console.warn(`API returned error: ${data.message}`);
        return await getMeterReadingsFallback(date, metricId);
      }
      
      return data.data || {};
    } catch (error) {
      console.error('Error fetching meter readings:', error);
      return await getMeterReadingsFallback(date, metricId);
    }
  },
  
  /**
   * Save meter readings in bulk
   * @param {string} date - Date in YYYY-MM-DD format
   * @param {Array} readings - Array of reading objects
   * @returns {Promise<Object>} Response data
   */
  saveMeterReadings: async (date, readings) => {
    try {
      // Адаптируем данные для API, включая значение consumption
      const formattedReadings = readings.map(reading => {
        return {
          meter_id: reading.meter_id,
          shift_id: reading.shift_id,
          reading_start: reading.reading_start,
          reading_end: reading.reading_end,
          consumption: reading.consumption // Добавляем consumption
        };
      });
      
      const response = await fetch(`${API_URL}/meter_readings/bulk`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authService.getToken()}`
        },
        body: JSON.stringify({ date, readings: formattedReadings })
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      if (data.success === false || data.status === "error") {
        throw new Error(data.message || 'Failed to save meter readings');
      }
      
      return data.data;
    } catch (error) {
      console.error('Error saving meter readings:', error);
      throw error;
    }
  },
  
  /**
   * Save meter replacement
   * @param {Object} replacementData - Replacement data
   * @returns {Promise<Object>} Response data
   */
  createMeterReplacement: async (replacementData) => {
    try {
      console.log('Sending request to:', `${API_URL}/meter_replacements`);
      console.log('Request data:', JSON.stringify(replacementData, null, 2));
      
      const response = await fetch(`${API_URL}/meter_replacements`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authService.getToken()}`
        },
        body: JSON.stringify(replacementData)
      });
      
      const responseText = await response.text();
      console.log('Response status:', response.status);
      console.log('Response text:', responseText);
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}, Response: ${responseText}`);
      }
      
      let data;
      try {
        data = JSON.parse(responseText);
      } catch (e) {
        throw new Error(`Invalid JSON response: ${responseText}`);
      }
      
      if (data.success === false || data.status === "error") {
        throw new Error(data.message || 'Failed to save meter replacement');
      }
      
      return data.data;
    } catch (error) {
      console.error('Error saving meter replacement:', error);
      throw error;
    }
  },
  
  /**
   * Delete meter replacement
   * @param {number} replacementId - Replacement ID
   * @returns {Promise<Object>} Response data
   */
  deleteMeterReplacement: async (replacementId) => {
    try {
      const response = await fetch(`${API_URL}/meter_replacements/${replacementId}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${authService.getToken()}`
        }
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      if (data.success === false || data.status === "error") {
        throw new Error(data.message || 'Failed to delete meter replacement');
      }
      
      return data.data;
    } catch (error) {
      console.error('Error deleting meter replacement:', error);
      throw error;
    }
  },
  
  /**
   * Update meter replacement
   * @param {number} replacementId - Replacement ID
   * @param {Object} replacementData - Updated replacement data
   * @returns {Promise<Object>} Response data
   */
  updateMeterReplacement: async (replacementId, replacementData) => {
    try {
      const response = await fetch(`${API_URL}/meter_replacements/${replacementId}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authService.getToken()}`
        },
        body: JSON.stringify(replacementData)
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      
      const data = await response.json();
      
      if (data.success === false || data.status === "error") {
        throw new Error(data.message || 'Failed to update meter replacement');
      }
      
      return data.data;
    } catch (error) {
      console.error('Error updating meter replacement:', error);
      throw error;
    }
  }
};

/**
 * Fallback function to get meter readings when the API fails
 * @param {string} date - Date in YYYY-MM-DD format
 * @param {number} metricId - Energy metric ID
 * @returns {Promise<Object>} Object with readings data
 */
async function getMeterReadingsFallback(date, metricId) {
  try {
    // Get meters for this metric
    const metersResponse = await fetch(`${API_URL}/meters?metric_id=${metricId}`, {
      headers: {
        'Authorization': `Bearer ${authService.getToken()}`
      }
    });
    
    if (!metersResponse.ok) {
      throw new Error(`HTTP error! Status: ${metersResponse.status}`);
    }
    
    const metersData = await metersResponse.json();
    
    // Get meters and use their coefficient values
    const meters = metersData.data?.meters || [];
    
    // Get shifts
    const shiftsResponse = await fetch(`${API_URL}/shifts`, {
      headers: {
        'Authorization': `Bearer ${authService.getToken()}`
      }
    });
    
    let shifts = [];
    if (shiftsResponse.ok) {
      const shiftsData = await shiftsResponse.json();
      shifts = shiftsData.data?.shifts || [];
    }
    
    // Return empty structure with meters and shifts
    return {
      date: date,
      metric_id: metricId,
      meters: meters,
      shifts: shifts,
      readings: {},
      replacements: {}
    };
  } catch (error) {
    console.error('Error in fallback meter readings:', error);
    return {
      date: date,
      metric_id: metricId,
      meters: [],
      shifts: [],
      readings: {},
      replacements: {}
    };
  }
}

export default energyService;