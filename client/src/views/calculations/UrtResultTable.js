import React, { useState, useEffect, useRef } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import jsPDF from 'jspdf'
import autoTable from 'jspdf-autotable'

export default function UrtResultTable() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const [data, setData] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const tableRef = useRef()

  const date = searchParams.get('date')
  const date_end = searchParams.get('date_end')
  const shift = searchParams.get('shift')
  const mode = searchParams.get('mode')

  useEffect(() => {
    const loadUrtData = async () => {
      try {
        setLoading(true)
        const token = localStorage.getItem('token')
        if (!token) {
          setError('Необходима авторизация')
          return
        }
        let url = `http://exepower/api/urt-data?mode=${mode}`
        if (date) url += `&date=${date}`
        if (date_end) url += `&date_end=${date_end}`
        if (shift) url += `&shift=${shift}`
        const response = await fetch(url, {
          headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
          }
        })
        const result = await response.json()
        if (response.ok && result.success) {
          setData(result.data.data || [])
          setError(null)
        } else if (!response.ok || result.success === false) {
          setError(result.message || 'Ошибка загрузки данных')
        } else {
          setError('Ошибка загрузки данных')
        }
      } catch (err) {
        setError('Ошибка загрузки данных УРТ: ' + err.message)
      } finally {
        setLoading(false)
      }
    }
    loadUrtData()
  }, [date, date_end, shift, mode])

  const handleBack = () => {
    navigate('/calculations/urt')
  }

  const handleExportPDF = () => {
    const doc = new jsPDF({ orientation: 'landscape' })
    doc.setFontSize(16)
    doc.text('Отчет по УРТ (pgu_results)', 14, 16)
    autoTable(doc, {
      head: [[
        'Наименование', 'Тип', 'Краткое имя', 'Ед. изм.', 'Значение', 'Дата', 'Смена'
      ]],
      body: data.map(row => [
        row.name, row.type, row.short_name, row.unit, row.value ?? '', row.date, row.shift_id ?? ''
      ]),
      startY: 24,
      styles: { font: 'times', fontSize: 10 },
      headStyles: { fillColor: [41, 128, 185], textColor: 255, fontStyle: 'bold' },
      alternateRowStyles: { fillColor: [245, 245, 245] },
      margin: { left: 14, right: 14 }
    })
    doc.save('urt_report.pdf')
  }

  if (loading) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '50vh', fontSize: '1.1rem', color: '#666' }}>
        Загрузка данных УРТ...
      </div>
    )
  }

  if (error) {
    return (
      <div style={{ display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', height: '50vh', gap: '1rem' }}>
        <div style={{ color: '#dc3545', fontSize: '1.1rem' }}>{error}</div>
        <button onClick={handleBack} style={{ padding: '0.5rem 1rem', background: '#23272f', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
          Назад
        </button>
      </div>
    )
  }

  return (
    <div style={{ padding: '2rem', maxWidth: 1200, margin: '0 auto' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '2rem' }}>
        <h2 style={{ color: '#23272f', margin: 0 }}>
          Государственный отчет по УРТ (pgu_results)
        </h2>
        <div style={{ display: 'flex', gap: 12 }}>
          <button onClick={handleExportPDF} style={{ padding: '0.5rem 1.2rem', background: '#2980b9', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer', fontWeight: 500 }}>
            Скачать PDF
          </button>
          <button onClick={handleBack} style={{ padding: '0.5rem 1.2rem', background: '#23272f', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer', fontWeight: 500 }}>
            Назад
          </button>
        </div>
      </div>
      <div style={{ background: '#fff', borderRadius: '8px', boxShadow: '0 2px 8px rgba(0,0,0,0.1)', overflow: 'auto', border: '1px solid #e1e4e8' }}>
        <table ref={tableRef} style={{ width: '100%', borderCollapse: 'collapse', fontSize: '1rem', minWidth: 900 }}>
          <thead>
            <tr style={{ background: '#f4f8fb', borderBottom: '2px solid #2980b9' }}>
              <th style={{ padding: '1rem', textAlign: 'left', fontWeight: 700, borderRight: '1px solid #e1e4e8' }}>Наименование</th>
              <th style={{ padding: '1rem', textAlign: 'left', fontWeight: 700, borderRight: '1px solid #e1e4e8' }}>Тип</th>
              <th style={{ padding: '1rem', textAlign: 'left', fontWeight: 700, borderRight: '1px solid #e1e4e8' }}>Краткое имя</th>
              <th style={{ padding: '1rem', textAlign: 'left', fontWeight: 700, borderRight: '1px solid #e1e4e8' }}>Ед. изм.</th>
              <th style={{ padding: '1rem', textAlign: 'right', fontWeight: 700, borderRight: '1px solid #e1e4e8' }}>Значение</th>
              <th style={{ padding: '1rem', textAlign: 'center', fontWeight: 700, borderRight: '1px solid #e1e4e8' }}>Дата</th>
              <th style={{ padding: '1rem', textAlign: 'center', fontWeight: 700 }}>Смена</th>
            </tr>
          </thead>
          <tbody>
            {data.length === 0 ? (
              <tr><td colSpan={7} style={{ textAlign: 'center', padding: '2rem', color: '#888' }}>Нет данных для выбранного периода</td></tr>
            ) : data.map((row, idx) => (
              <tr key={row.id} style={{ background: idx % 2 === 0 ? '#fff' : '#f4f8fb', borderBottom: '1px solid #e1e4e8' }}>
                <td style={{ padding: '0.8rem', fontWeight: 500 }}>{row.name}</td>
                <td style={{ padding: '0.8rem' }}>{row.type}</td>
                <td style={{ padding: '0.8rem' }}>{row.short_name}</td>
                <td style={{ padding: '0.8rem' }}>{row.unit}</td>
                <td style={{ padding: '0.8rem', textAlign: 'right', fontWeight: 600 }}>{row.value ?? ''}</td>
                <td style={{ padding: '0.8rem', textAlign: 'center' }}>{row.date}</td>
                <td style={{ padding: '0.8rem', textAlign: 'center' }}>{row.shift_id ?? ''}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div style={{ marginTop: '2rem', padding: '1rem', background: '#f8f9fa', borderRadius: '8px', fontSize: '1rem', color: '#666', border: '1px solid #e1e4e8' }}>
        <strong>Параметры отчета:</strong> {mode === 'period' && date_end ? `Период: ${date} — ${date_end}` : `Дата: ${date}`} {shift ? `, Смена: ${shift}` : ''}, Режим: {mode}
      </div>
    </div>
  )
} 