import React from 'react'
import { useNavigate } from 'react-router-dom'

const buttonStyle = {
  padding: '1.1rem 2.2rem',
  fontSize: '1.1rem',
  borderRadius: '0.7rem',
  background: '#23272f',
  color: '#f5f6fa',
  border: '1px solid #444851',
  boxShadow: '0 2px 8px rgba(30,34,40,0.08)',
  fontWeight: 500,
  letterSpacing: '0.03em',
  cursor: 'pointer',
  transition: 'background 0.15s, color 0.15s, box-shadow 0.15s',
  outline: 'none',
}
const buttonHover = {
  background: '#353a42',
  color: '#fff',
  boxShadow: '0 4px 16px rgba(30,34,40,0.13)',
}

const Calculations = () => {
  const navigate = useNavigate();
  const [hovered, setHovered] = React.useState(null);
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '2.5rem', marginTop: '3rem' }}>
      <h1 style={{ color: '#23272f', fontWeight: 600, fontSize: '2.1rem', marginBottom: '1.5rem' }}>Расчеты</h1>
      <div style={{ display: 'flex', gap: '2rem' }}>
        <button
          style={hovered === 1 ? { ...buttonStyle, ...buttonHover } : buttonStyle}
          onMouseEnter={() => setHovered(1)}
          onMouseLeave={() => setHovered(null)}
          onClick={() => navigate('/calculations/tep')}
        >
          Расчет ТЕП
        </button>
        <button
          style={hovered === 2 ? { ...buttonStyle, ...buttonHover } : buttonStyle}
          onMouseEnter={() => setHovered(2)}
          onMouseLeave={() => setHovered(null)}
          onClick={() => navigate('/calculations/urt')}
        >
          Отчет по УРТ
        </button>
        <button
          style={hovered === 3 ? { ...buttonStyle, ...buttonHover } : buttonStyle}
          onMouseEnter={() => setHovered(3)}
          onMouseLeave={() => setHovered(null)}
          onClick={() => navigate('/calculations/raport')}
        >
          Рапорт
        </button>
      </div>
    </div>
  )
}

export default Calculations 