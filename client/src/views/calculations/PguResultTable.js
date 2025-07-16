import React, { useState, useEffect } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import authService from '../../services/authService'

export default function PguResultTable() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const date = searchParams.get('date')
  const shift = searchParams.get('shift')
  const mode = searchParams.get('mode')

  useEffect(() => {
    const loadPguData = async () => {
      try {
        setLoading(true)
        const token = authService.getToken()
        if (!token) {
          setError('Необходима авторизация')
          return
        }
        const response = await fetch(`http://exepower/api/pgu-data?date=${date}&shift=${shift}&mode=${mode}`, {
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
        console.error('Error loading PGU data:', err)
        setError('Ошибка загрузки данных ПГУ: ' + err.message)
      } finally {
        setLoading(false)
      }
    }
    loadPguData()
  }, [date, shift, mode])

  const handleBack = () => {
    navigate('/calculations/pgu')
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
        Загрузка данных ПГУ...
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
          Распет ПГУ за {date}
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
              <th style={{ padding: '1rem', textAlign: 'left', fontWeight: 600 }}>Параметр</th>
              <th style={{ padding: '1rem', textAlign: 'left', fontWeight: 600 }}>Тип</th>
              <th style={{ padding: '1rem', textAlign: 'left', fontWeight: 600 }}>Краткое имя</th>
              <th style={{ padding: '1rem', textAlign: 'left', fontWeight: 600 }}>Ед. изм.</th>
              <th style={{ padding: '1rem', textAlign: 'right', fontWeight: 600 }}>ПГУ-1</th>
              <th style={{ padding: '1rem', textAlign: 'right', fontWeight: 600 }}>ПГУ-2</th>
              <th style={{ padding: '1rem', textAlign: 'right', fontWeight: 600 }}>ТЭС</th>
            </tr>
          </thead>
          <tbody>
            {data.map((row, index) => (
              <tr key={row.id} style={{
                borderBottom: '1px solid #f1f3f4',
                background: index % 2 === 0 ? '#fff' : '#f8f9fa'
              }}>
                <td style={{ padding: '1rem' }}>{row.name}</td>
                <td style={{ padding: '1rem' }}>{row.type}</td>
                <td style={{ padding: '1rem' }}>
                  <span dangerouslySetInnerHTML={{ __html: row.short_name }} />
                </td>
                <td style={{ padding: '1rem' }}>{row.unit}</td>
                <td style={{ padding: '1rem', textAlign: 'right' }}>{row.value_pgu1 != null ? row.value_pgu1 : '-'}</td>
                <td style={{ padding: '1rem', textAlign: 'right' }}>{row.value_pgu2 != null ? row.value_pgu2 : '-'}</td>
                <td style={{ padding: '1rem', textAlign: 'right' }}>{row.value_tec != null ? row.value_tec : '-'}</td>
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