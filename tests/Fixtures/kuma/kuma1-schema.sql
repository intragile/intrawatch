CREATE TABLE [heartbeat](
  [id] INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
  [important] BOOLEAN NOT NULL DEFAULT 0, 
  [monitor_id] INTEGER NOT NULL REFERENCES [monitor]([id]) ON DELETE CASCADE ON UPDATE CASCADE, 
  [status] SMALLINT NOT NULL, 
  [msg] TEXT, 
  [time] DATETIME NOT NULL, 
  [ping] INTEGER, 
  [duration] INTEGER NOT NULL DEFAULT 0, down_count INTEGER default 0 not null);
CREATE TABLE sqlite_sequence(name,seq);
CREATE TABLE [user](
  [id] INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
  [username] VARCHAR(255) NOT NULL UNIQUE, 
  [password] VARCHAR(255), 
  [active] BOOLEAN NOT NULL DEFAULT 1, 
  [timezone] VARCHAR(150), twofa_secret VARCHAR(64), twofa_status BOOLEAN default 0 NOT NULL, twofa_last_token VARCHAR(6));
CREATE TABLE [notification](
  [id] INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
  [name] VARCHAR(255), 
  [active] BOOLEAN NOT NULL DEFAULT 1, 
  [user_id] INTEGER NOT NULL, is_default BOOLEAN default 0 NOT NULL, config TEXT);
CREATE TABLE [monitor_notification](
  [id] INTEGER PRIMARY KEY NOT NULL, 
  [monitor_id] INTEGER NOT NULL REFERENCES [monitor]([id]) ON DELETE CASCADE ON UPDATE CASCADE, 
  [notification_id] INTEGER NOT NULL REFERENCES [notification]([id]) ON DELETE CASCADE ON UPDATE CASCADE);
CREATE INDEX [monitor_id] ON [heartbeat]([monitor_id]);
CREATE INDEX [important] ON [heartbeat]([important]);
CREATE INDEX [monitor_notification_index]
ON [monitor_notification](
  [monitor_id], 
  [notification_id]);
CREATE TABLE monitor_tls_info (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	monitor_id INTEGER NOT NULL REFERENCES [monitor] ([id]) ON DELETE CASCADE ON UPDATE CASCADE,
	info_json TEXT
);
CREATE TABLE IF NOT EXISTS "monitor" (
	id INTEGER not null primary key autoincrement,
	name VARCHAR(150),
	active BOOLEAN default 1 not null,
	user_id INTEGER references user on update cascade on delete
	set
		null,
		interval INTEGER default 20 not null,
		url TEXT,
		type VARCHAR(20),
		weight INTEGER default 2000,
		hostname VARCHAR(255),
		port INTEGER,
		created_date DATETIME default (DATETIME('now')) not null,
		keyword VARCHAR(255),
		maxretries INTEGER NOT NULL DEFAULT 0,
		ignore_tls BOOLEAN default 0 not null,
		upside_down BOOLEAN default 0 not null,
        maxredirects INTEGER default 10 not null,
        accepted_statuscodes_json TEXT default '["200-299"]' not null
, dns_resolve_type VARCHAR(5), dns_resolve_server VARCHAR(255), dns_last_result VARCHAR(255), retry_interval INTEGER default 0 not null, push_token VARCHAR(20) DEFAULT NULL, method TEXT default 'GET' not null, body TEXT default null, headers TEXT default null, basic_auth_user TEXT default null, basic_auth_pass TEXT default null, docker_host INTEGER REFERENCES docker_host(id), docker_container VARCHAR(255), proxy_id INTEGER REFERENCES proxy(id), expiry_notification BOOLEAN default 1, mqtt_topic TEXT, mqtt_success_message VARCHAR(255), mqtt_username VARCHAR(255), mqtt_password VARCHAR(255), database_connection_string VARCHAR(2000), database_query TEXT, auth_method VARCHAR(250), auth_domain TEXT, auth_workstation TEXT, grpc_url VARCHAR(255) default null, grpc_protobuf TEXT default null, grpc_body TEXT default null, grpc_metadata TEXT default null, grpc_method VARCHAR(255) default null, grpc_service_name VARCHAR(255) default null, grpc_enable_tls BOOLEAN default 0 not null, radius_username VARCHAR(255), radius_password VARCHAR(255), radius_calling_station_id VARCHAR(50), radius_called_station_id VARCHAR(50), radius_secret VARCHAR(255), resend_interval INTEGER default 0 not null, packet_size INTEGER DEFAULT 56 NOT NULL, game VARCHAR(255), http_body_encoding VARCHAR(25), description TEXT default null, tls_ca TEXT default null, tls_cert TEXT default null, tls_key TEXT default null, parent INTEGER REFERENCES [monitor] ([id]) ON DELETE SET NULL ON UPDATE CASCADE, invert_keyword BOOLEAN default 0 not null, json_path TEXT, expected_value VARCHAR(255), kafka_producer_topic VARCHAR(255), kafka_producer_brokers TEXT, kafka_producer_sasl_options TEXT, kafka_producer_message TEXT, oauth_client_id TEXT default null, oauth_client_secret TEXT default null, oauth_token_url TEXT default null, oauth_scopes TEXT default null, oauth_auth_method TEXT default null, timeout DOUBLE default 0 not null, gamedig_given_port_only BOOLEAN default 1 not null, kafka_producer_ssl BOOLEAN default 0 NOT NULL, kafka_producer_allow_auto_topic_creation BOOLEAN default 0 NOT NULL);
CREATE INDEX user_id on monitor (user_id);
CREATE TABLE tag (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	name VARCHAR(255) NOT NULL,
    color VARCHAR(255) NOT NULL,
	created_date DATETIME DEFAULT (DATETIME('now')) NOT NULL
);
CREATE TABLE monitor_tag (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	monitor_id INTEGER NOT NULL,
	tag_id INTEGER NOT NULL,
	value TEXT,
	CONSTRAINT FK_tag FOREIGN KEY (tag_id) REFERENCES tag(id) ON DELETE CASCADE ON UPDATE CASCADE,
	CONSTRAINT FK_monitor FOREIGN KEY (monitor_id) REFERENCES monitor(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX monitor_tag_monitor_id_index ON monitor_tag (monitor_id);
CREATE INDEX monitor_tag_tag_id_index ON monitor_tag (tag_id);
CREATE TABLE IF NOT EXISTS "setting"
(
    id INTEGER
        primary key autoincrement,
    key VARCHAR(200) not null
        unique,
    value TEXT,
    type VARCHAR(20)
);
CREATE INDEX monitor_time_index ON heartbeat (monitor_id, time);
CREATE INDEX monitor_important_time_index ON heartbeat (monitor_id, important,time);
CREATE TABLE incident
(
    id INTEGER not null
        constraint incident_pk
            primary key autoincrement,
    title VARCHAR(255) not null,
    content TEXT not null,
    style VARCHAR(30) default 'warning' not null,
    created_date DATETIME default (DATETIME('now')) not null,
    last_updated_date DATETIME,
    pin BOOLEAN default 1 not null,
    active BOOLEAN default 1 not null
, status_page_id INTEGER);
CREATE TABLE `group`
(
    id           INTEGER      not null
        constraint group_pk
            primary key autoincrement,
    name         VARCHAR(255) not null,
    created_date DATETIME              default (DATETIME('now')) not null,
    public       BOOLEAN               default 0 not null,
    active       BOOLEAN               default 1 not null,
    weight       BOOLEAN      NOT NULL DEFAULT 1000
, status_page_id INTEGER);
CREATE TABLE [monitor_group]
(
    [id]         INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    [monitor_id] INTEGER                           NOT NULL REFERENCES [monitor] ([id]) ON DELETE CASCADE ON UPDATE CASCADE,
    [group_id]   INTEGER                           NOT NULL REFERENCES [group] ([id]) ON DELETE CASCADE ON UPDATE CASCADE,
    weight BOOLEAN NOT NULL DEFAULT 1000
, send_url BOOLEAN DEFAULT 0 NOT NULL);
CREATE INDEX [fk]
    ON [monitor_group] (
                        [monitor_id],
                        [group_id]);
CREATE TABLE [notification_sent_history] (
    [id] INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    [type] VARCHAR(50) NOT NULL,
    [monitor_id] INTEGER NOT NULL,
    [days] INTEGER NOT NULL,
    UNIQUE([type], [monitor_id], [days])
);
CREATE INDEX [good_index] ON [notification_sent_history] (
    [type],
    [monitor_id],
    [days]
);
CREATE TABLE docker_host (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	user_id INT NOT NULL,
	docker_daemon VARCHAR(255),
	docker_type VARCHAR(255),
	name VARCHAR(255)
);
CREATE TABLE [status_page](
    [id] INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    [slug] VARCHAR(255) NOT NULL UNIQUE,
    [title] VARCHAR(255) NOT NULL,
    [description] TEXT,
    [icon] VARCHAR(255) NOT NULL,
    [theme] VARCHAR(30) NOT NULL,
    [published] BOOLEAN NOT NULL DEFAULT 1,
    [search_engine_index] BOOLEAN NOT NULL DEFAULT 1,
    [show_tags] BOOLEAN NOT NULL DEFAULT 0,
    [password] VARCHAR,
    [created_date] DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    [modified_date] DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
, footer_text TEXT, custom_css TEXT, show_powered_by BOOLEAN NOT NULL DEFAULT 1, google_analytics_tag_id VARCHAR, show_certificate_expiry BOOLEAN default 0 NOT NULL);
CREATE UNIQUE INDEX [slug] ON [status_page]([slug]);
CREATE TABLE [status_page_cname](
    [id] INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    [status_page_id] INTEGER NOT NULL REFERENCES [status_page]([id]) ON DELETE CASCADE ON UPDATE CASCADE,
    [domain] VARCHAR NOT NULL UNIQUE
);
CREATE TABLE proxy (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    user_id INT NOT NULL,
    protocol VARCHAR(10) NOT NULL,
    host VARCHAR(255) NOT NULL,
    port SMALLINT NOT NULL,
    auth BOOLEAN NOT NULL,
    username VARCHAR(255) NULL,
    password VARCHAR(255) NULL,
    active BOOLEAN NOT NULL DEFAULT 1,
    'default' BOOLEAN NOT NULL DEFAULT 0,
    created_date DATETIME DEFAULT (DATETIME('now')) NOT NULL
);
CREATE INDEX proxy_id ON monitor (proxy_id);
CREATE INDEX proxy_user_id ON proxy (user_id);
CREATE TABLE [maintenance] (
    [id] INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    [title] VARCHAR(150) NOT NULL,
    [description] TEXT NOT NULL,
    [user_id] INTEGER REFERENCES [user]([id]) ON DELETE SET NULL ON UPDATE CASCADE,
    [active] BOOLEAN NOT NULL DEFAULT 1,
    [strategy] VARCHAR(50) NOT NULL DEFAULT 'single',
    [start_date] DATETIME,
    [end_date] DATETIME,
    [start_time] TIME,
    [end_time] TIME,
    [weekdays] VARCHAR2(250) DEFAULT '[]',
    [days_of_month] TEXT DEFAULT '[]',
    [interval_day] INTEGER
, cron TEXT, timezone VARCHAR(255), duration INTEGER);
CREATE INDEX [manual_active] ON [maintenance] (
    [strategy],
    [active]
);
CREATE INDEX [active] ON [maintenance] ([active]);
CREATE INDEX [maintenance_user_id] ON [maintenance] ([user_id]);
CREATE TABLE maintenance_status_page (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    status_page_id INTEGER NOT NULL,
    maintenance_id INTEGER NOT NULL,
    CONSTRAINT FK_maintenance FOREIGN KEY (maintenance_id) REFERENCES maintenance (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT FK_status_page FOREIGN KEY (status_page_id) REFERENCES status_page (id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX [status_page_id_index]
    ON [maintenance_status_page]([status_page_id]);
CREATE INDEX [maintenance_id_index]
    ON [maintenance_status_page]([maintenance_id]);
CREATE TABLE monitor_maintenance (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    maintenance_id INTEGER NOT NULL,
    CONSTRAINT FK_maintenance FOREIGN KEY (maintenance_id) REFERENCES maintenance (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT FK_monitor FOREIGN KEY (monitor_id) REFERENCES monitor (id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX [maintenance_id_index2] ON [monitor_maintenance]([maintenance_id]);
CREATE INDEX [monitor_id_index] ON [monitor_maintenance]([monitor_id]);
CREATE TABLE [api_key] (
    [id] INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    [key] VARCHAR(255) NOT NULL,
    [name] VARCHAR(255) NOT NULL,
    [user_id] INTEGER NOT NULL,
    [created_date] DATETIME DEFAULT (DATETIME('now')) NOT NULL,
    [active] BOOLEAN DEFAULT 1 NOT NULL,
    [expires] DATETIME DEFAULT NULL,
    CONSTRAINT FK_user FOREIGN KEY ([user_id]) REFERENCES [user]([id]) ON DELETE CASCADE ON UPDATE CASCADE
);
