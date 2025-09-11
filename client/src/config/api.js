// Централизованная конфигурация API
export const API_BASE_URL = process.env.NODE_ENV === 'production' 
  ? 'http://exepower/api' 
  : 'http://localhost:8001';

export default API_BASE_URL; 