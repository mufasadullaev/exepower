import React, { useState, useEffect } from 'react'
import {
  CCard,
  CCardBody,
  CCardHeader,
  CTable,
  CTableHead,
  CTableRow,
  CTableHeaderCell,
  CTableBody,
  CTableDataCell,
  CRow,
  CCol,
  CButton,
  CFormSelect
} from '@coreui/react'

const ShiftSchedule = () => {
  // Состояние компонента
  const [selectedMonth, setSelectedMonth] = useState(new Date().getMonth())
  const [selectedYear, setSelectedYear] = useState(new Date().getFullYear())
  const [scheduleData, setScheduleData] = useState([])
  const [displayMonth, setDisplayMonth] = useState(selectedMonth)
  const [displayYear, setDisplayYear] = useState(selectedYear)
  
  // Константы
  const MONTHS = [
    'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
    'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'
  ]
  
  const YEARS = Array.from({ length: 10 }, (_, i) => new Date().getFullYear() - 2 + i)
  
  // Функция для генерации данных графика на выбранный месяц и год
  const generateScheduleData = () => {
    // Обновляем отображаемый месяц и год при применении
    setDisplayMonth(selectedMonth)
    setDisplayYear(selectedYear)
    
    const daysInMonth = new Date(selectedYear, selectedMonth + 1, 0).getDate()
    const newScheduleData = []
    
    // Для каждой вахты
    for (let shiftNumber = 1; shiftNumber <= 4; shiftNumber++) {
      const shiftData = {
        shiftNumber,
        days: [],
        workingDays: 0,
        workingHours: 0
      }
      
      // Для каждого дня в месяце
      for (let day = 1; day <= daysInMonth; day++) {
        const date = new Date(selectedYear, selectedMonth, day)
        const shiftValue = getShiftValueForDate(date, shiftNumber)
        
        shiftData.days.push({
          day,
          value: shiftValue
        })
        
        // Если не выходной, увеличиваем счетчик рабочих дней
        if (shiftValue !== 'B') {
          shiftData.workingDays++
        }
      }
      
      // Рассчитываем рабочие часы (8 часов на рабочий день)
      shiftData.workingHours = shiftData.workingDays * 8
      
      newScheduleData.push(shiftData)
    }
    
    setScheduleData(newScheduleData)
  }
  
  // Функция для определения смены на конкретный день
  const getShiftValueForDate = (date, shiftNumber) => {
    // Используем фиксированные данные для апреля 2025 года как эталон
    const april2025 = [
      // Вахта 1 (апрель 2025)
      ['3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2'],
      // Вахта 2 (апрель 2025)
      ['1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3'],
      // Вахта 3 (апрель 2025)
      ['2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1'],
      // Вахта 4 (апрель 2025)
      ['B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B', '3', '3', 'B', '1', '1', '2', '2', 'B']
    ];
    
    // Определяем паттерны на основе эталонных данных
    // Каждый паттерн имеет длину 8 дней и повторяется
    const patterns = [
      ['3', '3', 'B', '1', '1', '2', '2', 'B'], // Вахта 1
      ['1', '2', '2', 'B', '3', '3', 'B', '1'], // Вахта 2
      ['2', 'B', '3', '3', 'B', '1', '1', '2'], // Вахта 3
      ['B', '1', '1', '2', '2', 'B', '3', '3']  // Вахта 4
    ];
    
    // Опорная дата - 1 апреля 2025 года
    const referenceDate = new Date(2025, 3, 1); // Апрель = 3 (месяцы начинаются с 0)
    
    // Вычисляем разницу в днях между текущей датой и опорной
    const timeDiff = date.getTime() - referenceDate.getTime();
    const daysDiff = Math.floor(timeDiff / (1000 * 3600 * 24));
    
    // Вычисляем индекс в паттерне с учетом смещения
    // Добавляем длину паттерна и берем остаток, чтобы избежать отрицательных индексов
    const patternIndex = ((daysDiff % 8) + 8) % 8;
    
    return patterns[shiftNumber - 1][patternIndex];
  }
  
  // Генерируем данные графика при первом рендере
  useEffect(() => {
    generateScheduleData()
  }, []) // Пустой массив зависимостей - выполняется только при монтировании
  
  // Отдельный эффект для проверки данных апреля 2025
  useEffect(() => {
    // Проверка для апреля 2025 года
    if (displayMonth === 3 && displayYear === 2025 && scheduleData.length > 0) {
      console.log('Проверка графика для апреля 2025:');
      const expectedWorkingDays = [23, 23, 22, 22]; // Ожидаемое количество рабочих дней
      const expectedWorkingHours = [184, 184, 176, 176]; // Ожидаемое количество рабочих часов
      
      const actualWorkingDays = scheduleData.map(sd => sd.workingDays);
      const actualWorkingHours = scheduleData.map(sd => sd.workingHours);
      
      console.log('Ожидаемые рабочие дни:', expectedWorkingDays);
      console.log('Фактические рабочие дни:', actualWorkingDays);
      console.log('Ожидаемые рабочие часы:', expectedWorkingHours);
      console.log('Фактические рабочие часы:', actualWorkingHours);
      
      const daysMatch = JSON.stringify(expectedWorkingDays) === JSON.stringify(actualWorkingDays);
      const hoursMatch = JSON.stringify(expectedWorkingHours) === JSON.stringify(actualWorkingHours);
      
      console.log('Рабочие дни совпадают:', daysMatch);
      console.log('Рабочие часы совпадают:', hoursMatch);
    }
  }, [displayMonth, displayYear, scheduleData])
  
  // Получение цвета ячейки в зависимости от значения смены
  const getShiftCellColor = (shiftValue) => {
    switch (shiftValue) {
      case '1':
        return 'bg-success bg-opacity-25'
      case '2':
        return 'bg-warning bg-opacity-25'
      case '3':
        return 'bg-danger bg-opacity-25'
      case 'B':
        return 'bg-secondary bg-opacity-25'
      default:
        return ''
    }
  }
  
  // Получение количества дней в отображаемом месяце
  const getDaysInDisplayMonth = () => {
    return new Date(displayYear, displayMonth + 1, 0).getDate();
  }
  
  return (
    <CCard>
      <CCardHeader>
        <h4>График вахт</h4>
      </CCardHeader>
      <CCardBody>
        <CRow className="mb-3">
          <CCol md={3}>
            <CFormSelect
              value={selectedMonth}
              onChange={(e) => setSelectedMonth(parseInt(e.target.value))}
              label="Месяц"
            >
              {MONTHS.map((month, index) => (
                <option key={index} value={index}>
                  {month}
                </option>
              ))}
            </CFormSelect>
          </CCol>
          <CCol md={3}>
            <CFormSelect
              value={selectedYear}
              onChange={(e) => setSelectedYear(parseInt(e.target.value))}
              label="Год"
            >
              {YEARS.map((year) => (
                <option key={year} value={year}>
                  {year}
                </option>
              ))}
            </CFormSelect>
          </CCol>
          <CCol md={3} className="d-flex align-items-end">
            <CButton color="primary" onClick={generateScheduleData}>
              Применить
            </CButton>
          </CCol>
        </CRow>
        
        <CTable bordered small responsive>
          <CTableHead>
            <CTableRow>
              <CTableHeaderCell>Вахта</CTableHeaderCell>
              {/* Генерируем заголовки для каждого дня месяца на основе отображаемого месяца */}
              {Array.from({ length: getDaysInDisplayMonth() }, (_, i) => (
                <CTableHeaderCell key={i + 1} className="text-center">
                  {i + 1}
                </CTableHeaderCell>
              ))}
              <CTableHeaderCell className="text-center">Рабочие дни</CTableHeaderCell>
              <CTableHeaderCell className="text-center">Часы</CTableHeaderCell>
            </CTableRow>
          </CTableHead>
          <CTableBody>
            {scheduleData.map((shiftData) => (
              <CTableRow key={shiftData.shiftNumber}>
                <CTableHeaderCell>Вахта №{shiftData.shiftNumber}</CTableHeaderCell>
                {/* Ячейки для каждого дня месяца */}
                {shiftData.days.map((day) => (
                  <CTableDataCell 
                    key={day.day} 
                    className={`text-center ${getShiftCellColor(day.value)}`}
                  >
                    {day.value}
                  </CTableDataCell>
                ))}
                <CTableDataCell className="text-center fw-bold">
                  {shiftData.workingDays}
                </CTableDataCell>
                <CTableDataCell className="text-center fw-bold">
                  {shiftData.workingHours}
                </CTableDataCell>
              </CTableRow>
            ))}
          </CTableBody>
        </CTable>
        
        <div className="mt-3">
          <h5>Условные обозначения:</h5>
          <div className="d-flex gap-3">
            <div>
              <span className="badge bg-success bg-opacity-25 text-success">1</span> - Первая смена
            </div>
            <div>
              <span className="badge bg-warning bg-opacity-25 text-warning">2</span> - Вторая смена
            </div>
            <div>
              <span className="badge bg-danger bg-opacity-25 text-danger">3</span> - Третья смена
            </div>
            <div>
              <span className="badge bg-secondary bg-opacity-25 text-secondary">B</span> - Выходной
            </div>
          </div>
        </div>
      </CCardBody>
    </CCard>
  )
}

export default ShiftSchedule 