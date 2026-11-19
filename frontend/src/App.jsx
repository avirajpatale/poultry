import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import { ToastProvider } from './context/ToastContext'
import AdminLayout from './layouts/AdminLayout'

import Login from './pages/Login'
import Register from './pages/Register'
import ForgotPassword from './pages/ForgotPassword'
import ResetPassword from './pages/ResetPassword'
import Dashboard from './pages/Dashboard'
import DailyEggEntry from './pages/DailyEggEntry'
import DailyReport from './pages/DailyReport'
import MonthlyReport from './pages/MonthlyReport'
import Money from './pages/Money'
import Materials from './pages/Materials'
import Users from './pages/Users'

function Protected({ children }) {
  const { user, loading } = useAuth()
  if (loading) {
    return <div className="d-flex justify-content-center align-items-center vh-100">
      <div className="spinner-border text-success" />
    </div>
  }
  return user ? children : <Navigate to="/login" replace />
}

export default function App() {
  return (
    <ToastProvider>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route path="/register" element={<Register />} />
            <Route path="/forgot" element={<ForgotPassword />} />
            <Route path="/reset-password" element={<ResetPassword />} />
            <Route element={<Protected><AdminLayout /></Protected>}>
              <Route path="/" element={<Dashboard />} />
              <Route path="/eggs" element={<DailyEggEntry />} />
              <Route path="/daily" element={<DailyReport />} />
              <Route path="/monthly" element={<MonthlyReport />} />
              <Route path="/money" element={<Money />} />
              <Route path="/materials" element={<Materials />} />
              <Route path="/users" element={<Users />} />
              <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </ToastProvider>
  )
}
