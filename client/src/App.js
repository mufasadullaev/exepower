import React, { Suspense, useEffect } from 'react'
import { HashRouter, Route, Routes, Navigate } from 'react-router-dom'
import { useSelector } from 'react-redux'

import { CSpinner, useColorModes } from '@coreui/react'
import './scss/style.scss'
import authService from './services/authService'

// We use those styles to show code examples, you should remove them in your application.
import './scss/examples.scss'

// Containers
const DefaultLayout = React.lazy(() => import('./layout/DefaultLayout'))

// Pages
const Login = React.lazy(() => import('./views/pages/login/Login'))
const Register = React.lazy(() => import('./views/pages/register/Register'))
const Page404 = React.lazy(() => import('./views/pages/page404/Page404'))
const Page500 = React.lazy(() => import('./views/pages/page500/Page500'))
const BlockParams = React.lazy(() => import('./views/params/BlockParams'))
const PguParams = React.lazy(() => import('./views/params/PguParams'))
const Dashboard = React.lazy(() => import('./views/dashboard/Dashboard'))
const EquipmentEvents = React.lazy(() => import('./views/events/EquipmentEvents'))
const OperatingHours = React.lazy(() => import('./views/stats/OperatingHours'))
const ShiftSchedule = React.lazy(() => import('./views/shifts/ShiftSchedule'))
const FunctionVariables = React.lazy(() => import('./views/functions/FunctionVariables'))
const Counters = React.lazy(() => import('./views/counters/Counters'))
const Calculations = React.lazy(() => import('./views/calculations/Calculations'))
const PguResults = React.lazy(() => import('./views/calculations/PguResults'))

// Components
const ProtectedRoute = React.lazy(() => import('./components/ProtectedRoute'))

// Root route handler that checks authentication
const RootRedirect = () => {
  const isAuthenticated = authService.isAuthenticated();
  return isAuthenticated ? <Navigate to="/dashboard" replace /> : <Navigate to="/login" replace />;
};

const App = () => {
  const { isColorModeSet, setColorMode } = useColorModes('coreui-free-react-admin-template-theme')
  const storedTheme = useSelector((state) => state.theme)

  useEffect(() => {
    const urlParams = new URLSearchParams(window.location.href.split('?')[1])
    const theme = urlParams.get('theme') && urlParams.get('theme').match(/^[A-Za-z0-9\s]+/)[0]
    if (theme) {
      setColorMode(theme)
    }

    if (isColorModeSet()) {
      return
    }

    setColorMode(storedTheme)
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <HashRouter>
      <Suspense
        fallback={
          <div className="pt-3 text-center">
            <CSpinner color="primary" variant="grow" />
          </div>
        }
      >
        <Routes>
          <Route exact path="/" element={<RootRedirect />} />
          <Route exact path="/login" name="Login Page" element={<Login />} />
          <Route exact path="/register" name="Register Page" element={<Register />} />
          <Route exact path="/404" name="Page 404" element={<Page404 />} />
          <Route exact path="/500" name="Page 500" element={<Page500 />} />
          
          {/* Protected routes */}
          <Route element={<ProtectedRoute />}>
            <Route path="/dashboard" element={<DefaultLayout />}>
              <Route index element={<Dashboard />} />
            </Route>
            <Route path="/block-params" element={<DefaultLayout />}>
              <Route index element={<BlockParams />} />
            </Route>
            <Route path="/pgu-params" element={<DefaultLayout />}>
              <Route index element={<PguParams />} />
            </Route>
            <Route path="/equipment-events" element={<DefaultLayout />}>
              <Route index element={<EquipmentEvents />} />
            </Route>
            <Route path="/operating-hours" element={<DefaultLayout />}>
              <Route index element={<OperatingHours />} />
            </Route>
            <Route path="/shift-schedule" element={<DefaultLayout />}>
              <Route index element={<ShiftSchedule />} />
            </Route>
            <Route path="/functions/variables" element={<DefaultLayout />}>
              <Route index element={<FunctionVariables />} />
            </Route>
            <Route path="/counters" element={<DefaultLayout />}>
              <Route index element={<Counters />} />
            </Route>
            <Route path="/calculations" element={<DefaultLayout />}>
              <Route index element={<Calculations />} />
            </Route>
            <Route path="/pgu-results" element={<DefaultLayout />}>
              <Route index element={<PguResults />} />
            </Route>
          </Route>
        </Routes>
      </Suspense>
    </HashRouter>
  )
}

export default App
