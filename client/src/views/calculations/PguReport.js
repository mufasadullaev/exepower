import React, { useState } from 'react'
import DatePicker from 'react-datepicker'
import 'react-datepicker/dist/react-datepicker.css'
import { useNavigate } from 'react-router-dom'

const MODES = [
  { value: 'day', label: 'Сутки' },
  { value: 'period', label: 'За период' },
  { value: 'month', label: 'С начала месяца' },
  { value: 'shift', label: 'По вахтам' },
]

const SHIFTS = [
  { value: 1, label: '1 смена' },
  { value: 2, label: '2 смена' },
  { value: 3, label: '3 смена' },
]

export default function PguReport() {
  const [mode, setMode] = useState('day')
  const [date, setDate] = useState(new Date())
  const [dateRange, setDateRange] = useState([null, null])
  const [shift, setShift] = useState(1)
  const navigate = useNavigate()

  const isPeriod = mode === 'period'
  const isShift = mode === 'shift'
  const isMonth = mode === 'month'

  const handleShow = (e) => {
    e.preventDefault()
    let params = `mode=${mode}`
    if (isPeriod && dateRange[0] && dateRange[1]) {
      const start = dateRange[0]
      const end = dateRange[1]
      const startStr = `${start.getFullYear()}-${String(start.getMonth() + 1).padStart(2, '0')}-${String(start.getDate()).padStart(2, '0')}`
      const endStr = `${end.getFullYear()}-${String(end.getMonth() + 1).padStart(2, '0')}-${String(end.getDate()).padStart(2, '0')}`
      params += `&date=${startStr}&date_end=${endStr}`
    } else if (isMonth) {
      const d = date
      const firstDayStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
      const dateStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
      params += `&date=${firstDayStr}&date_end=${dateStr}`
    } else {
      const d = date
      const dateStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
      params += `&date=${dateStr}`
    }
    // Only add shift if mode is 'shift'
    if (isShift) {
      params += `&shift=${shift}`
    }
    console.log('PGU report params:', params)
    navigate(`/calculations/pgu/result?${params}`)
  }

  return (
    <div style={{
      maxWidth: 520,
      margin: '40px auto',
      background: '#fff',
      borderRadius: 16,
      boxShadow: '0 4px 24px rgba(30,34,40,0.10)',
      padding: '2.5rem 2rem'
    }}>
      <h2 style={{
        textAlign: 'center',
        marginBottom: 24,
        color: '#23272f',
        fontWeight: 600,
        fontSize: '1.2rem'
      }}>
        Распет ПГУ
      </h2>
      <div style={{ marginBottom: 24, textAlign: 'center' }}>
        {isPeriod ? (
          <div style={{ display: 'flex', justifyContent: 'center', gap: 12 }}>
            <DatePicker
              selectsRange
              startDate={dateRange[0]}
              endDate={dateRange[1]}
              onChange={(update) => setDateRange(update)}
              dateFormat="dd.MM.yyyy"
              isClearable={true}
              placeholderText="Выберите период"
              className="form-control"
              inline
            />
          </div>
        ) : (
          <DatePicker
            selected={date}
            onChange={setDate}
            dateFormat="dd.MM.yyyy"
            showMonthDropdown
            showYearDropdown
            dropdownMode="select"
            className="form-control"
            inline
          />
        )}
      </div>
      <div style={{
        background: '#f5f6fa',
        borderRadius: 10,
        padding: '1.2rem 1rem',
        marginBottom: 24,
        boxShadow: '0 1px 4px rgba(30,34,40,0.04)'
      }}>
        <div style={{ fontWeight: 500, marginBottom: 10, color: '#23272f' }}>Отображение:</div>
        <div style={{ display: 'flex', gap: 32, marginBottom: 10 }}>
          <div>
            {MODES.map(m => (
              <label key={m.value} style={{ display: 'block', marginBottom: 6, cursor: 'pointer' }}>
                <input
                  type="radio"
                  name="mode"
                  value={m.value}
                  checked={mode === m.value}
                  onChange={() => setMode(m.value)}
                  style={{ marginRight: 6 }}
                />
                {m.label}
              </label>
            ))}
          </div>
          {isShift && (
            <div>
              <div style={{ fontWeight: 500, marginBottom: 6 }}>Смена:</div>
              {SHIFTS.map(s => (
                <label key={s.value} style={{ display: 'block', marginBottom: 6, cursor: 'pointer' }}>
                  <input
                    type="radio"
                    name="shift"
                    value={s.value}
                    checked={shift === s.value}
                    onChange={() => setShift(s.value)}
                    style={{ marginRight: 6 }}
                  />
                  {s.label}
                </label>
              ))}
            </div>
          )}
        </div>
      </div>
      <div style={{ color: '#4b5563', fontSize: '0.98rem', marginBottom: 24, textAlign: 'center' }}>
        Для анализа ПГУ выберите дату или период, форму отображения и нажмите кнопку «Отобразить»
      </div>
      <div style={{ display: 'flex', gap: 16, justifyContent: 'center' }}>
        <button
          style={{
            padding: '0.7rem 2.2rem',
            borderRadius: 8,
            background: '#23272f',
            color: '#fff',
            border: 'none',
            fontWeight: 500,
            fontSize: '1.08rem',
            cursor: 'pointer',
            transition: 'background 0.15s'
          }}
          onClick={handleShow}
        >
          Отобразить
        </button>
      </div>
    </div>
  )
} 