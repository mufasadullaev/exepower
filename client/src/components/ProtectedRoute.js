import React, { useEffect, useState } from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { CSpinner } from '@coreui/react';
import authService from '../services/authService';

/**
 * ProtectedRoute component to handle authentication checks
 * Redirects to login if user is not authenticated
 */
const ProtectedRoute = () => {
  const [isAuthenticated, setIsAuthenticated] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  
  useEffect(() => {
    const verifyAuth = async () => {
      try {
        // First check if we have a token
        if (!authService.isAuthenticated()) {
          setIsAuthenticated(false);
          setIsLoading(false);
          return;
        }
        
        // Then verify the token with the API
        const isValid = await authService.verifyToken();
        setIsAuthenticated(isValid);
      } catch (error) {
        console.error('Authentication verification error:', error);
        setIsAuthenticated(false);
      } finally {
        setIsLoading(false);
      }
    };
    
    verifyAuth();
  }, []);
  
  if (isLoading) {
    return (
      <div className="d-flex justify-content-center align-items-center vh-100">
        <CSpinner color="primary" />
      </div>
    );
  }
  
  return isAuthenticated ? <Outlet /> : <Navigate to="/login" />;
};

export default ProtectedRoute; 