import React from 'react'
import { AppSidebar, AppFooter, AppHeader } from '../components/index'
import { Outlet } from 'react-router-dom'
import { CContainer } from '@coreui/react'

const DefaultLayout = () => {
  return (
    <div>
      <AppSidebar />
      <div className="wrapper d-flex flex-column min-vh-100">
        <AppHeader />
        <div className="body flex-grow-1">
          <CContainer className="px-4" lg>
            <Outlet />
          </CContainer>
        </div>
        <AppFooter />
      </div>
    </div>
  )
}

export default DefaultLayout
