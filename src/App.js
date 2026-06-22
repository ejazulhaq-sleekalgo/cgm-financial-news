import React from 'react';
import { HashRouter, Routes, Route, Link, useLocation } from 'react-router-dom';
import { ConfigProvider, Layout, Menu, theme } from 'antd';
import { 
  DashboardOutlined, 
  SettingOutlined, 
  UnorderedListOutlined, 
  BugOutlined, 
  StockOutlined,
  BookOutlined
} from '@ant-design/icons';
import Dashboard from './pages/Dashboard';
import Tickers from './pages/Tickers';
import Queue from './pages/Queue';
import Logs from './pages/Logs';
import Settings from './pages/Settings';

const { Header, Content, Sider } = Layout;

function NavigationLayout() {
  const location = useLocation();
  const currentPath = location.pathname || '/';

  const menuItems = [
    {
      key: '/',
      icon: <DashboardOutlined />,
      label: <Link to="/">Dashboard</Link>,
    },
    {
      key: '/tickers',
      icon: <StockOutlined />,
      label: <Link to="/tickers">Tickers Config</Link>,
    },
    {
      key: '/queue',
      icon: <UnorderedListOutlined />,
      label: <Link to="/queue">Queue Registry</Link>,
    },
    {
      key: '/logs',
      icon: <BugOutlined />,
      label: <Link to="/logs">Diagnostics Logs</Link>,
    },
    {
      key: '/settings',
      icon: <SettingOutlined />,
      label: <Link to="/settings">Plugin Settings</Link>,
    },
  ];

  return (
    <Layout style={{ minHeight: '85vh', borderRadius: '12px', overflow: 'hidden', boxShadow: '0 4px 12px rgba(0,0,0,0.05)' }}>
      <Sider 
        width={230} 
        theme="light" 
        breakpoint="lg" 
        collapsedWidth="60"
        style={{ borderRight: '1px solid #e2e8f0' }}
      >
        <div style={{ 
          height: '64px', 
          display: 'flex', 
          alignItems: 'center', 
          paddingLeft: '24px', 
          borderBottom: '1px solid #f1f5f9',
          gap: '8px'
        }}>
          <BookOutlined style={{ fontSize: '20px', color: '#3b82f6' }} />
          <span style={{ fontWeight: '700', fontSize: '15px', color: '#0f172a', letterSpacing: '-0.025em' }}>
            CGM News Manager
          </span>
        </div>
        <Menu
          mode="inline"
          selectedKeys={[currentPath]}
          items={menuItems}
          style={{ height: 'calc(100% - 64px)', borderRight: 0, paddingTop: '12px' }}
        />
      </Sider>
      <Layout>
        <Header style={{ 
          background: '#ffffff', 
          padding: '0 24px', 
          height: '64px', 
          lineHeight: '64px', 
          display: 'flex', 
          justifyContent: 'flex-end', 
          alignItems: 'center',
          borderBottom: '1px solid #f1f5f9'
        }}>
          <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '500' }}>
            WordPress Integrations &bull; API Version 1.0
          </span>
        </Header>
        <Content style={{ padding: '24px', background: '#f8fafc', overflowY: 'auto' }}>
          <Routes>
            <Route path="/" element={<Dashboard />} />
            <Route path="/tickers" element={<Tickers />} />
            <Route path="/queue" element={<Queue />} />
            <Route path="/logs" element={<Logs />} />
            <Route path="/settings" element={<Settings />} />
          </Routes>
        </Content>
      </Layout>
    </Layout>
  );
}

export default function App() {
  return (
    <ConfigProvider
      theme={{
        token: {
          colorPrimary: '#3b82f6', // Premium Indigo/Blue color
          colorSuccess: '#10b981', // Premium Emerald Green
          colorWarning: '#f59e0b', // Amber/Orange
          colorError: '#ef4444', // Red
          borderRadius: 8,
          fontFamily: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif`
        },
      }}
    >
      <HashRouter>
        <NavigationLayout />
      </HashRouter>
    </ConfigProvider>
  );
}
