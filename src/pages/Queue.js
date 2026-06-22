import React, { useState, useEffect } from 'react';
import { Card, Table, Button, Tag, Space, Select, Modal, message, Badge, Tooltip } from 'antd';
import { PlayCircleOutlined, SyncOutlined, EyeOutlined, RedoOutlined, LinkOutlined, ExclamationCircleOutlined } from '@ant-design/icons';
import { api } from '../utils/api';

export default function Queue() {
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(20);
  const [filterStatus, setFilterStatus] = useState(null);
  const [filterTicker, setFilterTicker] = useState(null);
  const [tickerOptions, setTickerOptions] = useState([]);
  const [activeItem, setActiveItem] = useState(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [runningId, setRunningId] = useState(null);

  const loadQueue = async () => {
    setLoading(true);
    try {
      const res = await api.get('/queue', {
        page,
        limit: pageSize,
        status: filterStatus,
        ticker: filterTicker
      });
      setData(res.items || []);
      setTotal(res.total || 0);
    } catch (err) {
      message.error('Failed to load news queue: ' + err.message);
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
    loadQueue();
  }, [page, pageSize, filterStatus, filterTicker]);

  useEffect(() => {
    loadTickers();
  }, []);

  const handleRunItem = async (id) => {
    setRunningId(id);
    const key = 'run_item_loading';
    message.loading({ content: 'Processing article using OpenAI Chat Completions...', key, duration: 0 });
    try {
      const res = await api.post('/queue/run', { id });
      message.success({ content: res.message || 'Article processed & published successfully!', key, duration: 4 });
      loadQueue();
    } catch (err) {
      message.error({ content: 'Workflow failed: ' + err.message, key, duration: 6 });
    } finally {
      setRunningId(null);
    }
  };

  const handleResetItem = async (id) => {
    try {
      const res = await api.post('/queue/reset', { id });
      message.success(res.message || 'Queue item reset to pending.');
      loadQueue();
    } catch (err) {
      message.error('Failed to reset item: ' + err.message);
    }
  };

  const handleOpenDetails = (record) => {
    setActiveItem(record);
    setModalOpen(true);
  };

  const getStatusTag = (status, errorMsg) => {
    switch (status) {
      case 'processed':
        return <Tag color="success">Published</Tag>;
      case 'skipped_irrelevant':
        return <Tag color="warning">Skipped (Irrelevant)</Tag>;
      case 'failed':
        return (
          <Tooltip title={errorMsg || 'Failed processing'}>
            <Tag color="error" icon={<ExclamationCircleOutlined />}>Failed</Tag>
          </Tooltip>
        );
      default:
        return <Tag color="processing">Pending</Tag>;
    }
  };

  const columns = [
    {
      title: 'Ticker',
      dataIndex: 'ticker',
      key: 'ticker',
      render: (text) => <Tag color="blue">{text}</Tag>
    },
    {
      title: 'Source Title',
      dataIndex: 'source_title',
      key: 'source_title',
      render: (text, record) => (
        <Space direction="vertical" size={1}>
          <span style={{ fontWeight: 500 }}>{text}</span>
          <a href={record.source_url} target="_blank" rel="noopener noreferrer" style={{ fontSize: '11px', color: '#94a3b8' }}>
            <LinkOutlined /> View Original Source
          </a>
        </Space>
      )
    },
    {
      title: 'Status',
      key: 'status',
      render: (_, record) => getStatusTag(record.status, record.error_message)
    },
    {
      title: 'Created Date',
      dataIndex: 'created_at',
      key: 'created_at'
    },
    {
      title: 'Published Post',
      key: 'post',
      render: (_, record) => {
        if (record.post_id) {
          return (
            <Space>
              <a href={record.post_view_url} target="_blank" rel="noopener noreferrer">View</a>
              <span>|</span>
              <a href={record.post_edit_url} target="_blank" rel="noopener noreferrer">Edit (Post #{record.post_id})</a>
            </Space>
          );
        }
        return <span style={{ color: '#94a3b8' }}>N/A</span>;
      }
    },
    {
      title: 'Actions',
      key: 'actions',
      render: (_, record) => (
        <Space size="small">
          <Button 
            type="text" 
            icon={<EyeOutlined />} 
            onClick={() => handleOpenDetails(record)}
          >
            Details
          </Button>

          {record.status === 'pending' && (
            <Button
              type="primary"
              size="small"
              icon={<PlayCircleOutlined />}
              onClick={() => handleRunItem(record.id)}
              loading={runningId === record.id}
              style={{ borderRadius: '4px' }}
            >
              Run Now
            </Button>
          )}

          {(record.status === 'failed' || record.status === 'skipped_irrelevant') && (
            <Button
              type="text"
              size="small"
              icon={<RedoOutlined />}
              onClick={() => handleResetItem(record.id)}
              style={{ color: '#52c41a' }}
            >
              Retry
            </Button>
          )}
        </Space>
      )
    }
  ];

  return (
    <div className="cgm-fade-in-el">
      <Card 
        className="cgm-premium-card"
        title={<span style={{ fontSize: '18px', fontWeight: 600 }}>Crawl & Processing Registry Queue</span>}
        extra={
          <Space>
            <Select
              allowClear
              placeholder="Filter Ticker"
              style={{ width: 140 }}
              onChange={setFilterTicker}
              options={tickerOptions.map(t => ({ label: t, value: t }))}
            />
            <Select
              allowClear
              placeholder="Filter Status"
              style={{ width: 160 }}
              onChange={setFilterStatus}
              options={[
                { label: 'Pending', value: 'pending' },
                { label: 'Published / Live', value: 'processed' },
                { label: 'Skipped Irrelevant', value: 'skipped_irrelevant' },
                { label: 'Failed Attempts', value: 'failed' }
              ]}
            />
            <Button 
              icon={<SyncOutlined />} 
              onClick={() => { loadQueue(); loadTickers(); }}
              type="default"
            >
              Refresh
            </Button>
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

      {/* Details Modal */}
      <Modal
        title="Queued News Details"
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        footer={[
          <Button key="close" onClick={() => setModalOpen(false)}>Close</Button>,
          activeItem && activeItem.status === 'pending' && (
            <Button 
              key="run" 
              type="primary" 
              icon={<PlayCircleOutlined />} 
              onClick={() => { setModalOpen(false); handleRunItem(activeItem.id); }}
              loading={runningId === activeItem.id}
            >
              Process Now
            </Button>
          )
        ]}
        width={700}
        destroyOnClose
      >
        {activeItem && (
          <div style={{ marginTop: '16px' }}>
            <div style={{ marginBottom: '16px' }}>
              <span style={{ fontWeight: 'bold' }}>Ticker context:</span> <Tag color="blue">{activeItem.ticker}</Tag>
              <span style={{ fontWeight: 'bold', marginLeft: '16px' }}>Status:</span> {getStatusTag(activeItem.status)}
            </div>

            <div style={{ marginBottom: '16px' }}>
              <h3 style={{ margin: '0 0 8px 0', fontSize: '16px', fontWeight: 600 }}>{activeItem.source_title}</h3>
              <a href={activeItem.source_url} target="_blank" rel="noopener noreferrer">
                Original source URL: {activeItem.source_url}
              </a>
            </div>

            {activeItem.error_message && (
              <div style={{ marginBottom: '16px', padding: '12px', background: '#fff1f0', border: '1px solid #ffa39e', borderRadius: '6px', color: '#cf1322' }}>
                <h4 style={{ margin: '0 0 4px 0', fontWeight: 'bold' }}>Error Log:</h4>
                <p style={{ margin: 0, fontSize: '13px' }}>{activeItem.error_message}</p>
              </div>
            )}

            <div>
              <h4 style={{ margin: '0 0 6px 0', fontWeight: 'bold' }}>Original Article Content:</h4>
              <div style={{ 
                maxHeight: '250px', 
                overflowY: 'auto', 
                padding: '12px', 
                background: '#f8fafc', 
                border: '1px solid #cbd5e1', 
                borderRadius: '6px',
                whiteSpace: 'pre-wrap',
                fontSize: '13px',
                color: '#334155'
              }}>
                {activeItem.source_content || '(No content body available)'}
              </div>
            </div>
            
            <div style={{ marginTop: '16px', fontSize: '12px', color: '#94a3b8' }}>
              Content MD5 Hash: <code>{activeItem.content_hash}</code> | Fetched ID: <code>{activeItem.source_id}</code>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
