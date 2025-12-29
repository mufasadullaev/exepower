import React, { useState, useEffect } from 'react'
import {
  CCard,
  CCardBody,
  CCardHeader,
  CCol,
  CRow,
  CFormCheck,
  CForm,
  CFormLabel,
  CButton,
  CInputGroup,
  CInputGroupText,
  CBadge,
  CNav,
  CNavItem,
  CNavLink,
  CTabContent,
  CTabPane,
  CSpinner
} from '@coreui/react'
import { useNavigate } from 'react-router-dom'
import DatePicker, { registerLocale } from 'react-datepicker'
import ru from 'date-fns/locale/ru'
import 'react-datepicker/dist/react-datepicker.css'
import { calculateActiveVakhtas } from '../../services/calculationsService'
import { pguCalculationService } from '../../services/pguCalculationService'
import blocksCalculationService from '../../services/blocksCalculationService'
import urtAnalysisService from '../../services/urtAnalysisService'
import './Calculations.scss'

// Регистрируем русскую локаль
registerLocale('ru', ru)

const Calculations = () => {
  const navigate = useNavigate()
  
  // Состояние для типа периода
  const [periodType, setPeriodType] = useState('shift') // 'shift', 'day' или 'period'
  
  // Состояние для дат
  const [selectedDate, setSelectedDate] = useState(new Date())
  const [startDate, setStartDate] = useState(new Date())
  const [endDate, setEndDate] = useState(new Date())
  
  // Состояние для выбора смен (для типа "Смена") - все выбраны по умолчанию
  const [selectedShifts, setSelectedShifts] = useState({
    shift1: true,
    shift2: true,
    shift3: true
  })

  // Состояние для выбора вахт (для типа "Период") - все выбраны по умолчанию
  const [selectedVakhtas, setSelectedVakhtas] = useState({
    vahta1: true,
    vahta2: true,
    vahta3: true,
    vahta4: true
  })

  // Состояние для работающих вахт
  const [activeVakhtas, setActiveVakhtas] = useState([])

  // Состояние для активного таба
  const [activeTab, setActiveTab] = useState('blocks')
  
  // Состояние для загрузки
  const [loading, setLoading] = useState(false)

  // Обработчик изменения типа периода
  const handlePeriodTypeChange = (type) => {
    setPeriodType(type)
  }

  // Обработчик изменения смен
  const handleShiftChange = (shiftKey) => {
    setSelectedShifts(prev => ({
      ...prev,
      [shiftKey]: !prev[shiftKey]
    }))
  }

  // Обработчик изменения вахт
  const handleVakhtaChange = (vakhtaKey) => {
    setSelectedVakhtas(prev => ({
      ...prev,
      [vakhtaKey]: !prev[vakhtaKey]
    }))
  }

  // Выбрать все смены
  const selectAllShifts = () => {
    if (periodType === 'shift') {
      setSelectedShifts({
        shift1: true,
        shift2: true,
        shift3: true
      })
    } else {
      setSelectedVakhtas({
        vahta1: true,
        vahta2: true,
        vahta3: true,
        vahta4: true
      })
    }
  }

  // Снять выбор всех смен
  const deselectAllShifts = () => {
    if (periodType === 'shift') {
      setSelectedShifts({
        shift1: false,
        shift2: false,
        shift3: false
      })
    } else {
      setSelectedVakhtas({
        vahta1: false,
        vahta2: false,
        vahta3: false,
        vahta4: false
      })
    }
  }

  // Обновление работающих вахт при изменении даты
  useEffect(() => {
    if (periodType === 'shift') {
      const vakhtas = calculateActiveVakhtas(selectedDate)
      setActiveVakhtas(vakhtas)
    }
  }, [selectedDate, periodType])

  // Обработчик запуска расчетов
  const handleCalculate = async () => {
    // Для типа "Сутки" не требуется выбор вахт
    let selectedItemsList = []
    if (periodType !== 'day') {
      if (periodType === 'shift') {
        selectedItemsList = Object.entries(selectedShifts)
          .filter(([_, selected]) => selected)
          .map(([shift, _]) => shift)
      } else {
        selectedItemsList = Object.entries(selectedVakhtas)
          .filter(([_, selected]) => selected)
          .map(([vakhta, _]) => vakhta)
      }

      if (selectedItemsList.length === 0) {
        const message = periodType === 'shift' ? 'Выберите хотя бы одну смену' : 'Выберите хотя бы одну вахту'
        alert(message)
        return
      }
    }

    const calculationData = {
      periodType,
        dates: periodType === 'period' 
          ? {
            startDate: startDate.toLocaleDateString('en-CA'), 
            endDate: endDate.toLocaleDateString('en-CA') 
          }
        : { selectedDate: selectedDate.toLocaleDateString('en-CA') },
      shifts: selectedItemsList,
      // Добавляем информацию о работающих вахтах для типа "Смена"
      activeVakhtas: periodType === 'shift' ? activeVakhtas : null,
      // Добавляем выбранный таб как фильтр типа оборудования
      equipmentType: activeTab === 'blocks' ? 'blocks' : activeTab === 'pgu' ? 'pgu' : 'urt'
    }

    console.log('Запуск расчетов с параметрами:', calculationData)
    console.log('Даты:', {
      selectedDate: selectedDate.toString(),
      selectedDateLocal: selectedDate.toLocaleDateString('en-CA'),
      selectedDateUTC: selectedDate.toISOString().split('T')[0],
      currentTime: new Date().toString()
    })
    
    // Если выбран таб Блоки
    if (activeTab === 'blocks') {
      try {
        setLoading(true)
        const result = await blocksCalculationService.performFullCalculation(calculationData)
        const date = periodType === 'period' 
          ? `${startDate.toLocaleDateString('en-CA')} - ${endDate.toLocaleDateString('en-CA')}`
          : selectedDate.toLocaleDateString('en-CA')
        const shifts = selectedItemsList.join(', ')
        navigate('/blocks-results', {
          state: { date, periodType, shifts, calculationData, calculationResult: result }
        })
      } catch (error) {
        console.error('Ошибка при выполнении расчета блоков:', error)
        const errorMessage = error.message || 'Неизвестная ошибка'
        alert('Ошибка при выполнении расчета блоков: ' + errorMessage)
      } finally {
        setLoading(false)
      }
      return
    }
    
    // Если выбран таб ПГУ, выполняем расчет
    if (activeTab === 'pgu') {
      try {
        setLoading(true)
        
        // Выполняем расчет
        const result = await pguCalculationService.performFullCalculation(calculationData)
        
        console.log('Результат расчета:', result)
        
        // Перенаправляем на страницу результатов
        const date = periodType === 'period' 
          ? `${startDate.toLocaleDateString('en-CA')} - ${endDate.toLocaleDateString('en-CA')}`
          : selectedDate.toLocaleDateString('en-CA')
        
        const shifts = selectedItemsList.join(', ')
        
        navigate('/pgu-results', {
          state: {
            date,
            periodType,
            shifts,
            calculationData,
            calculationResult: result
          }
        })
        
      } catch (error) {
        console.error('Ошибка при выполнении расчета:', error)
        alert('Ошибка при выполнении расчета: ' + error.message)
      } finally {
        setLoading(false)
      }
      return
    }
    
    // Если выбран таб УРТ, выполняем расчет анализа УРТ
    if (activeTab === 'urt') {
      try {
        setLoading(true)
        
        // Выполняем расчет анализа УРТ
        const result = await urtAnalysisService.performUrtAnalysisCalculation(calculationData)
        
        const date = periodType === 'period' 
          ? `${startDate.toLocaleDateString('en-CA')} - ${endDate.toLocaleDateString('en-CA')}`
          : selectedDate.toLocaleDateString('en-CA')
        const shifts = selectedItemsList.join(', ')
        
        navigate('/urt-analysis', {
          state: { 
            date, 
            periodType, 
            shifts, 
            calculationData, 
            calculationResult: result 
          }
        })
        
      } catch (error) {
        console.error('Ошибка при выполнении расчета УРТ:', error)
        alert('Ошибка при выполнении расчета УРТ: ' + error.message)
      } finally {
        setLoading(false)
      }
      return
    }
    
    // Здесь будет логика отправки данных в API для других табов
  }

  return (
    <CRow>
      <CCol xs={12}>
        <CCard>
          <CCardHeader>
            <strong>Расчеты</strong>
          </CCardHeader>
          <CCardBody>
            {/* Табы */}
            <CNav variant="tabs" className="mb-4">
              <CNavItem>
                <CNavLink
                  active={activeTab === 'blocks'}
                  onClick={() => setActiveTab('blocks')}
                  style={{ cursor: 'pointer' }}
                >
                  Блоки
                </CNavLink>
              </CNavItem>
              <CNavItem>
                <CNavLink
                  active={activeTab === 'pgu'}
                  onClick={() => setActiveTab('pgu')}
                  style={{ cursor: 'pointer' }}
                >
                  ПГУ
                </CNavLink>
              </CNavItem>
              <CNavItem>
                <CNavLink
                  active={activeTab === 'urt'}
                  onClick={() => setActiveTab('urt')}
                  style={{ cursor: 'pointer' }}
                >
                  Анализ УРТ
                </CNavLink>
              </CNavItem>
            </CNav>

            {/* Контент табов */}
            <CTabContent>
              {/* Таб "Блоки" */}
              <CTabPane visible={activeTab === 'blocks'}>
                <CForm>
                             {/* Выбор типа периода */}
               <CRow className="mb-4">
                 <CCol md={8}>
                   <CFormLabel className="fw-bold mb-3">Выберите период расчета:</CFormLabel>
                   <div className="d-flex gap-4">
                     <CFormCheck
                       type="radio"
                       name="periodType"
                       id="periodShift"
                       label="Смена"
                       checked={periodType === 'shift'}
                       onChange={() => handlePeriodTypeChange('shift')}
                     />
                     <CFormCheck
                       type="radio"
                       name="periodType"
                       id="periodDay"
                       label="Сутки"
                       checked={periodType === 'day'}
                       onChange={() => handlePeriodTypeChange('day')}
                     />
                     <CFormCheck
                       type="radio"
                       name="periodType"
                       id="periodRange"
                       label="Период"
                       checked={periodType === 'period'}
                       onChange={() => handlePeriodTypeChange('period')}
                     />
                   </div>
                 </CCol>
               </CRow>

              {/* Календарь */}
              <CRow className="mb-4">
                <CCol md={8}>
                                     <CFormLabel className="fw-bold mb-3">
                     {periodType === 'period' ? 'Выберите период:' : 'Выберите дату:'}
                   </CFormLabel>
                   
                                      {periodType === 'period' ? (
                    <CRow>
                      <CCol md={6}>
                        <CInputGroup className="mb-2">
                          <CInputGroupText>С</CInputGroupText>
                          <DatePicker
                            selected={startDate}
                            onChange={setStartDate}
                            locale="ru"
                            dateFormat="dd.MM.yyyy"
                            className="form-control"
                            maxDate={endDate || new Date()}
                            showYearDropdown
                            showMonthDropdown
                            dropdownMode="select"
                            placeholderText="Дата начала"
                          />
                        </CInputGroup>
                      </CCol>
                      <CCol md={6}>
                        <CInputGroup>
                          <CInputGroupText>По</CInputGroupText>
                          <DatePicker
                            selected={endDate}
                            onChange={setEndDate}
                            locale="ru"
                            dateFormat="dd.MM.yyyy"
                            className="form-control"
                            minDate={startDate}
                            maxDate={new Date()}
                            showYearDropdown
                            showMonthDropdown
                            dropdownMode="select"
                            placeholderText="Дата окончания"
                          />
                        </CInputGroup>
                      </CCol>
                    </CRow>
                  ) : (
                    <div className="date-picker-container">
                      <DatePicker
                        selected={selectedDate}
                        onChange={setSelectedDate}
                        locale="ru"
                        dateFormat="dd.MM.yyyy"
                        className="form-control"
                        maxDate={new Date()}
                        showYearDropdown
                        showMonthDropdown
                        dropdownMode="select"
                        placeholderText="Выберите дату"
                      />
                    </div>
                  )}
                </CCol>
              </CRow>

                                            {/* Выбор смен - показываем только для смены и периода */}
               {periodType !== 'day' && (
                 <CRow className="mb-4">
                   <CCol md={8}>
                     <CFormLabel className="fw-bold mb-3">
                       {periodType === 'shift' ? 'Выберите смены:' : 'Выберите вахты:'}
                     </CFormLabel>
                     
                     {/* Кнопки выбора всех/ни одной */}
                     <div className="mb-3">
                       <CButton 
                         color="outline-primary" 
                         size="sm" 
                         className="me-2"
                         onClick={selectAllShifts}
                       >
                         Выбрать все
                       </CButton>
                       <CButton 
                         color="outline-secondary" 
                         size="sm"
                         onClick={deselectAllShifts}
                       >
                         Снять все
                       </CButton>
                     </div>

                     {/* Чекбоксы смен или вахт */}
                     <div className="shifts-container">
                       {periodType === 'shift' ? (
                         // Отображение смен с работающими вахтами
                         activeVakhtas.map((shift) => (
                           <div key={shift.shiftNumber} className="mb-3">
                             <CFormCheck
                               id={`shift${shift.shiftNumber}`}
                               label={
                                 <div className="d-flex align-items-center">
                                   <span className="me-2">{shift.shiftName}</span>
                                   <CBadge color="info" className="ms-2">
                                     {shift.activeVahta}
                                   </CBadge>
                                 </div>
                               }
                               checked={selectedShifts[`shift${shift.shiftNumber}`]}
                               onChange={() => handleShiftChange(`shift${shift.shiftNumber}`)}
                             />
                           </div>
                         ))
                                                ) : (
                         // Отображение вахт для периода
                         <>
                           <CFormCheck
                             id="vahta1"
                             label="Вахта №1"
                             checked={selectedVakhtas.vahta1}
                             onChange={() => handleVakhtaChange('vahta1')}
                             className="mb-2"
                           />
                           <CFormCheck
                             id="vahta2"
                             label="Вахта №2"
                             checked={selectedVakhtas.vahta2}
                             onChange={() => handleVakhtaChange('vahta2')}
                             className="mb-2"
                           />
                           <CFormCheck
                             id="vahta3"
                             label="Вахта №3"
                             checked={selectedVakhtas.vahta3}
                             onChange={() => handleVakhtaChange('vahta3')}
                             className="mb-2"
                           />
                           <CFormCheck
                             id="vahta4"
                             label="Вахта №4"
                             checked={selectedVakhtas.vahta4}
                             onChange={() => handleVakhtaChange('vahta4')}
                             className="mb-2"
                           />
                         </>
                       )}
                     </div>
                   </CCol>
                 </CRow>
               )}

              {/* Кнопка запуска расчетов */}
              <CRow>
                <CCol md={6}>
                  <CButton 
                    color="primary" 
                    size="lg"
                    onClick={handleCalculate}
                    className="px-4"
                    disabled={loading}
                  >
                    {loading ? (
                      <>
                        <CSpinner size="sm" className="me-2" />
                        Выполняется расчет...
                      </>
                    ) : (
                      'Выполнить расчеты'
                    )}
                  </CButton>
                </CCol>
              </CRow>
                </CForm>
              </CTabPane>

              {/* Таб "ПГУ" */}
              <CTabPane visible={activeTab === 'pgu'}>
                <CForm>
                  {/* Выбор типа периода */}
                  <CRow className="mb-4">
                    <CCol md={8}>
                      <CFormLabel className="fw-bold mb-3">Выберите период расчета:</CFormLabel>
                      <div className="d-flex gap-4">
                        <CFormCheck
                          type="radio"
                          name="periodTypePgu"
                          id="periodShiftPgu"
                          label="Смена"
                          checked={periodType === 'shift'}
                          onChange={() => handlePeriodTypeChange('shift')}
                        />
                        <CFormCheck
                          type="radio"
                          name="periodTypePgu"
                          id="periodDayPgu"
                          label="Сутки"
                          checked={periodType === 'day'}
                          onChange={() => handlePeriodTypeChange('day')}
                        />
                        <CFormCheck
                          type="radio"
                          name="periodTypePgu"
                          id="periodRangePgu"
                          label="Период"
                          checked={periodType === 'period'}
                          onChange={() => handlePeriodTypeChange('period')}
                        />
                      </div>
                    </CCol>
                  </CRow>

                  {/* Календарь */}
                  <CRow className="mb-4">
                    <CCol md={8}>
                      <CFormLabel className="fw-bold mb-3">
                        {periodType === 'period' ? 'Выберите период:' : 'Выберите дату:'}
                      </CFormLabel>
                      
                      {periodType === 'period' ? (
                        <CRow>
                          <CCol md={6}>
                            <CInputGroup className="mb-2">
                              <CInputGroupText>С</CInputGroupText>
                              <DatePicker
                                selected={startDate}
                                onChange={setStartDate}
                                locale="ru"
                                dateFormat="dd.MM.yyyy"
                                className="form-control"
                                maxDate={endDate || new Date()}
                                showYearDropdown
                                showMonthDropdown
                                dropdownMode="select"
                                placeholderText="Дата начала"
                              />
                            </CInputGroup>
                          </CCol>
                          <CCol md={6}>
                            <CInputGroup>
                              <CInputGroupText>По</CInputGroupText>
                              <DatePicker
                                selected={endDate}
                                onChange={setEndDate}
                                locale="ru"
                                dateFormat="dd.MM.yyyy"
                                className="form-control"
                                minDate={startDate}
                                maxDate={new Date()}
                                showYearDropdown
                                showMonthDropdown
                                dropdownMode="select"
                                placeholderText="Дата окончания"
                              />
                            </CInputGroup>
                          </CCol>
                        </CRow>
                      ) : (
                        <div className="date-picker-container">
                          <DatePicker
                            selected={selectedDate}
                            onChange={setSelectedDate}
                            locale="ru"
                            dateFormat="dd.MM.yyyy"
                            className="form-control"
                            maxDate={new Date()}
                            showYearDropdown
                            showMonthDropdown
                            dropdownMode="select"
                            placeholderText="Выберите дату"
                          />
                        </div>
                      )}
                    </CCol>
                  </CRow>

                  {/* Выбор смен - показываем только для смены и периода */}
                  {periodType !== 'day' && (
                    <CRow className="mb-4">
                      <CCol md={8}>
                        <CFormLabel className="fw-bold mb-3">
                          {periodType === 'shift' ? 'Выберите смены:' : 'Выберите вахты:'}
                        </CFormLabel>
                        
                        {/* Кнопки выбора всех/ни одной */}
                        <div className="mb-3">
                          <CButton 
                            color="outline-primary" 
                            size="sm" 
                            className="me-2"
                            onClick={selectAllShifts}
                          >
                            Выбрать все
                          </CButton>
                          <CButton 
                            color="outline-secondary" 
                            size="sm"
                            onClick={deselectAllShifts}
                          >
                            Снять все
                          </CButton>
                        </div>

                        {/* Чекбоксы смен или вахт */}
                        <div className="shifts-container">
                          {periodType === 'shift' ? (
                            // Отображение смен с работающими вахтами
                            activeVakhtas.map((shift) => (
                              <div key={shift.shiftNumber} className="mb-3">
                                <CFormCheck
                                  id={`shift${shift.shiftNumber}Pgu`}
                                  label={
                                    <div className="d-flex align-items-center">
                                      <span className="me-2">{shift.shiftName}</span>
                                      <CBadge color="info" className="ms-2">
                                        {shift.activeVahta}
                                      </CBadge>
                                    </div>
                                  }
                                  checked={selectedShifts[`shift${shift.shiftNumber}`]}
                                  onChange={() => handleShiftChange(`shift${shift.shiftNumber}`)}
                                />
                              </div>
                            ))
                          ) : (
                            // Отображение вахт для периода
                            <>
                              <CFormCheck
                                id="vahta1Pgu"
                                label="Вахта №1"
                                checked={selectedVakhtas.vahta1}
                                onChange={() => handleVakhtaChange('vahta1')}
                                className="mb-2"
                              />
                              <CFormCheck
                                id="vahta2Pgu"
                                label="Вахта №2"
                                checked={selectedVakhtas.vahta2}
                                onChange={() => handleVakhtaChange('vahta2')}
                                className="mb-2"
                              />
                              <CFormCheck
                                id="vahta3Pgu"
                                label="Вахта №3"
                                checked={selectedVakhtas.vahta3}
                                onChange={() => handleVakhtaChange('vahta3')}
                                className="mb-2"
                              />
                              <CFormCheck
                                id="vahta4Pgu"
                                label="Вахта №4"
                                checked={selectedVakhtas.vahta4}
                                onChange={() => handleVakhtaChange('vahta4')}
                                className="mb-2"
                              />
                            </>
                          )}
                        </div>
                      </CCol>
                    </CRow>
                  )}

                  {/* Кнопка запуска расчетов */}
                  <CRow>
                    <CCol md={6}>
                      <CButton 
                        color="primary" 
                        size="lg"
                        onClick={handleCalculate}
                        className="px-4"
                      >
                        Выполнить расчеты
                      </CButton>
                    </CCol>
                  </CRow>
                </CForm>
              </CTabPane>

              {/* Таб "Анализ УРТ" */}
              <CTabPane visible={activeTab === 'urt'}>
                <CForm>
                  {/* Выбор типа периода */}
                  <CRow className="mb-4">
                    <CCol md={8}>
                      <CFormLabel className="fw-bold mb-3">Выберите период расчета:</CFormLabel>
                      <div className="d-flex gap-4">
                        <CFormCheck
                          type="radio"
                          name="periodTypeUrt"
                          id="periodShiftUrt"
                          label="Смена"
                          checked={periodType === 'shift'}
                          onChange={() => handlePeriodTypeChange('shift')}
                        />
                        <CFormCheck
                          type="radio"
                          name="periodTypeUrt"
                          id="periodDayUrt"
                          label="Сутки"
                          checked={periodType === 'day'}
                          onChange={() => handlePeriodTypeChange('day')}
                        />
                        <CFormCheck
                          type="radio"
                          name="periodTypeUrt"
                          id="periodRangeUrt"
                          label="Период"
                          checked={periodType === 'period'}
                          onChange={() => handlePeriodTypeChange('period')}
                        />
                      </div>
                    </CCol>
                  </CRow>

                  {/* Календарь */}
                  <CRow className="mb-4">
                    <CCol md={8}>
                      <CFormLabel className="fw-bold mb-3">
                        {periodType === 'period' ? 'Выберите период:' : 'Выберите дату:'}
                      </CFormLabel>
                      
                      {periodType === 'period' ? (
                        <CRow>
                          <CCol md={6}>
                            <CInputGroup className="mb-2">
                              <CInputGroupText>С</CInputGroupText>
                              <DatePicker
                                selected={startDate}
                                onChange={setStartDate}
                                locale="ru"
                                dateFormat="dd.MM.yyyy"
                                className="form-control"
                                maxDate={endDate || new Date()}
                                showYearDropdown
                                showMonthDropdown
                                dropdownMode="select"
                                placeholderText="Дата начала"
                              />
                            </CInputGroup>
                          </CCol>
                          <CCol md={6}>
                            <CInputGroup>
                              <CInputGroupText>По</CInputGroupText>
                              <DatePicker
                                selected={endDate}
                                onChange={setEndDate}
                                locale="ru"
                                dateFormat="dd.MM.yyyy"
                                className="form-control"
                                minDate={startDate}
                                maxDate={new Date()}
                                showYearDropdown
                                showMonthDropdown
                                dropdownMode="select"
                                placeholderText="Дата окончания"
                              />
                            </CInputGroup>
                          </CCol>
                        </CRow>
                      ) : (
                        <div className="date-picker-container">
                          <DatePicker
                            selected={selectedDate}
                            onChange={setSelectedDate}
                            locale="ru"
                            dateFormat="dd.MM.yyyy"
                            className="form-control"
                            maxDate={new Date()}
                            showYearDropdown
                            showMonthDropdown
                            dropdownMode="select"
                            placeholderText="Выберите дату"
                          />
                        </div>
                      )}
                    </CCol>
                  </CRow>

                  {/* Выбор смен - показываем только для смены и периода */}
                  {periodType !== 'day' && (
                    <CRow className="mb-4">
                      <CCol md={8}>
                        <CFormLabel className="fw-bold mb-3">
                          {periodType === 'shift' ? 'Выберите смены:' : 'Выберите вахты:'}
                        </CFormLabel>
                        
                        {/* Кнопки выбора всех/ни одной */}
                        <div className="mb-3">
                          <CButton 
                            color="outline-primary" 
                            size="sm" 
                            className="me-2"
                            onClick={selectAllShifts}
                          >
                            Выбрать все
                          </CButton>
                          <CButton 
                            color="outline-secondary" 
                            size="sm"
                            onClick={deselectAllShifts}
                          >
                            Снять все
                          </CButton>
                        </div>

                        {/* Чекбоксы смен или вахт */}
                        <div className="shifts-container">
                          {periodType === 'shift' ? (
                            // Отображение смен с работающими вахтами
                            activeVakhtas.map((shift) => (
                              <div key={shift.shiftNumber} className="mb-3">
                                <CFormCheck
                                  id={`shift${shift.shiftNumber}Urt`}
                                  label={
                                    <div className="d-flex align-items-center">
                                      <span className="me-2">{shift.shiftName}</span>
                                      <CBadge color="info" className="ms-2">
                                        {shift.activeVahta}
                                      </CBadge>
                                    </div>
                                  }
                                  checked={selectedShifts[`shift${shift.shiftNumber}`]}
                                  onChange={() => handleShiftChange(`shift${shift.shiftNumber}`)}
                                />
                              </div>
                            ))
                          ) : (
                            // Отображение вахт для периода
                            <>
                              <CFormCheck
                                id="vahta1Urt"
                                label="Вахта №1"
                                checked={selectedVakhtas.vahta1}
                                onChange={() => handleVakhtaChange('vahta1')}
                                className="mb-2"
                              />
                              <CFormCheck
                                id="vahta2Urt"
                                label="Вахта №2"
                                checked={selectedVakhtas.vahta2}
                                onChange={() => handleVakhtaChange('vahta2')}
                                className="mb-2"
                              />
                              <CFormCheck
                                id="vahta3Urt"
                                label="Вахта №3"
                                checked={selectedVakhtas.vahta3}
                                onChange={() => handleVakhtaChange('vahta3')}
                                className="mb-2"
                              />
                              <CFormCheck
                                id="vahta4Urt"
                                label="Вахта №4"
                                checked={selectedVakhtas.vahta4}
                                onChange={() => handleVakhtaChange('vahta4')}
                                className="mb-2"
                              />
                            </>
                          )}
                        </div>
                      </CCol>
                    </CRow>
                  )}

                  {/* Кнопка запуска расчетов */}
                  <CRow>
                    <CCol md={6}>
                      <CButton 
                        color="primary" 
                        size="lg"
                        onClick={handleCalculate}
                        className="px-4"
                      >
                        Выполнить расчеты
                      </CButton>
                    </CCol>
                  </CRow>
                </CForm>
              </CTabPane>
            </CTabContent>
          </CCardBody>
        </CCard>
      </CCol>
    </CRow>
  )
}

export default Calculations 