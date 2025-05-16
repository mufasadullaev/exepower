// Authentication service for interacting with the API

// Используем абсолютный URL для API
const API_URL = 'http://exepower/api';

// Helper function to safely access localStorage
const safeLocalStorage = {
  setItem: (key, value) => {
    try {
      localStorage.setItem(key, value);
      return true;
    } catch (error) {
      console.error('Error saving to localStorage:', error);
      return false;
    }
  },
  getItem: (key) => {
    try {
      return localStorage.getItem(key);
    } catch (error) {
      console.error('Error reading from localStorage:', error);
      return null;
    }
  },
  removeItem: (key) => {
    try {
      localStorage.removeItem(key);
      return true;
    } catch (error) {
      console.error('Error removing from localStorage:', error);
      return false;
    }
  }
};

export const authService = {
  /**
   * Authenticate a user with password only (legacy method)
   * @param {string} password - The password to verify
   * @returns {Promise<boolean>} - Promise resolving to authentication result
   */
  login: async (password) => {
    try {
      console.log('Sending login request to:', API_URL + '/auth/login');
      const response = await fetch(`${API_URL}/auth/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ password })
      });
      
      const data = await response.json();
      console.log('Login response:', data);
      
      if (!response.ok) {
        throw new Error(data.message || 'Login failed');
      }
      
      // Store token in localStorage
      safeLocalStorage.setItem('token', data.data.token);
      safeLocalStorage.setItem('user', JSON.stringify(data.data.user));
      
      return true;
    } catch (error) {
      console.error('Login error:', error);
      return false;
    }
  },

  /**
   * Authenticate a user with username and password
   * @param {string} username - The username
   * @param {string} password - The password
   * @returns {Promise<boolean>} - Promise resolving to authentication result
   */
  loginWithUsername: async (username, password) => {
    try {
      console.log('Sending login request with username to:', API_URL + '/auth/login');
      const response = await fetch(`${API_URL}/auth/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ username, password })
      });
      
      const data = await response.json();
      console.log('Login response:', data);
      
      if (!response.ok) {
        throw new Error(data.message || 'Login failed');
      }
      
      // Store token in localStorage
      safeLocalStorage.setItem('token', data.data.token);
      safeLocalStorage.setItem('user', JSON.stringify(data.data.user));
      
      return true;
    } catch (error) {
      console.error('Login error:', error);
      return false;
    }
  },

  /**
   * Check if user is authenticated
   * @returns {boolean} - Authentication status
   */
  isAuthenticated: () => {
    const token = safeLocalStorage.getItem('token');
    return !!token;
  },

  /**
   * Get the current user
   * @returns {Object|null} - User object or null
   */
  getUser: () => {
    const userJson = safeLocalStorage.getItem('user');
    if (!userJson) return null;
    
    try {
      return JSON.parse(userJson);
    } catch (error) {
      console.error('Error parsing user data:', error);
      return null;
    }
  },

  /**
   * Get the authentication token
   * @returns {string|null} - Token or null
   */
  getToken: () => {
    return safeLocalStorage.getItem('token');
  },

  /**
   * Verify token with the server
   * @returns {Promise<boolean>} - Promise resolving to verification result
   */
  verifyToken: async () => {
    const token = safeLocalStorage.getItem('token');
    if (!token) return false;
    
    try {
      console.log('Verifying token at:', API_URL + '/auth/verify');
      const response = await fetch(`${API_URL}/auth/verify`, {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      
      return response.ok;
    } catch (error) {
      console.error('Token verification error:', error);
      return false;
    }
  },

  /**
   * Log out the current user
   */
  logout: () => {
    safeLocalStorage.removeItem('token');
    safeLocalStorage.removeItem('user');
  }
};

export default authService;