// URT Analysis service for interacting with the API

import { API_BASE_URL } from '../config/api';

const API_URL = API_BASE_URL;

/**
 * Получение параметров для анализа УРТ
 */
export const getUrtAnalysisParams = async () => {
  try {
    const response = await fetch(`${API_URL}/urt-analysis-params`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${localStorage.getItem('token')}`
      }
    });
    
    const data = await response.json();
    
    if (!response.ok) {
      throw new Error(data.message || 'Ошибка при получении параметров УРТ');
    }
    
    return data.data;
  } catch (error) {
    console.error('Ошибка при получении параметров УРТ:', error);
    throw error;
  }
};

/**
 * Получение значений анализа УРТ
 */
export const getUrtAnalysisValues = async (params) => {
  try {
    const queryParams = new URLSearchParams(params);
    const response = await fetch(`${API_URL}/urt-analysis-values?${queryParams}`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${localStorage.getItem('token')}`
      }
    });
    
    const data = await response.json();
    
    if (!response.ok) {
      throw new Error(data.message || 'Ошибка при получении значений УРТ');
    }
    
    return data.data;
  } catch (error) {
    console.error('Ошибка при получении значений УРТ:', error);
    throw error;
  }
};

/**
 * Сохранение значений анализа УРТ
 */
export const saveUrtAnalysisValues = async (values) => {
  try {
    const response = await fetch(`${API_URL}/urt-analysis-values`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${localStorage.getItem('token')}`
      },
      body: JSON.stringify(values)
    });
    
    const data = await response.json();
    
    if (!response.ok) {
      throw new Error(data.message || 'Ошибка при сохранении значений УРТ');
    }
    
    return data.data;
  } catch (error) {
    console.error('Ошибка при сохранении значений УРТ:', error);
    throw error;
  }
};

/**
 * Выполнение расчета анализа УРТ
 */
export const performUrtAnalysisCalculation = async (calculationData) => {
  try {
    const response = await fetch(`${API_URL}/urt-analysis/perform`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${localStorage.getItem('token')}`
      },
      body: JSON.stringify(calculationData)
    });
    
    const data = await response.json();
    
    if (!response.ok) {
      throw new Error(data.message || 'Ошибка при выполнении расчета УРТ');
    }
    
    return data.data;
  } catch (error) {
    console.error('Ошибка при выполнении расчета УРТ:', error);
    throw error;
  }
};

export default {
  getUrtAnalysisParams,
  getUrtAnalysisValues,
  saveUrtAnalysisValues,
  performUrtAnalysisCalculation
};















