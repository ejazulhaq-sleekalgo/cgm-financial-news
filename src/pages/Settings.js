import React, { useState, useEffect } from 'react';
import { Card, Form, Input, Select, Switch, Button, Slider, Space, Collapse, message, Row, Col, Alert, Tooltip, Typography, Tag } from 'antd';
import { SettingOutlined, SafetyOutlined, TranslationOutlined, EyeOutlined, BuildOutlined, LinkOutlined } from '@ant-design/icons';
import { api } from '../utils/api';

const { Panel } = Collapse;
const { Text } = Typography;

export default function Settings() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testingEodhd, setTestingEodhd] = useState(false);
  const [testingOpenai, setTestingOpenai] = useState(false);
  const [translationEnabled, setTranslationEnabled] = useState(false);
  const [form] = Form.useForm();
  const [selectedOpenaiModel, setSelectedOpenaiModel] = useState('gpt-5.4-mini');

  const loadSettings = async () => {
    setLoading(true);
    try {
      const data = await api.get('/settings');
      form.setFieldsValue({
        ...data,
        // If keys exist but masked is sent, we let inputs display the masked placeholders.
        eodhd_api_key: data.eodhd_api_key_masked || '',
        openai_api_key: data.openai_api_key_masked || ''
      });
      setSelectedOpenaiModel(data.openai_model || 'gpt-5.4-mini');
      setTranslationEnabled(!!data.translation_enabled);
    } catch (err) {
      message.error('Failed to load plugin settings: ' + err.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadSettings();
  }, []);

  const handleSaveSettings = async (values) => {
    setSaving(true);
    try {
      const res = await api.post('/settings', values);
      message.success(res.message || 'Settings saved successfully!');
      // Reload settings to refresh the masked values.
      loadSettings();
    } catch (err) {
      message.error('Failed to save settings: ' + err.message);
    } finally {
      setSaving(false);
    }
  };

  const handleTestConnection = async (service) => {
    const isEodhd = service === 'eodhd';
    const setTesting = isEodhd ? setTestingEodhd : setTestingOpenai;

    // Read key directly from the form field.
    const keyField = isEodhd ? 'eodhd_api_key' : 'openai_api_key';
    const keyValue = form.getFieldValue(keyField);

    if (!keyValue) {
      message.warning(`Please enter an API Key to test.`);
      return;
    }

    setTesting(true);
    try {
      const res = await api.post('/test-connection', { service, key: keyValue });
      message.success(res.message || `Connection test passed!`);
    } catch (err) {
      message.error(`Connection test failed: ${err.message}`);
    } finally {
      setTesting(false);
    }
  };


  const MODEL_OPTIONS = [
    // ===================== PRO / HIGH-END =====================
    {
      label: (
        <Tooltip title="Highest reasoning quality for critical financial analysis and premium publishing workflows">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-5.5 Pro</span>
            <Tag>🏆 Maximum Accuracy</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-5.5-pro"
    },

    {
      label: (
        <Tooltip title="Premium-grade model for high-quality financial news rewriting and investor reports">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-5.5</span>
            <Tag>⭐ Premium Quality</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-5.5"
    },

    {
      label: (
        <Tooltip title="High-performance reasoning model for advanced financial content generation">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-5.4 Pro</span>
            <Tag>🔥 Advanced Pro</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-5.4-pro"
    },

    {
      label: (
        <Tooltip title="Strong general premium model for high-quality content generation">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-5.4</span>
            <Tag>💎 High Quality</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-5.4"
    },

    // ===================== BALANCED (RECOMMENDED) =====================
    {
      label: (
        <Tooltip title="Best balance of cost, speed, and quality for financial news rewriting, translation, and SEO content">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-5.4 Mini</span>
            <Tag>✅ Recommended (Best Balance)</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-5.4-mini"
    },

    // ===================== COST OPTIMIZED =====================
    {
      label: (
        <Tooltip title="Ultra low-cost model for large-scale processing like ticker extraction, tagging, and sentiment analysis">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-5.4 Nano</span>
            <Tag>⚡Ultra Low Cost</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-5.4-nano"
    },

    // ===================== GPT-4.1 FAMILY =====================
    {
      label: (
        <Tooltip title="Reliable general-purpose model for structured financial content and analysis">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-4.1</span>
            <Tag>Reliable General</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-4.1"
    },

    {
      label: (
        <Tooltip title="Fast and cost-efficient version of GPT-4.1 for lighter workloads">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-4.1 Mini</span>
            <Tag>Fast General</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-4.1-mini"
    },

    {
      label: (
        <Tooltip title="Very low-cost GPT-4.1 variant for classification and extraction tasks">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-4.1 Nano</span>
            <Tag>Economy</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-4.1-nano"
    },

    // ===================== GPT-4o FAMILY =====================
    {
      label: (
        <Tooltip title="Strong multimodal model, still widely used for general-purpose AI tasks">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-4o</span>
            <Tag>Legacy Smart</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-4o"
    },

    {
      label: (
        <Tooltip title="Fast and cost-efficient legacy model for simple rewriting and classification">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-4o Mini</span>
            <Tag>Legacy Fast</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-4o-mini"
    },

    // ===================== LEGACY =====================
    {
      label: (
        <Tooltip title="Old generation model. Only use for backward compatibility or fallback systems">
          <Space style={{ width: "100%", justifyContent: "space-between" }}>
            <span>GPT-3.5 Turbo</span>
            <Tag>Legacy Fallback</Tag>
          </Space>
        </Tooltip>
      ),
      value: "gpt-3.5-turbo"
    }
  ];

  return (
    <div className="cgm-fade-in-el">
      <Form
        form={form}
        layout="vertical"
        onFinish={handleSaveSettings}
        disabled={loading}
      >
        <Row gutter={[16, 16]}>
          <Col xs={24} md={16}>
            <Space direction="vertical" size="middle" style={{ display: 'flex', width: '100%' }}>

              {/* API Credentials */}
              <Card
                title={<Space><SafetyOutlined /><span>API Credentials</span></Space>}
                className="cgm-premium-card"
                loading={loading}
              >
                <Row gutter={16}>
                  <Col span={18}>
                    <Form.Item
                      name="eodhd_api_key"
                      label="EODHD API Key"
                      extra="Required to fetch market news articles. Get one at eodhd.com."
                    >
                      <Input.Password placeholder="Enter EODHD API Token..." />
                    </Form.Item>
                  </Col>
                  <Col span={6} style={{ display: 'flex', alignItems: 'center', paddingTop: '10px' }}>
                    <Button
                      type="dashed"
                      onClick={() => handleTestConnection('eodhd')}
                      loading={testingEodhd}
                      style={{ width: '100%' }}
                    >
                      Test EODHD
                    </Button>
                  </Col>
                </Row>

                <Row gutter={16} style={{ marginTop: '16px' }}>
                  <Col span={18}>
                    <Form.Item
                      name="openai_api_key"
                      label="OpenAI API Key"
                      extra="Required for AI content rewriting and factual audit checks. Get one at platform.openai.com."
                    >
                      <Input.Password placeholder="Enter OpenAI Secret Key..." />
                    </Form.Item>
                  </Col>
                  <Col span={6} style={{ display: 'flex', alignItems: 'center', paddingTop: '10px' }}>
                    <Button
                      type="dashed"
                      onClick={() => handleTestConnection('openai')}
                      loading={testingOpenai}
                      style={{ width: '100%' }}
                    >
                      Test OpenAI
                    </Button>
                  </Col>
                </Row>
              </Card>

              {/* Advanced prompt configurations */}
              <Card
                title={<Space><BuildOutlined /><span>AI Prompts Customization</span></Space>}
                className="cgm-premium-card"
                loading={loading}
              >
                <Collapse ghost>
                  <Panel header="News Rewrite System Prompt" key="1">
                    <Form.Item
                      name="prompt_template"
                      label="Rewriter Prompt Template"
                      extra="Customize the instruction template sent to OpenAI. Use placeholders: {ticker}, {source_title}, {source_content}."
                    >
                      <Input.TextArea rows={12} style={{ fontFamily: 'monospace', fontSize: '13px' }} />
                    </Form.Item>
                  </Panel>
                  <Panel header="Factual Consistency Audit Prompt" key="2">
                    <Form.Item
                      name="verify_prompt"
                      label="Verifier Prompt Template"
                      extra="Customize the fact checker prompt template. Use placeholders: {ticker}, {original_facts}, {rewritten_title}, {rewritten_content}."
                    >
                      <Input.TextArea rows={10} style={{ fontFamily: 'monospace', fontSize: '13px' }} />
                    </Form.Item>
                  </Panel>
                </Collapse>
              </Card>
            </Space>
          </Col>

          <Col xs={24} md={8}>
            <Space direction="vertical" size="middle" style={{ display: 'flex', width: '100%' }}>

              {/* Publishing config */}
              <Card
                title={<Space><SettingOutlined /><span>AI Processing Settings</span></Space>}
                className="cgm-premium-card"
                loading={loading}
              >
                <Form.Item
                  name="openai_model"
                  label="OpenAI LLM Model"
                  rules={[{ required: true }]}
                  extra={selectedOpenaiModel ?
                    <Text type="secondary" italic>
                      For full specifications and capabilities, please refer to the selected model OpenAI <a href={`https://developers.openai.com/api/docs/models/${selectedOpenaiModel}`} target="_blank">
                        View Official Documentation
                      </a>
                    </Text>
                    : ''}
                >
                  <Select
                    options={MODEL_OPTIONS}
                    onChange={(value) => {
                      setSelectedOpenaiModel(value);
                    }}
                  />
                </Form.Item>

                <Form.Item
                  name="publishing_status"
                  label="Default Post Status"
                  rules={[{ required: true }]}
                  extra="Published articles can either go directly live or be saved as drafts for editorial review."
                >
                  <Select
                    options={[
                      { label: 'Publish Immediately (Live)', value: 'publish' },
                      { label: 'Save as Draft (Review Required)', value: 'draft' }
                    ]}
                  />
                </Form.Item>

                <Form.Item
                  name="min_relevance"
                  label="Min Article Relevance Score"
                  extra="Articles below this rating (out of 10) will be skipped as irrelevant."
                >
                  <Row gutter={16} align="middle">
                    <Col span={16}>
                      <Form.Item name="min_relevance" noStyle>
                        <Slider min={1} max={10} />
                      </Form.Item>
                    </Col>
                    <Col span={8}>
                      <Form.Item name="min_relevance" noStyle>
                        <Input style={{ textAlign: 'center' }} disabled />
                      </Form.Item>
                    </Col>
                  </Row>
                </Form.Item>
              </Card>

              {/* Translation configs */}
              <Card
                title={<Space><TranslationOutlined /><span>Multilingual Settings</span></Space>}
                className="cgm-premium-card"
                loading={loading}
              >
                <Form.Item
                  name="translation_enabled"
                  label="Enable Translation Support"
                  valuePropName="checked"
                >
                  <Switch
                    checkedChildren="Enabled"
                    unCheckedChildren="Disabled"
                    onChange={setTranslationEnabled}
                  />
                </Form.Item>

                <Form.Item
                  name="translation_lang"
                  label="Target Language"
                  hidden={!translationEnabled}
                >
                  <Select
                    options={[
                      { label: 'German (de) - For AW Websites', value: 'de' },
                      { label: 'Spanish (es)', value: 'es' },
                      { label: 'French (fr)', value: 'fr' },
                      { label: 'Italian (it)', value: 'it' },
                      { label: 'Dutch (nl)', value: 'nl' },
                      { label: 'Japanese (ja)', value: 'ja' },
                      { label: 'Chinese (zh)', value: 'zh' }
                    ]}
                  />
                </Form.Item>
              </Card>

              {/* Save changes action */}
              <Card className="cgm-premium-card">
                <Button
                  type="primary"
                  htmlType="submit"
                  size="large"
                  loading={saving}
                  style={{ width: '100%', borderRadius: '8px' }}
                >
                  Save Plugin Settings
                </Button>
              </Card>

            </Space>
          </Col>
        </Row>
      </Form>
    </div>
  );
}
