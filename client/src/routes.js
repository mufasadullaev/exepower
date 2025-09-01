import React from 'react'
import Dashboard from './views/dashboard/Dashboard'
import Counters from './views/counters/Counters'

const BlockParams = React.lazy(() => import('./views/params/BlockParams'))
const PguParams = React.lazy(() => import('./views/params/PguParams'))
const EquipmentEvents = React.lazy(() => import('./views/events/EquipmentEvents'))
const OperatingHours = React.lazy(() => import('./views/stats/OperatingHours'))
const ShiftSchedule = React.lazy(() => import('./views/shifts/ShiftSchedule'))
const FunctionVariables = React.lazy(() => import('./views/functions/FunctionVariables'))
const Calculations = React.lazy(() => import('./views/calculations/Calculations'))
const PguResults = React.lazy(() => import('./views/calculations/PguResults'))
const BlocksResults = React.lazy(() => import('./views/calculations/BlocksResults'))

const routes = [
  {
    path: '/',
    exact: true,
    name: 'Главная',
    element: Dashboard,
  },
  {
    path: '/counters',
    name: 'Счётчики',
    element: Counters,
  },
  { path: 'dashboard', name: 'Главная', element: Dashboard },
  { path: 'block-params', name: 'Параметры Блоков', element: BlockParams },
  { path: 'pgu-params', name: 'Параметры ПГУ', element: PguParams },
  { path: 'equipment-events', name: 'Пуски и Остановы', element: EquipmentEvents },
  { path: 'operating-hours', name: 'Наработки', element: OperatingHours },
  { path: 'shift-schedule', name: 'График вахт', element: ShiftSchedule },
  { path: 'function-variables', name: 'Переменные ПГУ', element: FunctionVariables },
  { path: 'calculations', name: 'Расчеты', element: Calculations },
  { path: 'pgu-results', name: 'Результаты ПГУ', element: PguResults },
  { path: 'blocks-results', name: 'Результаты Блоков', element: BlocksResults },
]

export default routes
