import React from 'react'
import CIcon from '@coreui/icons-react'
import { cilSpeedometer, cilList, cilSettings, cilPowerStandby, cilChart, cilCalendar, cilFunctions, cilCalculator, cilNoteAdd } from '@coreui/icons'
import { CNavItem, CNavGroup } from '@coreui/react'

const _nav = [
  {
    component: CNavItem,
    name: 'Главная',
    to: '/',
    icon: <CIcon icon={cilSpeedometer} customClassName="nav-icon" />,
  },
  {
    component: CNavItem,
    name: 'Параметры Блоков',
    to: '/block-params',
    icon: <CIcon icon={cilList} customClassName="nav-icon" />,
  },
  {
    component: CNavItem,
    name: 'Параметры ПГУ',
    to: '/pgu-params',
    icon: <CIcon icon={cilSettings} customClassName="nav-icon" />,
  },
  {
    component: CNavItem,
    name: 'Пуски и Остановы',
    to: '/equipment-events',
    icon: <CIcon icon={cilPowerStandby} customClassName="nav-icon" />,
  },
  {
    component: CNavItem,
    name: 'Счётчики',
    to: '/counters',
    icon: <CIcon icon={cilChart} customClassName="nav-icon" />,
  },
  {
    component: CNavItem,
    name: 'График вахт',
    to: '/shift-schedule',
    icon: <CIcon icon={cilCalendar} customClassName="nav-icon" />,
  },
  {
    component: CNavItem,
    name: 'Переменные ПГУ',
    to: '/functions/variables',
    icon: <CIcon icon={cilCalculator} customClassName="nav-icon" />,
  },
  {
    component: CNavItem,
    name: 'Наработки',
    to: '/operating-hours',
    icon: <CIcon icon={cilChart} customClassName="nav-icon" />,
  },
]

export default _nav
