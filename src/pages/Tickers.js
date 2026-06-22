import React, { useState, useEffect } from 'react';
import { Card, Table, Button, Space, Tag, Modal, Form, Input, InputNumber, Switch, Popconfirm, message, Progress, Badge } from 'antd';
import { PlusOutlined, EditOutlined, DeleteOutlined, InfoCircleOutlined } from '@ant-design/icons';
import { api } from '../utils/api';

export default function Tickers() {
  const [loading, setLoading] = useState(true);
  const [tickers, setTickers] = useState([]);
  const [settings, setSettings] = useState({});
  const [modalOpen, setModalOpen] = useState(false);
  const [editingTicker, setEditingTicker] = useState(null);
  const [form] = Form.useForm();

  const loadData = async () => {
    setLoading(true);
    try {
      // 1. Fetch settings (to save new tickers)
      const settingsData = await api.get('/settings');
      setSettings(settingsData);

      // 2. Fetch enriched ticker statistics
      const tickersData = await api.get('/tickers');
      setTickers(tickersData);
    } catch (err) {
      message.error('Failed to load tickers config: ' + err.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const handleOpenAddModal = () => {
    setEditingTicker(null);
    form.resetFields();
    form.setFieldsValue({ limit: 3, status: true });
    setModalOpen(true);
  };

  const handleOpenEditModal = (record) => {
    setEditingTicker(record);
    form.resetFields();
    form.setFieldsValue({
      symbol: record.symbol,
      alias: record.alias,
      limit: record.limit,
      status: record.status === 'active'
    });
    setModalOpen(true);
  };

  const handleDeleteTicker = async (symbolToDelete) => {
    try {
      const updatedTickersList = settings.tickers.filter(
        t => t.symbol.toUpperCase() !== symbolToDelete.toUpperCase()
      );
      
      const newSettingsObj = {
        ...settings,
        tickers: updatedTickersList
      };

      await api.post('/settings', newSettingsObj);
      message.success(`Ticker ${symbolToDelete} removed successfully.`);
      loadData();
    } catch (err) {
      message.error('Failed to delete ticker: ' + err.message);
    }
  };

  const handleSaveTicker = async (values) => {
    try {
      const formattedTicker = {
        symbol: values.symbol.toUpperCase().trim(),
        alias: (values.alias || values.symbol).toUpperCase().trim(),
        limit: parseInt(values.limit || 3, 10),
        status: values.status ? 'active' : 'inactive'
      };

      let updatedTickersList = [];

      if (editingTicker) {
        // Edit existing
        updatedTickersList = settings.tickers.map(t => {
          if (t.symbol.toUpperCase() === editingTicker.symbol.toUpperCase()) {
            return formattedTicker;
          }
          return t;
        });
      } else {
        // Check duplicate symbol
        const exists = settings.tickers.some(
          t => t.symbol.toUpperCase() === formattedTicker.symbol
        );
        if (exists) {
          message.error(`Ticker symbol "${formattedTicker.symbol}" is already configured.`);
          return;
        }
        // Add new
        updatedTickersList = [...settings.tickers, formattedTicker];
      }

      const newSettingsObj = {
        ...settings,
        tickers: updatedTickersList
      };

      await api.post('/settings', newSettingsObj);
      message.success(editingTicker ? 'Ticker updated successfully.' : 'New ticker added successfully.');
      setModalOpen(false);
      loadData();
    } catch (err) {
      message.error('Failed to save ticker: ' + err.message);
    }
  };

  const columns = [
    {
      title: 'Ticker Symbol',
      dataIndex: 'symbol',
      key: 'symbol',
      render: (text) => <Tag color="blue" style={{ fontWeight: 'bold', fontSize: '13px' }}>{text}</Tag>
    },
    {
      title: 'Target / Alias Page Name',
      dataIndex: 'alias',
      key: 'alias',
      render: (text) => <span style={{ fontWeight: 500 }}>{text}</span>
    },
    {
      title: 'Daily Post Limit',
      dataIndex: 'limit',
      key: 'limit',
      render: (val) => `${val} articles/day`
    },
    {
      title: 'Today\'s Progress',
      key: 'progress',
      render: (_, record) => {
        const percent = Math.min(100, Math.round((record.today_published / record.limit) * 100));
        let status = 'normal';
        if (percent >= 100) status = 'success';
        return (
          <div style={{ width: '160px' }}>
            <Progress 
              percent={percent} 
              size="small" 
              status={status} 
              format={() => `${record.today_published}/${record.limit}`} 
            />
          </div>
        );
      }
    },
    {
      title: 'All Time Published',
      dataIndex: 'total_published',
      key: 'total_published'
    },
    {
      title: 'Status',
      dataIndex: 'status',
      key: 'status',
      render: (status) => (
        <Badge 
          status={status === 'active' ? 'success' : 'error'} 
          text={status === 'active' ? 'Active' : 'Inactive'} 
        />
      )
    },
    {
      title: 'Action',
      key: 'action',
      render: (_, record) => (
        <Space size="middle">
          <Button 
            type="text" 
            icon={<EditOutlined />} 
            onClick={() => handleOpenEditModal(record)}
            style={{ color: '#1890ff' }}
          >
            Edit
          </Button>
          <Popconfirm
            title="Are you sure you want to remove this ticker?"
            description="All publication records will be kept, but automatic news crawls for this ticker will stop immediately."
            onConfirm={() => handleDeleteTicker(record.symbol)}
            okText="Delete"
            cancelText="Cancel"
            okButtonProps={{ danger: true }}
          >
            <Button type="text" danger icon={<DeleteOutlined />}>
              Remove
            </Button>
          </Popconfirm>
        </Space>
      )
    }
  ];

  return (
    <div className="cgm-fade-in-el">
      <Card 
        className="cgm-premium-card"
        title={<span style={{ fontSize: '18px', fontWeight: 600 }}>Stock Tickers Configuration</span>}
        extra={
          <Button 
            type="primary" 
            icon={<PlusOutlined />} 
            onClick={handleOpenAddModal}
            style={{ borderRadius: '6px' }}
          >
            Add New Ticker
          </Button>
        }
      >
        <Table 
          columns={columns} 
          dataSource={tickers} 
          rowKey="symbol" 
          loading={loading}
          pagination={false}
        />
      </Card>

      <Modal
        title={editingTicker ? "Edit Ticker Configuration" : "Add New Stock Ticker"}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={() => form.submit()}
        okText="Save Config"
        destroyOnClose
      >
        <Form
          form={form}
          layout="vertical"
          onFinish={handleSaveTicker}
          style={{ marginTop: '16px' }}
        >
          <Form.Item
            name="symbol"
            label="Ticker Symbol"
            rules={[
              { required: true, message: 'Please enter the ticker symbol.' },
              { pattern: /^[A-Z0-9.\-]+$/i, message: 'Invalid ticker symbol. Use alphanumeric characters, dots, or dashes.' }
            ]}
            extra="Example: DSEAF or AAPL.US (Format expected by EODHD)"
          >
            <Input disabled={!!editingTicker} placeholder="e.g. DSEAF" style={{ textTransform: 'uppercase' }} />
          </Form.Item>

          <Form.Item
            name="alias"
            label="Page Alias / Target Page Name"
            extra="The company name or slug representing this ticker on the frontend. Shortcodes will auto-detect matching terms on these pages."
          >
            <Input placeholder="e.g. SEAS" style={{ textTransform: 'uppercase' }} />
          </Form.Item>

          <Form.Item
            name="limit"
            label="Daily Publishing Limit"
            rules={[{ required: true, message: 'Please enter daily limit.' }]}
          >
            <InputNumber min={1} max={50} style={{ width: '100%' }} />
          </Form.Item>

          <Form.Item
            name="status"
            label="Active Crawling Status"
            valuePropName="checked"
          >
            <Switch checkedChildren="Active" unCheckedChildren="Inactive" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
