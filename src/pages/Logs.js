import React, { useState, useEffect } from 'react';
import { Card, Table, Button, Tag, Space, Select, Input, Modal, Popconfirm, message } from 'antd';
import { DeleteOutlined, SyncOutlined, EyeOutlined, SearchOutlined } from '@ant-design/icons';
import { api } from '../utils/api';

export default function Logs() {
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(20);
  const [filterLevel, setFilterLevel] = useState(null);
  const [filterTicker, setFilterTicker] = useState(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [tickerOptions, setTickerOptions] = useState([]);
  const [activeLog, setActiveLog] = useState(null);
  const [modalOpen, setModalOpen] = useState(false);

  const loadLogs = async () => {
    setLoading(true);
    try {
      const res = await api.get('/logs', {
        page,
        limit: pageSize,
        level: filterLevel,
        ticker: filterTicker,
        search: searchQuery || undefined
      });
      setData(res.items || []);
      setTotal(res.total || 0);
    } catch (err) {
      message.error('Failed to load system logs: ' + err.message);
    } finally {
      setLoading(false);
    }
  };

  const loadTickers = async () => {
    try {
      const tickers = await api.get('/tickers');
      setTickerOptions(tickers.map(t => t.symbol));
    } catch (err) {
      console.error('Failed to load tickers list:', err);
    }
  };

  useEffect(() => {
    loadLogs();
  }, [page, pageSize, filterLevel, filterTicker]);

  useEffect(() => {
    loadTickers();
  }, []);

  const handleClearLogs = async () => {
    try {
      await api.post('/logs/clear');
      message.success('System logs cleared successfully.');
      loadLogs();
    } catch (err) {
      message.error('Failed to clear logs: ' + err.message);
    }
  };

  const handleOpenContext = (record) => {
    setActiveLog(record);
    setModalOpen(true);
  };

  const getLogLevelTag = (level) => {
    switch (level) {
      case 'error':
        return <Tag color="error">ERROR</Tag>;
      case 'warning':
        return <Tag color="warning">WARN</Tag>;
      case 'info':
      default:
        return <Tag color="info">INFO</Tag>;
    }
  };

  const columns = [
    {
      title: 'Timestamp',
      dataIndex: 'timestamp',
      key: 'timestamp',
      width: '180px'
    },
    {
      title: 'Level',
      dataIndex: 'level',
      key: 'level',
      width: '90px',
      render: (level) => getLogLevelTag(level)
    },
    {
      title: 'Ticker',
      dataIndex: 'ticker',
      key: 'ticker',
      width: '100px',
      render: (ticker) => ticker ? <Tag color="blue">{ticker}</Tag> : <span style={{ color: '#94a3b8' }}>Global</span>
    },
    {
      title: 'Action Hook / Phase',
      dataIndex: 'action',
      key: 'action',
      width: '180px',
      render: (text) => <code>{text}</code>
    },
    {
      title: 'Diagnostic Message',
      dataIndex: 'message',
      key: 'message'
    },
    {
      title: 'Context Data',
      key: 'context',
      width: '100px',
      render: (_, record) => {
        if (record.context) {
          return (
            <Button 
              size="small" 
              icon={<EyeOutlined />} 
              onClick={() => handleOpenContext(record)}
            >
              Inspect
            </Button>
          );
        }
        return <span style={{ color: '#94a3b8' }}>None</span>;
      }
    }
  ];

  return (
    <div className="cgm-fade-in-el">
      <Card 
        className="cgm-premium-card"
        title={<span style={{ fontSize: '18px', fontWeight: 600 }}>System Logs & Diagnostics</span>}
        extra={
          <Space>
            <Input
              placeholder="Search logs..."
              style={{ width: 180 }}
              onPressEnter={(e) => { setSearchQuery(e.target.value); setPage(1); loadLogs(); }}
              suffix={
                <SearchOutlined 
                  style={{ cursor: 'pointer' }} 
                  onClick={() => { setPage(1); loadLogs(); }} 
                />
              }
            />
            <Select
              allowClear
              placeholder="Filter Ticker"
              style={{ width: 130 }}
              onChange={setFilterTicker}
              options={tickerOptions.map(t => ({ label: t, value: t }))}
            />
            <Select
              allowClear
              placeholder="Filter Level"
              style={{ width: 130 }}
              onChange={setFilterLevel}
              options={[
                { label: 'INFO', value: 'info' },
                { label: 'WARN', value: 'warning' },
                { label: 'ERROR', value: 'error' }
              ]}
            />
            <Button 
              icon={<SyncOutlined />} 
              onClick={loadLogs}
              type="default"
            >
              Refresh
            </Button>
            <Popconfirm
              title="Clear all logs?"
              description="This will permanently delete all entries in the wp_cgm_logs table. Are you sure?"
              onConfirm={handleClearLogs}
              okText="Clear All"
              cancelText="Cancel"
              okButtonProps={{ danger: true }}
            >
              <Button danger icon={<DeleteOutlined />}>
                Clear Logs
              </Button>
            </Popconfirm>
          </Space>
        }
      >
        <Table
          columns={columns}
          dataSource={data}
          rowKey="id"
          loading={loading}
          pagination={{
            current: page,
            pageSize: pageSize,
            total: total,
            showSizeChanger: true,
            onChange: (p, ps) => { setPage(p); setPageSize(ps); }
          }}
        />
      </Card>

      {/* Log Context Inspector Modal */}
      <Modal
        title="Log Context Payload Inspector"
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        footer={[
          <Button key="close" onClick={() => setModalOpen(false)}>Close</Button>
        ]}
        width={800}
        destroyOnClose
      >
        {activeLog && (
          <div style={{ marginTop: '16px' }}>
            <div style={{ marginBottom: '12px' }}>
              <span style={{ fontWeight: 'bold' }}>Phase:</span> <code>{activeLog.action}</code>
              <span style={{ fontWeight: 'bold', marginLeft: '16px' }}>Timestamp:</span> {activeLog.timestamp}
            </div>
            <div style={{ marginBottom: '16px', fontWeight: '500', color: '#1e293b' }}>
              {activeLog.message}
            </div>
            <div>
              <h4 style={{ margin: '0 0 6px 0', fontWeight: 'bold' }}>JSON Payload / Metadata:</h4>
              <div style={{ 
                maxHeight: '400px', 
                overflowY: 'auto', 
                padding: '12px', 
                background: '#0f172a', 
                color: '#38bdf8', 
                fontFamily: 'monospace', 
                fontSize: '12px', 
                borderRadius: '6px',
                border: '1px solid #1e293b'
              }}>
                <pre style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
                  {JSON.stringify(activeLog.context, null, 2)}
                </pre>
              </div>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
