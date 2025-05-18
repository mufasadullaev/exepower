import React from 'react'
import { CFooter } from '@coreui/react'
import logo from '../assets/images/logo.png'
import logotext from '../assets/images/logo-text.png'
const AppFooter = () => {
  return (
    <CFooter className="px-4">
      <div style={{ display: 'flex', flexDirection: 'row', alignItems: 'center' }}>
        <a href="/" target="_blank" rel="" style={{ display: 'flex', flexDirection: 'row', alignItems: 'center' }}>
          <img src={logo} style={{ height: '20px' }} alt="logo" className="me-2" />
          <img src={logotext} style={{ height: '15px' }} alt="logo-text" className="me-2" />
        </a>
        <span className="ms-1">&copy; {new Date().getFullYear()} creative tech solutions lab</span>
      </div>
      <div className="ms-auto">
        <span className="me-1">Powered by</span>
        <a href="https://exedev.uz/" target="_blank" rel="Developer Site">
          ExeDev.Uz
        </a>
      </div>
    </CFooter>
  )
}

export default React.memo(AppFooter)
