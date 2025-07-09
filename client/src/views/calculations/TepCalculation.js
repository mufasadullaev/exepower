import React from 'react'
import { useNavigate } from 'react-router-dom'

export default function TepCalculation() {
  const navigate = useNavigate()

  return (
    <div style={{ padding: '2rem' }}>
      <div style={{ 
        display: 'flex', 
        justifyContent: 'space-between', 
        alignItems: 'center',
        marginBottom: '2rem'
      }}>
        <h2 style={{ color: '#23272f', margin: 0 }}>Расчет ТЕП</h2>
        <button onClick={() => navigate('/calculations')} style={{
          padding: '0.5rem 1rem',
          background: '#23272f',
          color: '#fff',
          border: 'none',
          borderRadius: '4px',
          cursor: 'pointer'
        }}>
          Назад
        </button>
      </div>
      
      <div style={{
        background: '#fff',
        padding: '2rem',
        borderRadius: '8px',
        boxShadow: '0 2px 8px rgba(0,0,0,0.1)',
        textAlign: 'center',
        color: '#666'
      }}>
        <p>Функция расчета ТЕП находится в разработке.</p>
        <p>Здесь будет интерфейс для расчета технико-экономических показателей.</p>
      </div>
    </div>
  )
} 