import React, { useState, useEffect } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'

export default function UrtResultTable() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const date = searchParams.get('date')
  const shift = searchParams.get('shift')
  const mode = searchParams.get('mode')

  useEffect(() => {
    const loadUrtData = async () => {
      try {
        setLoading(true)
        
        // Get auth token
        const token = localStorage.getItem('token')
        if (!token) {
          setError('Необходима авторизация')
          return
        }
        
        // Call API
        const response = await fetch(`/api/urt-data?date=${date}&shift=${shift}&mode=${mode}`, {
          headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
          }
        })
        
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`)
        }
        
        const result = await response.json()
        
        if (result.success) {
          setData(result.data.data || [])
        } else {
          setError(result.message || 'Ошибка загрузки данных')
        }
      } catch (err) {
        console.error('Error loading URT data:', err)
        setError('Ошибка загрузки данных УРТ: ' + err.message)
      } finally {
        setLoading(false)
      }
    }

    loadUrtData()
  }, [date, shift, mode])

  const handleBack = () => {
    navigate('/calculations/urt')
  }

  if (loading) {
    return (
      <div style={{ 
        display: 'flex', 
        justifyContent: 'center', 
        alignItems: 'center', 
        height: '50vh',
        fontSize: '1.1rem',
        color: '#666'
      }}>
        Загрузка данных УРТ...
      </div>
    )
  }

  if (error) {
    return (
      <div style={{ 
        display: 'flex', 
        flexDirection: 'column',
        justifyContent: 'center', 
        alignItems: 'center', 
        height: '50vh',
        gap: '1rem'
      }}>
        <div style={{ color: '#dc3545', fontSize: '1.1rem' }}>{error}</div>
        <button onClick={handleBack} style={{
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
    )
  }

  return (
    <div style={{ padding: '2rem' }}>
      <div style={{ 
        display: 'flex', 
        justifyContent: 'space-between', 
        alignItems: 'center',
        marginBottom: '2rem'
      }}>
        <h2 style={{ color: '#23272f', margin: 0 }}>
          Результаты анализа УРТ за {date}
        </h2>
        <button onClick={handleBack} style={{
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
        borderRadius: '8px',
        boxShadow: '0 2px 8px rgba(0,0,0,0.1)',
        overflow: 'hidden'
      }}>
        <table style={{
          width: '100%',
          borderCollapse: 'collapse',
          fontSize: '0.9rem'
        }}>
          <thead>
            <tr style={{
              background: '#f8f9fa',
              borderBottom: '1px solid #dee2e6'
            }}>
              <th style={{ padding: '1rem', textAlign: 'left', fontWeight: 600 }}>Оборудование</th>
              <th style={{ padding: '1rem', textAlign: 'center', fontWeight: 600 }}>Время</th>
              <th style={{ padding: '1rem', textAlign: 'right', fontWeight: 600 }}>Мощность (МВт)</th>
              <th style={{ padding: '1rem', textAlign: 'right', fontWeight: 600 }}>Расход топлива (т/ч)</th>
              <th style={{ padding: '1rem', textAlign: 'right', fontWeight: 600 }}>УРТ (г/кВт·ч)</th>
              <th style={{ padding: '1rem', textAlign: 'right', fontWeight: 600 }}>КПД (%)</th>
            </tr>
          </thead>
          <tbody>
            {data.map((row, index) => (
              <tr key={row.id} style={{
                borderBottom: '1px solid #f1f3f4',
                background: index % 2 === 0 ? '#fff' : '#f8f9fa'
              }}>
                <td style={{ padding: '1rem', fontWeight: 500 }}>{row.equipment}</td>
                <td style={{ padding: '1rem', textAlign: 'center' }}>{row.time}</td>
                <td style={{ padding: '1rem', textAlign: 'right' }}>{row.power.toFixed(1)}</td>
                <td style={{ padding: '1rem', textAlign: 'right' }}>{row.fuel_consumption.toFixed(1)}</td>
                <td style={{ padding: '1rem', textAlign: 'right', fontWeight: 600 }}>{row.urt.toFixed(1)}</td>
                <td style={{ padding: '1rem', textAlign: 'right' }}>{row.efficiency.toFixed(1)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div style={{
        marginTop: '2rem',
        padding: '1rem',
        background: '#f8f9fa',
        borderRadius: '8px',
        fontSize: '0.9rem',
        color: '#666'
      }}>
        <strong>Параметры отчета:</strong> Дата: {date}, Смена: {shift}, Режим: {mode}
      </div>
    </div>
  )
} 