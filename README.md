# ExePower - Power Plant Equipment Monitoring System

A comprehensive web-based system for monitoring and managing power plant equipment parameters, designed specifically for thermal power stations with gas turbines (GT), steam turbines (PT), and combined-cycle power units (CCGT).

## 🏭 Project Overview

ExePower is a real-time monitoring and data management system for power plant operations. It provides operators and engineers with tools to:

- **Monitor Equipment Status**: Track the operational state of turbines, generators, and auxiliary systems
- **Record Parameters**: Input and store critical operational parameters for different equipment types
- **Track Events**: Log equipment starts, stops, and maintenance events
- **Generate Reports**: Analyze performance data and generate operational reports
- **Manage Shifts**: Coordinate work across different shifts and teams

## 🏗️ System Architecture

### Technology Stack

- **Frontend**: React 19.0 with CoreUI 5.5.0
- **Backend**: PHP 8.1 with Apache
- **Database**: MySQL 8.0
- **Authentication**: JWT (JSON Web Tokens)
- **Containerization**: Docker & Docker Compose
- **Build Tools**: Vite 6.1.0

### Architecture Pattern

The system follows a **3-tier architecture**:

1. **Presentation Layer** (React Frontend)
   - Modern SPA with CoreUI components
   - Responsive design for various screen sizes
   - Real-time data updates

2. **Business Logic Layer** (PHP API)
   - RESTful API endpoints
   - JWT-based authentication
   - Role-based access control
   - Data validation and processing

3. **Data Layer** (MySQL Database)
   - Normalized relational database
   - Optimized for time-series data
   - Comprehensive indexing for performance

## 🔧 Equipment Types & Parameters

### Monitored Equipment

1. **Turbogenerators (TG)**
   - ТГ7 (Turbogenerator Block 7)
   - ТГ8 (Turbogenerator Block 8)
   - ОЧ-130 (Steam Generator)

2. **Combined-Cycle Power Units (CCGT)**
   - ПГУ1: ГТ1 + ПТ1 (Gas Turbine 1 + Steam Turbine 1)
   - ПГУ2: ГТ2 + ПТ2 (Gas Turbine 2 + Steam Turbine 2)

### Parameter Categories

#### Gas Turbine Parameters
- **Environmental**: Barometric pressure, air humidity, temperature
- **Performance**: Power factor (cosφ), frequency, fuel consumption
- **Fuel**: Gas pressure, temperature, density, calorific value
- **Auxiliary Systems**: Evaporative cooling, AOS (Air Cooling System)

#### Steam Turbine Parameters
- **Steam**: Pressure, temperature, flow rates
- **Water**: Feedwater temperature, circulation water temperatures
- **Heat**: Heat supply to district heating, heat to blocks
- **Efficiency**: Power factor, cooling tower temperatures

#### Block Parameters
- **Steam Cycle**: Steam pressure/temperature before/after reheating
- **Water Cycle**: Circulation water inlet/outlet temperatures
- **Fuel**: Gas and fuel oil consumption, calorific values
- **Emission**: Flue gas temperature, excess air coefficient

## 👥 User Roles & Permissions

### Role-Based Access Control

1. **Рядовой (Operator)**
   - Input parameter values
   - View equipment status
   - Record basic events

2. **Инженер (Engineer)**
   - All operator permissions
   - View reports and analytics
   - Access calculation modules

3. **Менеджер (Manager)**
   - Full system access
   - User management
   - System configuration
   - Advanced reporting

## 🚀 Quick Start with Docker

### Prerequisites

- Docker & Docker Compose
- Git

### Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd exepower
   ```

2. **Start the application**
   ```bash
   docker-compose up -d --build
   ```

3. **Access the application**
   - **Frontend**: http://localhost:3001
   - **API**: http://localhost:8001
   - **Database Admin**: http://localhost:8080

### Default Credentials

- **Username**: `admin`
- **Password**: `admin123`

## 📊 Key Features

### Dashboard
- Real-time equipment status overview
- Active shift monitoring
- Power generation statistics
- Equipment working hours tracking

### Parameter Management
- Equipment-specific parameter forms
- Excel-like cell mapping for data entry
- Automatic calculation of derived values
- Historical data tracking

### Event Tracking
- Equipment start/stop events
- Auxiliary system status (evaporator, AOS)
- Reason codes for events
- Shift-based event logging

### Reporting & Analytics
- Performance calculations
- Equipment efficiency analysis
- Shift schedule management
- Operating hours statistics

### Data Integration
- Automatic data synchronization between equipment types
- Cross-referenced parameter calculations
- Time-series data management

## 🗄️ Database Schema

### Core Tables

- **equipment**: Equipment definitions and types
- **parameters**: Parameter definitions by equipment type
- **parameter_values**: Time-series parameter data
- **equipment_events**: Start/stop event logging
- **users**: User accounts and roles
- **shifts**: Shift definitions and schedules

### Specialized Tables

- **pgu_fullparams**: CCGT comprehensive parameters
- **pgu_fullparam_values**: CCGT parameter values
- **tg_parameters**: Turbogenerator specific parameters
- **meter_readings**: Power generation/consumption data
- **functions**: Calculation function definitions

## 🔌 API Endpoints

### Authentication
- `POST /api/auth/login` - User login
- `GET /api/auth/verify` - Token verification
- `POST /api/auth/register` - User registration (managers only)

### Data Management
- `GET /api/dashboard` - Dashboard data
- `GET /api/parameters` - Equipment parameters
- `POST /api/parameter-values` - Save parameter values
- `GET /api/parameter-values` - Retrieve parameter values

### Equipment Management
- `GET /api/equipment` - Equipment list
- `POST /api/equipment-events` - Log equipment events
- `GET /api/equipment-events` - Retrieve events

## 🛠️ Development

### Local Development Setup

1. **Backend Setup**
   ```bash
   cd api
   # Configure database in config.php
   # Import database schema
   ```

2. **Frontend Setup**
   ```bash
   cd client
   npm install
   npm start
   ```

### Environment Configuration

Create `.env` file in project root:
```env
DB_HOST=mysql
DB_NAME=exepower
DB_USER=root
DB_PASS=rootpassword
API_URL=http://localhost:8001
VITE_API_URL=http://localhost:8001
```

## 📈 Performance Features

- **Optimized Queries**: Indexed database queries for fast data retrieval
- **Real-time Updates**: Automatic data refresh every 5 minutes
- **Efficient Calculations**: Pre-calculated derived values
- **Responsive Design**: Mobile-friendly interface
- **Data Validation**: Client and server-side validation

## 🔒 Security Features

- **JWT Authentication**: Secure token-based authentication
- **Password Hashing**: Bcrypt password encryption
- **Role-based Access**: Granular permission system
- **Input Validation**: Comprehensive data validation
- **SQL Injection Protection**: Prepared statements

## 📝 License

This project is proprietary software developed for power plant operations.

## 🤝 Contributing

This is an internal project. For contributions, please contact the development team.

## 📞 Support

For technical support or questions about the system, please contact the system administrator.

---

**ExePower** - Empowering Power Plant Operations Through Technology