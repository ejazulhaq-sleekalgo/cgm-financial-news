import React, { useState, useEffect } from 'react';
import { Card, Col, Row, Statistic, Button, Table, Tag, Progress, Space, Alert, message, Badge } from 'antd';
import { 
  CheckCircleOutlined, 
  CloseCircleOutlined, 
  SyncOutlined, 
  ArrowRightOutlined, 
  InfoCircleOutlined, 
  CalendarOutlined, 
  FileTextOutlined 
} from '@ant-design/icons';
import { api } from '../utils/api';

export default function Dashboard() {
  const [loading, setLoading] = useState(true);
  const [fetchingNews, setFetchingNews] = useState(false);
  const [stats, setStats] = useState({
    total_published: 0,
    total_failed: 0,
    total_pending: 0,
    total_skipped: 0,
    today_published: 0,
    success_rate: 100,
    scheduler: { cron_scheduled: false, next_run: null, action_scheduler_active: false },
    recent_articles: []
  });

  const loadStats = async () => {
    setLoading(true);
    try {
      const data = await api.get('/stats');
      setStats(data);
    } catch (err) {
      message.error('Failed to load dashboard statistics: ' + err.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadStats();
  }, []);

  const handleFetchNews = async () => {
    setFetchingNews(true);
    try {
      const res = await api.post('/queue/fetch');
      message.success(res.message || 'News fetch triggered successfully!');
      loadStats();
    } catch (err) {
      message.error('Failed to fetch news: ' + err.message);
    } finally {
      setFetchingNews(false);
    }
  };

  const getSentimentTag = (sentiment) => {
    const s = sentiment ? sentiment.toLowerCase() : '';
    if (s.includes('pos')) return <Tag color="success">Positive</Tag>;
    if (s.includes('neg')) return <Tag color="error">Negative</Tag>;
    return <Tag color="default">Neutral</Tag>;
  };

  const columns = [
    {
      title: 'Ticker',
      dataIndex: 'ticker',
      key: 'ticker',
      render: (text) => <Tag color="blue" style={{ fontWeight: 'bold' }}>{text}</Tag>
    },
    {
      title: 'Headline',
      dataIndex: 'title',
      key: 'title',
      render: (text, record) => (
        <a href={record.view_url} target="_blank" rel="noopener noreferrer" style={{ fontWeight: 500 }}>
          {text}
        </a>
      )
    },
    {
      title: 'Date Published',
      dataIndex: 'date',
      key: 'date'
    },
    {
      title: 'Sentiment',
      dataIndex: 'sentiment',
      key: 'sentiment',
      render: (text) => getSentimentTag(text)
    },
    {
      title: 'Relevance',
      dataIndex: 'relevance',
      key: 'relevance',
      render: (val) => <span className="cgm-importance-badge">{val}/10</span>
    },
    {
      title: 'Action',
      key: 'action',
      render: (_, record) => (
        <Space size="middle">
          <a href={record.edit_url} target="_blank" rel="noopener noreferrer">Edit Post</a>
        </Space>
      )
    }
  ];

  return (
    <div className="cgm-fade-in-el">
      <Row gutter={[16, 16]}>
        <Col span={24}>
          <Card className="cgm-premium-card">
            <Row justify="between" align="middle" style={{ display: 'flex', width: '100%' }}>
              <Col flex="auto">
                <h1 style={{ margin: 0, fontSize: '24px', fontWeight: '700', color: '#0f172a' }}>
                  CGM Financial News Dashboard
                </h1>
                <p style={{ margin: '4px 0 0 0', color: '#64748b' }}>
                  Monitor automated news crawling, AI rewriting, and stock taxonomy synchronization.
                </p>
              </Col>
              <Col>
                <Button 
                  type="primary" 
                  icon={<SyncOutlined spin={fetchingNews} />} 
                  onClick={handleFetchNews} 
                  loading={fetchingNews}
                  size="large"
                  style={{ borderRadius: '8px' }}
                >
                  Trigger Manual News Crawl
                </Button>
              </Col>
            </Row>
          </Card>
        </Col>

        {/* Stats Cards */}
        <Col xs={24} sm={12} md={6}>
          <Card className="cgm-premium-card" loading={loading}>
            <Statistic
              title="Published Articles"
              value={stats.total_published}
              prefix={<CheckCircleOutlined style={{ color: '#52c41a' }} />}
            />
            <div style={{ marginTop: '8px', color: '#64748b', fontSize: '12px' }}>
              Successfully rewritten & live
            </div>
          </Card>
        </Col>

        <Col xs={24} sm={12} md={6}>
          <Card className="cgm-premium-card" loading={loading}>
            <Statistic
              title="Failed Processing"
              value={stats.total_failed}
              prefix={<CloseCircleOutlined style={{ color: '#ff4d4f' }} />}
            />
            <div style={{ marginTop: '8px', color: '#64748b', fontSize: '12px' }}>
              Factual errors or API issues
            </div>
          </Card>
        </Col>

        <Col xs={24} sm={12} md={6}>
          <Card className="cgm-premium-card" loading={loading}>
            <Statistic
              title="Irrelevant / Skipped"
              value={stats.total_skipped}
              prefix={<InfoCircleOutlined style={{ color: '#1890ff' }} />}
            />
            <div style={{ marginTop: '8px', color: '#64748b', fontSize: '12px' }}>
              Below relevance threshold
            </div>
          </Card>
        </Col>

        <Col xs={24} sm={12} md={6}>
          <Card className="cgm-premium-card" loading={loading}>
            <Statistic
              title="Success Rate"
              value={stats.success_rate}
              suffix="%"
              prefix={<Progress type="circle" percent={stats.success_rate} width={20} showInfo={false} strokeColor="#52c41a" />}
            />
            <div style={{ marginTop: '8px', color: '#64748b', fontSize: '12px' }}>
              Published vs Failed attempts
            </div>
          </Card>
        </Col>

        {/* Queue and Scheduler info */}
        <Col xs={24} md={16}>
          <Card title="Recent Rewritten Articles" className="cgm-premium-card" loading={loading} style={{ height: '100%' }}>
            <Table 
              columns={columns} 
              dataSource={stats.recent_articles} 
              rowKey="id" 
              pagination={false} 
              locale={{ emptyText: 'No rewritten articles published yet.' }}
            />
          </Card>
        </Col>

        <Col xs={24} md={8}>
          <Space direction="vertical" size="middle" style={{ display: 'flex', width: '100%', height: '100%' }}>
            <Card title="Background Task Scheduler" className="cgm-premium-card" loading={loading}>
              {stats.scheduler.action_scheduler_active ? (
                <div>
                  <Alert 
                    message="Action Scheduler Active" 
                    type="success" 
                    showIcon 
                    description="Background queues are running correctly."
                  />
                  <div style={{ marginTop: '16px' }}>
                    <Space style={{ color: '#475569' }}>
                      <CalendarOutlined />
                      <span>Next news check scheduled:</span>
                    </Space>
                    <div style={{ fontWeight: 'bold', fontSize: '16px', marginTop: '4px', color: '#0f172a' }}>
                      {stats.scheduler.next_run ? stats.scheduler.next_run : 'ASAP'}
                    </div>
                  </div>
                </div>
              ) : (
                <Alert 
                  message="Action Scheduler Missing" 
                  type="warning" 
                  showIcon 
                  description="Action Scheduler library was not detected. The plugin will execute tasks synchronously (slower and prone to page timeouts)."
                />
              )}
            </Card>

            <Card title="Queue Queue Status" className="cgm-premium-card" loading={loading}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ color: '#475569' }}>Pending in queue:</span>
                <Badge count={stats.total_pending} showZero color="#faad14" overflowCount={999} style={{ fontSize: '14px', padding: '0 8px' }} />
              </div>
              <div style={{ marginTop: '12px', color: '#64748b', fontSize: '12px' }}>
                These articles are waiting to be processed by OpenAI and published.
              </div>
            </Card>
          </Space>
        </Col>
      </Row>
    </div>
  );
}
