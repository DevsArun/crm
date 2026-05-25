-- ============================================================
-- WhatsApp CRM + Cold Outreach OS — Seed Data
-- Run AFTER schema.sql
-- ============================================================

-- ============================================================
-- DEFAULT SETTINGS
-- ============================================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `label`, `group_name`) VALUES

-- API Configuration
('groq_api_key',        '',                                     'string',  'Groq API Key',              'api'),
('groq_model',          'llama3-70b-8192',                      'string',  'Groq Model',                'api'),
('node_api_url',        'https://your-space.hf.space',          'string',  'HF Node API URL',           'api'),
('node_api_key',        '',                                     'string',  'Node API Key',              'api'),
('socket_url',          'https://your-space.hf.space',          'string',  'Socket.io Server URL',      'api'),
('webhook_secret',      '',                                     'string',  'Webhook Secret',            'api'),

-- Campaign Settings
('campaign_delay_min',  '120',                                  'integer', 'Min Delay (seconds)',       'campaign'),
('campaign_delay_max',  '300',                                  'integer', 'Max Delay (seconds)',       'campaign'),
('campaign_daily_limit','50',                                   'integer', 'Daily Send Limit',          'campaign'),
('campaign_retry_limit','3',                                    'integer', 'Max Retry Attempts',        'campaign'),
('auto_stop_on_reply',  '1',                                    'boolean', 'Auto Stop on Reply',        'campaign'),

-- App Settings
('app_name',            'OutreachOS',                           'string',  'App Name',                  'app'),
('app_tagline',         'WhatsApp CRM + Cold Outreach System',  'string',  'App Tagline',               'app'),
('dark_mode',           '1',                                    'boolean', 'Dark Mode',                 'app'),
('notification_sound',  '1',                                    'boolean', 'Notification Sound',        'app'),
('timezone',            'Asia/Kolkata',                         'string',  'Timezone',                  'app'),

-- Security
('webhook_ip_whitelist','',                                     'string',  'Webhook IP Whitelist (CSV)','security'),
('session_timeout',     '3600',                                 'integer', 'Session Timeout (seconds)', 'security'),
('admin_username',      'admin',                                'string',  'Admin Username',            'security'),
('admin_password',      '$2y$10$defaultHashToBeChangedOnSetup', 'string',  'Admin Password (hashed)',   'security'),

-- Feature Flags
('feature_ai_generation','1',                                   'boolean', 'Enable AI Message Generation','features'),
('feature_validation',  '1',                                    'boolean', 'Enable WA Validation',      'features'),
('feature_campaign',    '1',                                    'boolean', 'Enable Campaign Automation', 'features'),
('feature_logs',        '1',                                    'boolean', 'Enable System Logs',        'features'),

-- Logging
('log_retention_days',  '30',                                   'integer', 'Log Retention (days)',      'logging'),
('log_level',           'info',                                 'string',  'Log Level',                 'logging'),

-- User Profile (Seller Services)
('seller_name',         'Your Name',                            'string',  'Your Name',                 'profile'),
('seller_services',     '["Landing Pages","Business Websites","eCommerce Websites","Custom Web Apps","AI Agents","Automation Systems","Android Apps","Chrome Extensions","Digital Marketing"]', 'json', 'Services Offered', 'profile'),
('seller_portfolio_url','',                                     'string',  'Portfolio URL',             'profile'),
('seller_cta_text',     'Let me know if you are interested.',   'string',  'Default CTA Text',          'profile')

ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);


-- ============================================================
-- DEFAULT WHATSAPP SESSION ROW
-- ============================================================
INSERT IGNORE INTO `whatsapp_sessions` (`session_id`, `status`)
VALUES ('default', 'disconnected');


-- ============================================================
-- DEFAULT CAMPAIGN (blank starter)
-- ============================================================
INSERT IGNORE INTO `campaigns` (`id`, `name`, `description`, `status`, `daily_limit`, `delay_min`, `delay_max`)
VALUES (1, 'Campaign #1', 'Default outreach campaign', 'draft', 50, 120, 300);
