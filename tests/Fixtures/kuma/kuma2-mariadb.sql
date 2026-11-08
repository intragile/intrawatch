/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.19-MariaDB, for debian-linux-gnu (aarch64)
--
-- Host: localhost    Database: kuma
-- ------------------------------------------------------
-- Server version	10.11.19-MariaDB-0+deb12u1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `api_key`
--

DROP TABLE IF EXISTS `api_key`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_key` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `expires` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `api_key_user_id_foreign` (`user_id`),
  CONSTRAINT `api_key_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `api_key`
--

LOCK TABLES `api_key` WRITE;
/*!40000 ALTER TABLE `api_key` DISABLE KEYS */;
INSERT INTO `api_key` VALUES
(1,'$2a$10$9UvaDnYtloiVs3J7zA3v7ux0beUwwSp.X2cb5UpEumuttgeSY1Fym','importer',1,'2026-11-07 22:52:24',1,NULL);
/*!40000 ALTER TABLE `api_key` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `docker_host`
--

DROP TABLE IF EXISTS `docker_host`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `docker_host` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `docker_daemon` varchar(255) DEFAULT NULL,
  `docker_type` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `docker_host`
--

LOCK TABLES `docker_host` WRITE;
/*!40000 ALTER TABLE `docker_host` DISABLE KEYS */;
INSERT INTO `docker_host` VALUES
(1,1,'/var/run/docker.sock','socket','local docker');
/*!40000 ALTER TABLE `docker_host` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `domain_expiry`
--

DROP TABLE IF EXISTS `domain_expiry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_expiry` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `last_check` datetime DEFAULT NULL,
  `domain` varchar(255) NOT NULL,
  `expiry` datetime DEFAULT NULL,
  `last_expiry_notification_sent` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_expiry_domain_unique` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `domain_expiry`
--

LOCK TABLES `domain_expiry` WRITE;
/*!40000 ALTER TABLE `domain_expiry` DISABLE KEYS */;
/*!40000 ALTER TABLE `domain_expiry` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `group`
--

DROP TABLE IF EXISTS `group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `group` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  `public` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `weight` int(11) NOT NULL DEFAULT 1000,
  `status_page_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `group`
--

LOCK TABLES `group` WRITE;
/*!40000 ALTER TABLE `group` DISABLE KEYS */;
INSERT INTO `group` VALUES
(1,'Web','2026-11-07 22:52:24',1,1,1,1),
(2,'Infra','2026-11-07 22:52:24',1,1,2,1);
/*!40000 ALTER TABLE `group` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `heartbeat`
--

DROP TABLE IF EXISTS `heartbeat`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `heartbeat` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `important` tinyint(1) NOT NULL DEFAULT 0,
  `monitor_id` int(10) unsigned NOT NULL,
  `status` smallint(6) NOT NULL,
  `msg` text DEFAULT NULL,
  `time` datetime NOT NULL,
  `ping` bigint(20) DEFAULT NULL,
  `duration` int(11) NOT NULL DEFAULT 0,
  `down_count` int(11) NOT NULL DEFAULT 0,
  `end_time` datetime DEFAULT NULL,
  `retries` int(11) NOT NULL DEFAULT 0,
  `response` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `heartbeat_important_index` (`important`),
  KEY `monitor_time_index` (`monitor_id`,`time`),
  KEY `heartbeat_monitor_id_index` (`monitor_id`),
  KEY `monitor_important_time_index` (`monitor_id`,`important`,`time`),
  CONSTRAINT `heartbeat_monitor_id_foreign` FOREIGN KEY (`monitor_id`) REFERENCES `monitor` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `heartbeat`
--

LOCK TABLES `heartbeat` WRITE;
/*!40000 ALTER TABLE `heartbeat` DISABLE KEYS */;
INSERT INTO `heartbeat` VALUES
(1,1,1,2,'Pending child monitors: Example HTTP','2026-11-07 22:52:24',5,0,0,'2026-11-07 22:52:24',0,NULL),
(2,1,7,1,'','2026-11-07 22:52:24',0,0,0,'2026-11-07 22:52:24',0,NULL),
(3,1,6,1,'36 ms','2026-11-07 22:52:24',36,0,0,'2026-11-07 22:52:24',0,NULL),
(4,1,2,1,'200 - OK','2026-11-07 22:52:24',124,0,0,'2026-11-07 22:52:24',0,NULL),
(5,1,3,1,'200 - OK, keyword is found','2026-11-07 22:52:24',101,0,0,'2026-11-07 22:52:24',0,NULL),
(6,1,8,1,'Records: 104.20.23.154 | 172.66.147.243','2026-11-07 22:52:24',23,0,0,'2026-11-07 22:52:24',0,NULL),
(7,1,4,1,'JSON query passes (comparing Yours Truly == Yours Truly)','2026-11-07 22:52:24',408,0,0,'2026-11-07 22:52:24',0,NULL);
/*!40000 ALTER TABLE `heartbeat` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `incident`
--

DROP TABLE IF EXISTS `incident`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `style` varchar(30) NOT NULL DEFAULT 'warning',
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  `last_updated_date` datetime DEFAULT NULL,
  `pin` tinyint(1) NOT NULL DEFAULT 1,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `status_page_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `incident`
--

LOCK TABLES `incident` WRITE;
/*!40000 ALTER TABLE `incident` DISABLE KEYS */;
INSERT INTO `incident` VALUES
(1,'Degraded API','Investigating','warning','2026-11-07 22:52:24',NULL,1,1,1);
/*!40000 ALTER TABLE `incident` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `knex_migrations`
--

DROP TABLE IF EXISTS `knex_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `knex_migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `batch` int(11) DEFAULT NULL,
  `migration_time` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `knex_migrations`
--

LOCK TABLES `knex_migrations` WRITE;
/*!40000 ALTER TABLE `knex_migrations` DISABLE KEYS */;
INSERT INTO `knex_migrations` VALUES
(1,'2023-08-16-0000-create-uptime.js',1,'2026-11-07 22:52:03'),
(2,'2023-08-18-0301-heartbeat.js',1,'2026-11-07 22:52:03'),
(3,'2023-09-29-0000-heartbeat-retires.js',1,'2026-11-07 22:52:03'),
(4,'2023-10-08-0000-mqtt-query.js',1,'2026-11-07 22:52:03'),
(5,'2023-10-11-1915-push-token-to-32.js',1,'2026-11-07 22:52:03'),
(6,'2023-10-16-0000-create-remote-browsers.js',1,'2026-11-07 22:52:03'),
(7,'2023-12-20-0000-alter-status-page.js',1,'2026-11-07 22:52:03'),
(8,'2023-12-21-0000-stat-ping-min-max.js',1,'2026-11-07 22:52:03'),
(9,'2023-12-22-0000-hourly-uptime.js',1,'2026-11-07 22:52:03'),
(10,'2024-01-22-0000-stats-extras.js',1,'2026-11-07 22:52:03'),
(11,'2024-04-26-0000-snmp-monitor.js',1,'2026-11-07 22:52:03'),
(12,'2024-08-24-000-add-cache-bust.js',1,'2026-11-07 22:52:03'),
(13,'2024-08-24-0000-conditions.js',1,'2026-11-07 22:52:03'),
(14,'2024-10-1315-rabbitmq-monitor.js',1,'2026-11-07 22:52:03'),
(15,'2024-10-31-0000-fix-snmp-monitor.js',1,'2026-11-07 22:52:03'),
(16,'2024-11-27-1927-fix-info-json-data-type.js',1,'2026-11-07 22:52:03'),
(17,'2025-01-01-0000-add-smtp.js',1,'2026-11-07 22:52:03'),
(18,'2025-02-15-2312-add-wstest.js',1,'2026-11-07 22:52:03'),
(19,'2025-02-17-2142-generalize-analytics.js',1,'2026-11-07 22:52:03'),
(20,'2025-03-04-0000-ping-advanced-options.js',1,'2026-11-07 22:52:03'),
(21,'2025-03-25-0127-fix-5721.js',1,'2026-11-07 22:52:03'),
(22,'2025-05-09-0000-add-custom-url.js',1,'2026-11-07 22:52:03'),
(23,'2025-06-03-0000-add-ip-family.js',1,'2026-11-07 22:52:03'),
(24,'2025-06-11-0000-add-manual-monitor.js',1,'2026-11-07 22:52:03'),
(25,'2025-06-13-0000-maintenance-add-last-start.js',1,'2026-11-07 22:52:03'),
(26,'2025-06-15-0001-manual-monitor-fix.js',1,'2026-11-07 22:52:03'),
(27,'2025-06-24-0000-add-audience-to-oauth.js',1,'2026-11-07 22:52:03'),
(28,'2025-07-17-0000-mqtt-websocket-path.js',1,'2026-11-07 22:52:03'),
(29,'2025-09-02-0000-add-domain-expiry.js',1,'2026-11-07 22:52:03'),
(30,'2025-10-14-0000-add-ip-family-fix.js',1,'2026-11-07 22:52:03'),
(31,'2025-10-15-0000-stat-table-fix.js',1,'2026-11-07 22:52:03'),
(32,'2025-10-15-0001-add-monitor-response-config.js',1,'2026-11-07 22:52:03'),
(33,'2025-10-15-0002-add-response-to-heartbeat.js',1,'2026-11-07 22:52:03'),
(34,'2025-10-24-0000-show-only-last-heartbeat.js',1,'2026-11-07 22:52:03'),
(35,'2025-12-09-0000-add-system-service-monitor.js',1,'2026-11-07 22:52:03'),
(36,'2025-12-17-0000-add-globalping-monitor.js',1,'2026-11-07 22:52:03'),
(37,'2025-12-22-0121-optimize-important-indexes.js',1,'2026-11-07 22:52:03'),
(38,'2025-12-29-0000-remove-line-notify.js',1,'2026-11-07 22:52:03'),
(39,'2025-12-31-2143-add-snmp-v3-username.js',1,'2026-11-07 22:52:03'),
(40,'2026-01-02-0551-dns-last-result-to-text.js',1,'2026-11-07 22:52:03'),
(41,'2026-01-02-0713-gamedig-v4-to-v5.js',1,'2026-11-07 22:52:03'),
(42,'2026-01-05-0000-add-rss-title.js',1,'2026-11-07 22:52:03'),
(43,'2026-01-05-0000-add-tls-monitor.js',1,'2026-11-07 22:52:03'),
(44,'2026-01-06-0000-fix-domain-expiry-column-type.js',1,'2026-11-07 22:52:03'),
(45,'2026-01-10-0000-convert-float-precision.js',1,'2026-11-07 22:52:03'),
(46,'2026-01-15-0000-add-json-query-retry-only-status-code.js',1,'2026-11-07 22:52:03'),
(47,'2026-01-16-0000-add-screenshot-delay.js',1,'2026-11-07 22:52:03'),
(48,'2026-02-07-0000-disable-domain-expiry-unsupported-tlds.js',1,'2026-11-07 22:52:03'),
(49,'2026-03-27-0000-add-ntp-monitor.js',1,'2026-11-07 22:52:03'),
(50,'2026-05-20-0000-add-bearer-token.js',1,'2026-11-07 22:52:03'),
(51,'2026-05-25-0000-add-gamedig-token.js',1,'2026-11-07 22:52:03'),
(52,'2026-06-19-1411-add-rybbit-analytics-type.js',1,'2026-11-07 22:52:03'),
(53,'2026-06-19-1412-analytics-type-to-string.js',1,'2026-11-07 22:52:03'),
(54,'2026-07-22-0000-fix-stat-daily-overflow.js',1,'2026-11-07 22:52:03'),
(55,'2026-08-07-0000-add-sftp-monitor.js',1,'2026-11-07 22:52:03'),
(56,'2026-08-18-0000-sqlite-only-drop-analytics-type-check.js',1,'2026-11-07 22:52:03');
/*!40000 ALTER TABLE `knex_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `knex_migrations_lock`
--

DROP TABLE IF EXISTS `knex_migrations_lock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `knex_migrations_lock` (
  `index` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `is_locked` int(11) DEFAULT NULL,
  PRIMARY KEY (`index`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `knex_migrations_lock`
--

LOCK TABLES `knex_migrations_lock` WRITE;
/*!40000 ALTER TABLE `knex_migrations_lock` DISABLE KEYS */;
INSERT INTO `knex_migrations_lock` VALUES
(1,0);
/*!40000 ALTER TABLE `knex_migrations_lock` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenance`
--

DROP TABLE IF EXISTS `maintenance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `strategy` varchar(50) NOT NULL DEFAULT 'single',
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `weekdays` varchar(250) DEFAULT '[]',
  `days_of_month` text DEFAULT '[]',
  `interval_day` int(11) DEFAULT NULL,
  `cron` text DEFAULT NULL,
  `timezone` varchar(255) DEFAULT NULL,
  `duration` int(11) DEFAULT NULL,
  `last_start_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_active_index` (`active`),
  KEY `manual_active` (`strategy`,`active`),
  KEY `maintenance_user_id` (`user_id`),
  CONSTRAINT `maintenance_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance`
--

LOCK TABLES `maintenance` WRITE;
/*!40000 ALTER TABLE `maintenance` DISABLE KEYS */;
INSERT INTO `maintenance` VALUES
(1,'Weekly patching','Sun 02-03',1,1,'recurring-weekday','2026-11-01 00:00:00',NULL,'02:00:00','03:00:00','[7]','[]',1,'0 2 * * 7','Europe/Budapest',3600,NULL),
(2,'Manual window','',1,0,'manual',NULL,NULL,NULL,NULL,'[]','[]',1,NULL,'SAME_AS_SERVER',NULL,NULL),
(3,'One-off','',1,1,'single','2026-12-01 01:00:00','2026-12-01 02:00:00',NULL,NULL,'[]','[]',1,NULL,'UTC',NULL,NULL),
(4,'Cron nightly','',1,1,'cron',NULL,NULL,NULL,NULL,'[]','[]',1,'30 3 * * *','UTC',2700,NULL),
(5,'Every 3 days','',1,1,'recurring-interval','2026-11-01 00:00:00',NULL,'02:00:00','03:00:00','[]','[]',3,'0 2  * * *','SAME_AS_SERVER',3600,NULL),
(6,'1st and last of month','',1,1,'recurring-day-of-month','2026-11-01 00:00:00',NULL,'02:00:00','03:00:00','[]','[1,\"lastDay1\"]',1,'0 2 1,L * *','SAME_AS_SERVER',3600,NULL);
/*!40000 ALTER TABLE `maintenance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenance_status_page`
--

DROP TABLE IF EXISTS `maintenance_status_page`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_status_page` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `status_page_id` int(10) unsigned NOT NULL,
  `maintenance_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_status_page_maintenance_id_foreign` (`maintenance_id`),
  KEY `maintenance_status_page_status_page_id_foreign` (`status_page_id`),
  CONSTRAINT `maintenance_status_page_maintenance_id_foreign` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `maintenance_status_page_status_page_id_foreign` FOREIGN KEY (`status_page_id`) REFERENCES `status_page` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance_status_page`
--

LOCK TABLES `maintenance_status_page` WRITE;
/*!40000 ALTER TABLE `maintenance_status_page` DISABLE KEYS */;
INSERT INTO `maintenance_status_page` VALUES
(1,1,1);
/*!40000 ALTER TABLE `maintenance_status_page` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `monitor`
--

DROP TABLE IF EXISTS `monitor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitor` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `user_id` int(10) unsigned DEFAULT NULL,
  `interval` int(11) NOT NULL DEFAULT 20,
  `url` text DEFAULT NULL,
  `type` varchar(20) DEFAULT NULL,
  `weight` int(11) DEFAULT 2000,
  `hostname` varchar(255) DEFAULT NULL,
  `port` int(11) DEFAULT NULL,
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  `keyword` varchar(255) DEFAULT NULL,
  `maxretries` int(11) NOT NULL DEFAULT 0,
  `ignore_tls` tinyint(1) NOT NULL DEFAULT 0,
  `upside_down` tinyint(1) NOT NULL DEFAULT 0,
  `maxredirects` int(11) NOT NULL DEFAULT 10,
  `accepted_statuscodes_json` text NOT NULL DEFAULT '["200-299"]',
  `dns_resolve_type` varchar(5) DEFAULT NULL,
  `dns_resolve_server` varchar(255) DEFAULT NULL,
  `dns_last_result` text DEFAULT NULL,
  `retry_interval` int(11) NOT NULL DEFAULT 0,
  `push_token` varchar(32) DEFAULT NULL,
  `method` text NOT NULL DEFAULT 'GET',
  `body` text DEFAULT NULL,
  `headers` text DEFAULT NULL,
  `basic_auth_user` text DEFAULT NULL,
  `basic_auth_pass` text DEFAULT NULL,
  `docker_host` int(10) unsigned DEFAULT NULL,
  `docker_container` varchar(255) DEFAULT NULL,
  `proxy_id` int(10) unsigned DEFAULT NULL,
  `expiry_notification` tinyint(1) DEFAULT 1,
  `mqtt_topic` text DEFAULT NULL,
  `mqtt_success_message` varchar(255) DEFAULT NULL,
  `mqtt_username` varchar(255) DEFAULT NULL,
  `mqtt_password` varchar(255) DEFAULT NULL,
  `database_connection_string` varchar(2000) DEFAULT NULL,
  `database_query` text DEFAULT NULL,
  `auth_method` varchar(250) DEFAULT NULL,
  `auth_domain` text DEFAULT NULL,
  `auth_workstation` text DEFAULT NULL,
  `grpc_url` varchar(255) DEFAULT NULL,
  `grpc_protobuf` text DEFAULT NULL,
  `grpc_body` text DEFAULT NULL,
  `grpc_metadata` text DEFAULT NULL,
  `grpc_method` text DEFAULT NULL,
  `grpc_service_name` text DEFAULT NULL,
  `grpc_enable_tls` tinyint(1) NOT NULL DEFAULT 0,
  `radius_username` varchar(255) DEFAULT NULL,
  `radius_password` varchar(255) DEFAULT NULL,
  `radius_calling_station_id` varchar(50) DEFAULT NULL,
  `radius_called_station_id` varchar(50) DEFAULT NULL,
  `radius_secret` varchar(255) DEFAULT NULL,
  `resend_interval` int(11) NOT NULL DEFAULT 0,
  `packet_size` int(11) NOT NULL DEFAULT 56,
  `game` varchar(255) DEFAULT NULL,
  `http_body_encoding` varchar(25) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `tls_ca` text DEFAULT NULL,
  `tls_cert` text DEFAULT NULL,
  `tls_key` text DEFAULT NULL,
  `parent` int(10) unsigned DEFAULT NULL,
  `invert_keyword` tinyint(1) NOT NULL DEFAULT 0,
  `json_path` text DEFAULT NULL,
  `expected_value` varchar(255) DEFAULT NULL,
  `kafka_producer_topic` varchar(255) DEFAULT NULL,
  `kafka_producer_brokers` text DEFAULT NULL,
  `kafka_producer_ssl` tinyint(1) NOT NULL DEFAULT 0,
  `kafka_producer_allow_auto_topic_creation` tinyint(1) NOT NULL DEFAULT 0,
  `kafka_producer_sasl_options` text DEFAULT NULL,
  `kafka_producer_message` text DEFAULT NULL,
  `oauth_client_id` text DEFAULT NULL,
  `oauth_client_secret` text DEFAULT NULL,
  `oauth_token_url` text DEFAULT NULL,
  `oauth_scopes` text DEFAULT NULL,
  `oauth_auth_method` text DEFAULT NULL,
  `timeout` double NOT NULL DEFAULT 0,
  `gamedig_given_port_only` tinyint(1) NOT NULL DEFAULT 1,
  `mqtt_check_type` varchar(255) NOT NULL DEFAULT 'keyword',
  `remote_browser` int(10) unsigned DEFAULT NULL,
  `snmp_oid` varchar(255) DEFAULT NULL,
  `snmp_version` enum('1','2c','3') DEFAULT '2c',
  `json_path_operator` varchar(255) DEFAULT NULL,
  `cache_bust` tinyint(1) NOT NULL DEFAULT 0,
  `conditions` text NOT NULL DEFAULT '[]',
  `rabbitmq_nodes` text DEFAULT NULL,
  `rabbitmq_username` varchar(255) DEFAULT NULL,
  `rabbitmq_password` varchar(255) DEFAULT NULL,
  `smtp_security` varchar(255) DEFAULT NULL,
  `ws_ignore_sec_websocket_accept_header` tinyint(1) NOT NULL DEFAULT 0,
  `ws_subprotocol` varchar(255) NOT NULL DEFAULT '',
  `ping_count` int(11) NOT NULL DEFAULT 1,
  `ping_numeric` tinyint(1) NOT NULL DEFAULT 1,
  `ping_per_request_timeout` int(11) NOT NULL DEFAULT 2,
  `ip_family` varchar(4) DEFAULT NULL,
  `manual_status` smallint(6) DEFAULT NULL,
  `oauth_audience` varchar(255) DEFAULT NULL,
  `mqtt_websocket_path` varchar(255) DEFAULT NULL,
  `domain_expiry_notification` tinyint(1) DEFAULT 0,
  `save_response` tinyint(1) NOT NULL DEFAULT 0,
  `save_error_response` tinyint(1) NOT NULL DEFAULT 1,
  `response_max_length` int(11) NOT NULL DEFAULT 1024,
  `system_service_name` varchar(255) DEFAULT NULL,
  `subtype` varchar(10) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `protocol` varchar(20) DEFAULT NULL,
  `snmp_v3_username` varchar(255) DEFAULT NULL,
  `expected_tls_alert` varchar(50) DEFAULT NULL,
  `retry_only_on_status_code_failure` tinyint(1) NOT NULL DEFAULT 0,
  `screenshot_delay` int(10) unsigned NOT NULL DEFAULT 0,
  `ntp_stratum_threshold` int(11) DEFAULT 5,
  `ntp_time_offset_threshold` int(11) DEFAULT 1000,
  `ntp_root_dispersion_threshold` int(11) DEFAULT 500,
  `bearer_token` text DEFAULT NULL,
  `gamedig_token` text DEFAULT NULL,
  `ssh_username` varchar(255) DEFAULT NULL,
  `ssh_password` varchar(255) DEFAULT NULL,
  `sftp_path` varchar(255) DEFAULT NULL,
  `ssh_private_key` text DEFAULT NULL,
  `ssh_passphrase` varchar(255) DEFAULT NULL,
  `ssh_auth_method` varchar(255) DEFAULT 'password',
  PRIMARY KEY (`id`),
  KEY `monitor_user_id_foreign` (`user_id`),
  KEY `monitor_docker_host_foreign` (`docker_host`),
  KEY `monitor_proxy_id_foreign` (`proxy_id`),
  KEY `monitor_parent_foreign` (`parent`),
  KEY `monitor_remote_browser_index` (`remote_browser`),
  CONSTRAINT `monitor_docker_host_foreign` FOREIGN KEY (`docker_host`) REFERENCES `docker_host` (`id`),
  CONSTRAINT `monitor_parent_foreign` FOREIGN KEY (`parent`) REFERENCES `monitor` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `monitor_proxy_id_foreign` FOREIGN KEY (`proxy_id`) REFERENCES `proxy` (`id`),
  CONSTRAINT `monitor_remote_browser_foreign` FOREIGN KEY (`remote_browser`) REFERENCES `remote_browser` (`id`),
  CONSTRAINT `monitor_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `monitor`
--

LOCK TABLES `monitor` WRITE;
/*!40000 ALTER TABLE `monitor` DISABLE KEYS */;
INSERT INTO `monitor` VALUES
(1,'Websites',1,1,60,NULL,'group',2000,NULL,NULL,'2026-11-07 22:52:24',NULL,0,0,0,10,'[\"200-299\"]','A','1.1.1.1',NULL,60,NULL,'GET',NULL,NULL,'','',NULL,'',NULL,0,'','','','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,0,56,NULL,'json','group monitor',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'[]',0,0,'{\"mechanism\":\"None\"}',NULL,NULL,NULL,NULL,NULL,NULL,48,1,'keyword',NULL,NULL,'2c',NULL,0,'[]','[]','','',NULL,0,'',1,1,2,NULL,NULL,NULL,NULL,0,0,1,1024,NULL,NULL,NULL,NULL,NULL,NULL,0,0,5,1000,500,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'password'),
(2,'Example HTTP',1,1,60,'https://example.com','http',2000,NULL,NULL,'2026-11-07 22:52:24',NULL,3,0,0,10,'[\"200-299\",\"301\"]','A','1.1.1.1',NULL,30,NULL,'GET',NULL,'{\"X-A\":\"1\"}','bu','bp',NULL,'',NULL,1,'','','','',NULL,NULL,'basic',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,10,56,NULL,'json','http with basic auth',NULL,NULL,NULL,1,0,NULL,NULL,NULL,'[]',0,0,'{\"mechanism\":\"None\"}',NULL,NULL,NULL,NULL,NULL,NULL,48,1,'keyword',NULL,NULL,'2c',NULL,0,'[]','[]','','',NULL,0,'',1,1,2,NULL,NULL,NULL,NULL,0,0,1,1024,NULL,NULL,NULL,NULL,NULL,NULL,0,0,5,1000,500,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'password'),
(3,'Keyword',1,1,60,'https://example.com','keyword',2000,NULL,NULL,'2026-11-07 22:52:24','Example Domain',0,0,0,10,'[\"200-299\"]','A','1.1.1.1',NULL,60,NULL,'GET',NULL,NULL,'','',NULL,'',NULL,0,'','','','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,0,56,NULL,'json',NULL,NULL,NULL,NULL,1,0,NULL,NULL,NULL,'[]',0,0,'{\"mechanism\":\"None\"}',NULL,NULL,NULL,NULL,NULL,NULL,48,1,'keyword',NULL,NULL,'2c',NULL,0,'[]','[]','','',NULL,0,'',1,1,2,NULL,NULL,NULL,NULL,0,0,1,1024,NULL,NULL,NULL,NULL,NULL,NULL,0,0,5,1000,500,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'password'),
(4,'JSON query',1,1,60,'https://httpbin.org/json','json-query',2000,NULL,NULL,'2026-11-07 22:52:24',NULL,0,0,0,10,'[\"200-299\"]','A','1.1.1.1',NULL,60,NULL,'GET',NULL,NULL,'','',NULL,'',NULL,0,'','','','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,0,56,NULL,'json',NULL,NULL,NULL,NULL,NULL,0,'$.slideshow.author','Yours Truly',NULL,'[]',0,0,'{\"mechanism\":\"None\"}',NULL,NULL,NULL,NULL,NULL,NULL,48,1,'keyword',NULL,NULL,'2c','==',0,'[]','[]','','',NULL,0,'',1,1,2,NULL,NULL,NULL,NULL,0,0,1,1024,NULL,NULL,NULL,NULL,NULL,NULL,0,0,5,1000,500,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'password'),
(5,'Via proxy (paused)',0,1,60,'https://example.org','http',2000,NULL,NULL,'2026-11-07 22:52:24',NULL,0,0,0,10,'[\"200-299\"]','A','1.1.1.1',NULL,60,NULL,'POST','{\"a\":1}',NULL,'','',NULL,'',1,0,'','','','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,0,56,NULL,'json',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'[]',0,0,'{\"mechanism\":\"None\"}',NULL,NULL,NULL,NULL,NULL,NULL,48,1,'keyword',NULL,NULL,'2c',NULL,0,'[]','[]','','',NULL,0,'',1,1,2,NULL,NULL,NULL,NULL,0,0,1,1024,NULL,NULL,NULL,NULL,NULL,NULL,0,0,5,1000,500,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'password'),
(6,'TCP 443',1,1,60,NULL,'port',2000,'example.com',443,'2026-11-07 22:52:24',NULL,0,0,0,10,'[\"200-299\"]','A','1.1.1.1',NULL,60,NULL,'GET',NULL,NULL,'','',NULL,'',NULL,0,'','','','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,0,56,NULL,'json',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'[]',0,0,'{\"mechanism\":\"None\"}',NULL,NULL,NULL,NULL,NULL,NULL,48,1,'keyword',NULL,NULL,'2c',NULL,0,'[]','[]','','',NULL,0,'',1,1,2,NULL,NULL,NULL,NULL,0,0,1,1024,NULL,NULL,NULL,NULL,NULL,NULL,0,0,5,1000,500,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'password'),
(7,'Ping localhost',1,1,60,NULL,'ping',2000,'127.0.0.1',NULL,'2026-11-07 22:52:24',NULL,0,0,0,10,'[\"200-299\"]','A','1.1.1.1',NULL,60,NULL,'GET',NULL,NULL,'','',NULL,'',NULL,0,'','','','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,0,56,NULL,'json',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'[]',0,0,'{\"mechanism\":\"None\"}',NULL,NULL,NULL,NULL,NULL,NULL,48,1,'keyword',NULL,NULL,'2c',NULL,0,'[]','[]','','',NULL,0,'',1,1,2,NULL,NULL,NULL,NULL,0,0,1,1024,NULL,NULL,NULL,NULL,NULL,NULL,0,0,5,1000,500,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'password'),
(8,'DNS',1,1,60,NULL,'dns',2000,'example.com',53,'2026-11-07 22:52:24',NULL,0,0,0,10,'[\"200-299\"]','A','1.1.1.1','Records: 104.20.23.154 | 172.66.147.243',60,NULL,'GET',NULL,NULL,'','',NULL,'',NULL,0,'','','','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,0,56,NULL,'json',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'[]',0,0,'{\"mechanism\":\"None\"}',NULL,NULL,NULL,NULL,NULL,NULL,48,1,'keyword',NULL,NULL,'2c',NULL,0,'[]','[]','','',NULL,0,'',1,1,2,NULL,NULL,NULL,NULL,0,0,1,1024,NULL,NULL,NULL,NULL,NULL,NULL,0,0,5,1000,500,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'password'),
(9,'Push',1,1,60,NULL,'push',2000,NULL,NULL,'2026-11-07 22:52:24',NULL,0,0,0,10,'[\"200-299\"]','A','1.1.1.1',NULL,60,'researchPushToken0123456789ABCDE','GET',NULL,NULL,'','',NULL,'',NULL,0,'','','','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,0,56,NULL,'json',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'[]',0,0,'{\"mechanism\":\"None\"}',NULL,NULL,NULL,NULL,NULL,NULL,48,1,'keyword',NULL,NULL,'2c',NULL,0,'[]','[]','','',NULL,0,'',1,1,2,NULL,NULL,NULL,NULL,0,0,1,1024,NULL,NULL,NULL,NULL,NULL,NULL,0,0,5,1000,500,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'password'),
(10,'Redis',0,1,60,NULL,'redis',2000,NULL,NULL,'2026-11-07 22:52:24',NULL,0,0,0,10,'[\"200-299\"]','A','1.1.1.1',NULL,60,NULL,'GET',NULL,NULL,'','',NULL,'',NULL,0,'','','','','redis://user:secret@redis.local:6379',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,0,56,NULL,'json',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'[]',0,0,'{\"mechanism\":\"None\"}',NULL,NULL,NULL,NULL,NULL,NULL,48,1,'keyword',NULL,NULL,'2c',NULL,0,'[]','[]','','',NULL,0,'',1,1,2,NULL,NULL,NULL,NULL,0,0,1,1024,NULL,NULL,NULL,NULL,NULL,NULL,0,0,5,1000,500,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'password');
/*!40000 ALTER TABLE `monitor` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `monitor_group`
--

DROP TABLE IF EXISTS `monitor_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitor_group` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_id` int(10) unsigned NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `weight` int(11) NOT NULL DEFAULT 1000,
  `send_url` tinyint(1) NOT NULL DEFAULT 0,
  `custom_url` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `monitor_group_group_id_foreign` (`group_id`),
  KEY `fk` (`monitor_id`,`group_id`),
  CONSTRAINT `monitor_group_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `group` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `monitor_group_monitor_id_foreign` FOREIGN KEY (`monitor_id`) REFERENCES `monitor` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `monitor_group`
--

LOCK TABLES `monitor_group` WRITE;
/*!40000 ALTER TABLE `monitor_group` DISABLE KEYS */;
INSERT INTO `monitor_group` VALUES
(1,2,1,1,1,NULL),
(2,3,1,2,0,NULL),
(3,7,2,1,0,NULL),
(4,6,2,2,0,NULL);
/*!40000 ALTER TABLE `monitor_group` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `monitor_maintenance`
--

DROP TABLE IF EXISTS `monitor_maintenance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitor_maintenance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_id` int(10) unsigned NOT NULL,
  `maintenance_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_id_index2` (`maintenance_id`),
  KEY `monitor_id_index` (`monitor_id`),
  CONSTRAINT `monitor_maintenance_maintenance_id_foreign` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `monitor_maintenance_monitor_id_foreign` FOREIGN KEY (`monitor_id`) REFERENCES `monitor` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `monitor_maintenance`
--

LOCK TABLES `monitor_maintenance` WRITE;
/*!40000 ALTER TABLE `monitor_maintenance` DISABLE KEYS */;
INSERT INTO `monitor_maintenance` VALUES
(1,2,1),
(2,7,1);
/*!40000 ALTER TABLE `monitor_maintenance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `monitor_notification`
--

DROP TABLE IF EXISTS `monitor_notification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitor_notification` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_id` int(10) unsigned NOT NULL,
  `notification_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `monitor_notification_notification_id_foreign` (`notification_id`),
  KEY `monitor_notification_index` (`monitor_id`,`notification_id`),
  CONSTRAINT `monitor_notification_monitor_id_foreign` FOREIGN KEY (`monitor_id`) REFERENCES `monitor` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `monitor_notification_notification_id_foreign` FOREIGN KEY (`notification_id`) REFERENCES `notification` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `monitor_notification`
--

LOCK TABLES `monitor_notification` WRITE;
/*!40000 ALTER TABLE `monitor_notification` DISABLE KEYS */;
INSERT INTO `monitor_notification` VALUES
(1,2,1),
(2,2,2);
/*!40000 ALTER TABLE `monitor_notification` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `monitor_tag`
--

DROP TABLE IF EXISTS `monitor_tag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitor_tag` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_id` int(10) unsigned NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `monitor_tag_monitor_id_foreign` (`monitor_id`),
  KEY `monitor_tag_tag_id_foreign` (`tag_id`),
  CONSTRAINT `monitor_tag_monitor_id_foreign` FOREIGN KEY (`monitor_id`) REFERENCES `monitor` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `monitor_tag_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tag` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `monitor_tag`
--

LOCK TABLES `monitor_tag` WRITE;
/*!40000 ALTER TABLE `monitor_tag` DISABLE KEYS */;
INSERT INTO `monitor_tag` VALUES
(1,2,1,'eu-west'),
(2,7,1,'');
/*!40000 ALTER TABLE `monitor_tag` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `monitor_tls_info`
--

DROP TABLE IF EXISTS `monitor_tls_info`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitor_tls_info` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_id` int(10) unsigned NOT NULL,
  `info_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `monitor_tls_info_monitor_id_foreign` (`monitor_id`),
  CONSTRAINT `monitor_tls_info_monitor_id_foreign` FOREIGN KEY (`monitor_id`) REFERENCES `monitor` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `monitor_tls_info`
--

LOCK TABLES `monitor_tls_info` WRITE;
/*!40000 ALTER TABLE `monitor_tls_info` DISABLE KEYS */;
INSERT INTO `monitor_tls_info` VALUES
(1,2,'{\"valid\":true,\"certInfo\":{\"subject\":{\"CN\":\"example.com\"},\"issuer\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"Cloudflare TLS Issuing ECC CA 3\"},\"subjectaltname\":\"DNS:example.com, DNS:*.example.com\",\"infoAccess\":{\"CA Issuers - URI\":[\"http://i.cf-i.ssl.com/Cloudflare-TLS-I-E3.cer\"],\"OCSP - URI\":[\"http://o.cf-i.ssl.com\"]},\"ca\":false,\"bits\":256,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,106,105,209,49,91,60,83,39,132,183,45,44,202,35,48,0,123,33,116,246,173,16,183,65,116,94,98,226,102,38,137,72,61,18,64,248,85,43,126,169,80,169,76,231,148,212,178,210,103,173,78,223,251,183,165,19,73,25,17,34,179,205,198,237]},\"asn1Curve\":\"prime256v1\",\"nistCurve\":\"P-256\",\"valid_from\":\"Sep 26 22:49:11 2026 GMT\",\"valid_to\":\"Dec 25 22:56:35 2026 GMT\",\"fingerprint\":\"89:9C:B2:59:E7:DE:8D:F1:00:33:A2:AF:48:C2:2C:2F:65:80:A2:1D\",\"fingerprint256\":\"85:CA:6A:B0:68:E9:BC:CE:88:B6:C4:AA:3C:47:F7:D1:72:28:13:4A:45:7F:87:0D:38:00:E6:22:3A:0D:F0:7A\",\"fingerprint512\":\"CE:F6:04:11:25:73:B1:B7:BE:CC:BA:5D:BE:81:D7:F1:C2:D9:AE:77:38:9F:DF:20:BA:25:D7:4A:39:2A:14:6E:BD:55:69:C1:31:9B:2B:67:69:D2:D0:11:B9:8D:4C:3C:1A:2A:E7:DD:BA:A7:50:4F:21:6A:1D:88:76:4B:6E:3E\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.1\"],\"serialNumber\":\"01EEE6AABB521D5E14FC315FD9985690\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,3,230,48,130,3,141,160,3,2,1,2,2,16,1,238,230,170,187,82,29,94,20,252,49,95,217,152,86,144,48,10,6,8,42,134,72,206,61,4,3,2,48,81,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,40,48,38,6,3,85,4,3,12,31,67,108,111,117,100,102,108,97,114,101,32,84,76,83,32,73,115,115,117,105,110,103,32,69,67,67,32,67,65,32,51,48,30,23,13,50,54,48,57,50,54,50,50,52,57,49,49,90,23,13,50,54,49,50,50,53,50,50,53,54,51,53,90,48,22,49,20,48,18,6,3,85,4,3,12,11,101,120,97,109,112,108,101,46,99,111,109,48,89,48,19,6,7,42,134,72,206,61,2,1,6,8,42,134,72,206,61,3,1,7,3,66,0,4,106,105,209,49,91,60,83,39,132,183,45,44,202,35,48,0,123,33,116,246,173,16,183,65,116,94,98,226,102,38,137,72,61,18,64,248,85,43,126,169,80,169,76,231,148,212,178,210,103,173,78,223,251,183,165,19,73,25,17,34,179,205,198,237,163,130,2,128,48,130,2,124,48,12,6,3,85,29,19,1,1,255,4,2,48,0,48,31,6,3,85,29,35,4,24,48,22,128,20,131,3,253,231,246,245,74,77,21,65,244,237,34,22,211,50,10,62,202,102,48,108,6,8,43,6,1,5,5,7,1,1,4,96,48,94,48,57,6,8,43,6,1,5,5,7,48,2,134,45,104,116,116,112,58,47,47,105,46,99,102,45,105,46,115,115,108,46,99,111,109,47,67,108,111,117,100,102,108,97,114,101,45,84,76,83,45,73,45,69,51,46,99,101,114,48,33,6,8,43,6,1,5,5,7,48,1,134,21,104,116,116,112,58,47,47,111,46,99,102,45,105,46,115,115,108,46,99,111,109,48,37,6,3,85,29,17,4,30,48,28,130,11,101,120,97,109,112,108,101,46,99,111,109,130,13,42,46,101,120,97,109,112,108,101,46,99,111,109,48,35,6,3,85,29,32,4,28,48,26,48,8,6,6,103,129,12,1,2,1,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,1,48,19,6,3,85,29,37,4,12,48,10,6,8,43,6,1,5,5,7,3,1,48,83,6,3,85,29,31,4,76,48,74,48,72,160,70,160,68,134,66,104,116,116,112,58,47,47,99,46,99,102,45,105,46,115,115,108,46,99,111,109,47,97,101,56,48,49,101,100,49,99,53,53,98,98,53,55,57,100,55,57,50,48,56,98,48,100,55,55,50,97,99,102,98,56,99,99,51,97,50,48,56,46,99,114,108,48,14,6,3,85,29,15,1,1,255,4,4,3,2,7,128,48,15,6,9,43,6,1,4,1,130,218,75,44,4,2,5,0,48,130,1,4,6,10,43,6,1,4,1,214,121,2,4,2,4,129,245,4,129,242,0,240,0,118,0,148,78,67,135,250,236,193,239,129,243,25,36,38,168,24,101,1,199,211,95,56,2,1,63,114,103,125,85,55,46,25,216,0,0,1,160,223,241,173,98,0,0,4,3,0,71,48,69,2,33,0,174,9,70,161,69,172,240,57,202,163,220,14,251,203,199,151,117,0,105,46,81,130,142,255,67,255,27,215,119,117,251,132,2,32,109,205,10,49,47,140,176,3,88,229,98,198,164,120,19,147,117,49,147,72,53,85,100,208,7,191,47,97,19,32,107,81,0,118,0,203,56,247,21,137,124,132,161,68,95,91,193,221,251,201,110,242,154,89,205,71,10,105,5,133,176,203,20,195,20,88,231,0,0,1,160,223,241,173,126,0,0,4,3,0,71,48,69,2,32,29,152,55,249,89,63,167,146,4,203,85,208,20,235,24,62,202,75,34,149,187,248,186,241,156,218,247,185,98,247,161,92,2,33,0,214,107,111,78,115,60,205,179,74,143,160,43,82,149,166,129,52,84,68,48,224,56,46,17,212,45,150,229,196,138,233,242,48,10,6,8,42,134,72,206,61,4,3,2,3,71,0,48,68,2,32,52,105,87,197,98,211,78,169,249,249,116,14,144,253,58,125,56,26,17,184,195,48,1,212,32,185,136,106,156,124,139,155,2,32,7,147,35,252,105,105,241,153,52,31,71,192,215,196,121,77,57,202,31,154,74,255,121,164,63,18,54,210,0,88,218,244]},\"issuerCertificate\":{\"subject\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"Cloudflare TLS Issuing ECC CA 3\"},\"issuer\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"SSL.com TLS Transit ECC CA R2\"},\"infoAccess\":{\"CA Issuers - URI\":[\"http://cert.ssl.com/SSL.com-TLS-T-ECC-R2.cer\"]},\"ca\":true,\"bits\":256,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,253,29,20,20,142,35,66,147,38,207,112,208,137,215,177,30,114,172,62,93,89,82,129,223,28,245,214,37,185,214,119,7,133,11,141,18,177,43,63,64,185,31,114,6,224,255,64,82,10,147,73,248,201,113,176,126,238,224,163,69,81,24,68,116]},\"asn1Curve\":\"prime256v1\",\"nistCurve\":\"P-256\",\"valid_from\":\"May 29 19:49:45 2025 GMT\",\"valid_to\":\"May 27 19:49:44 2035 GMT\",\"fingerprint\":\"AE:80:1E:D1:C5:5B:B5:79:D7:92:08:B0:D7:72:AC:FB:8C:C3:A2:08\",\"fingerprint256\":\"F1:5F:29:AB:EF:73:AA:4D:D9:AB:75:4B:AE:AE:36:85:BD:D3:87:4B:46:B5:25:07:11:77:62:86:85:71:80:26\",\"fingerprint512\":\"2B:20:5D:F6:95:5B:37:99:A7:CE:63:03:E0:8C:D9:CC:A7:01:5D:32:84:9E:1C:97:EE:8A:57:D3:7C:31:36:10:38:B1:31:8F:B9:9F:1D:C3:C3:43:F1:E7:CE:17:A6:C9:57:AD:95:AC:0C:3A:2D:3C:A3:6A:5F:7F:17:D1:98:ED\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.2\",\"1.3.6.1.5.5.7.3.1\"],\"serialNumber\":\"31EEE88AFB87CD9EF8336604743F9B27\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,2,226,48,130,2,105,160,3,2,1,2,2,16,49,238,232,138,251,135,205,158,248,51,102,4,116,63,155,39,48,10,6,8,42,134,72,206,61,4,3,3,48,79,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,38,48,36,6,3,85,4,3,12,29,83,83,76,46,99,111,109,32,84,76,83,32,84,114,97,110,115,105,116,32,69,67,67,32,67,65,32,82,50,48,30,23,13,50,53,48,53,50,57,49,57,52,57,52,53,90,23,13,51,53,48,53,50,55,49,57,52,57,52,52,90,48,81,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,40,48,38,6,3,85,4,3,12,31,67,108,111,117,100,102,108,97,114,101,32,84,76,83,32,73,115,115,117,105,110,103,32,69,67,67,32,67,65,32,51,48,89,48,19,6,7,42,134,72,206,61,2,1,6,8,42,134,72,206,61,3,1,7,3,66,0,4,253,29,20,20,142,35,66,147,38,207,112,208,137,215,177,30,114,172,62,93,89,82,129,223,28,245,214,37,185,214,119,7,133,11,141,18,177,43,63,64,185,31,114,6,224,255,64,82,10,147,73,248,201,113,176,126,238,224,163,69,81,24,68,116,163,130,1,35,48,130,1,31,48,18,6,3,85,29,19,1,1,255,4,8,48,6,1,1,255,2,1,0,48,31,6,3,85,29,35,4,24,48,22,128,20,50,162,199,216,88,139,255,127,192,60,242,85,105,51,236,206,204,31,188,151,48,72,6,8,43,6,1,5,5,7,1,1,4,60,48,58,48,56,6,8,43,6,1,5,5,7,48,2,134,44,104,116,116,112,58,47,47,99,101,114,116,46,115,115,108,46,99,111,109,47,83,83,76,46,99,111,109,45,84,76,83,45,84,45,69,67,67,45,82,50,46,99,101,114,48,17,6,3,85,29,32,4,10,48,8,48,6,6,4,85,29,32,0,48,29,6,3,85,29,37,4,22,48,20,6,8,43,6,1,5,5,7,3,2,6,8,43,6,1,5,5,7,3,1,48,61,6,3,85,29,31,4,54,48,52,48,50,160,48,160,46,134,44,104,116,116,112,58,47,47,99,114,108,115,46,115,115,108,46,99,111,109,47,83,83,76,46,99,111,109,45,84,76,83,45,84,45,69,67,67,45,82,50,46,99,114,108,48,29,6,3,85,29,14,4,22,4,20,131,3,253,231,246,245,74,77,21,65,244,237,34,22,211,50,10,62,202,102,48,14,6,3,85,29,15,1,1,255,4,4,3,2,1,134,48,10,6,8,42,134,72,206,61,4,3,3,3,103,0,48,100,2,48,100,91,247,96,76,10,229,223,170,113,232,181,76,91,55,199,46,253,41,20,63,143,244,26,14,99,56,171,103,102,146,216,140,44,64,241,219,210,180,29,100,198,57,54,8,83,78,78,2,48,40,176,13,78,245,126,27,88,85,27,165,81,206,123,228,114,107,160,181,224,143,224,100,166,64,110,109,209,231,111,111,154,62,144,135,236,179,250,64,212,63,94,111,77,138,167,113,3]},\"issuerCertificate\":{\"subject\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"SSL.com TLS Transit ECC CA R2\"},\"issuer\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"SSL.com TLS ECC Root CA 2022\"},\"infoAccess\":{\"CA Issuers - URI\":[\"http://cert.ssl.com/SSLcom-TLS-Root-2022-ECC.cer\"]},\"ca\":true,\"bits\":384,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,100,231,125,153,3,83,92,145,30,234,248,216,35,173,161,191,44,226,99,137,239,40,104,246,237,215,240,236,171,104,174,119,43,228,118,151,27,80,235,219,89,40,45,111,184,54,185,171,141,151,202,76,34,158,238,137,217,2,63,253,122,115,58,144,141,43,204,47,58,55,131,218,13,180,45,69,27,115,114,9,101,95,120,33,61,231,139,102,63,71,253,9,41,214,20,15]},\"asn1Curve\":\"secp384r1\",\"nistCurve\":\"P-384\",\"valid_from\":\"Oct 21 17:02:23 2022 GMT\",\"valid_to\":\"Oct 17 17:02:22 2037 GMT\",\"fingerprint\":\"E1:51:88:4D:AE:92:4B:08:EF:26:46:01:21:D7:79:06:D5:D1:8A:C8\",\"fingerprint256\":\"5D:1B:C3:99:27:4E:64:9E:1C:72:69:7D:E9:1A:54:AD:72:50:88:C5:22:1C:B6:1E:17:EE:9C:29:0B:C4:2A:92\",\"fingerprint512\":\"7B:34:2C:8E:C1:DA:B8:64:F2:93:10:1F:E2:1D:11:F1:05:17:15:73:BE:5B:68:6E:EF:82:6E:11:07:E4:3F:5A:92:74:E0:50:6B:B8:9A:21:88:61:BC:21:C6:61:73:CD:C4:C8:72:5F:FB:6A:C0:D3:FC:40:CD:2E:6A:93:6E:AE\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.2\",\"1.3.6.1.5.5.7.3.1\"],\"serialNumber\":\"604D8AF8D00BA8748B955853172C5F2E\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,3,52,48,130,2,185,160,3,2,1,2,2,16,96,77,138,248,208,11,168,116,139,149,88,83,23,44,95,46,48,10,6,8,42,134,72,206,61,4,3,3,48,78,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,37,48,35,6,3,85,4,3,12,28,83,83,76,46,99,111,109,32,84,76,83,32,69,67,67,32,82,111,111,116,32,67,65,32,50,48,50,50,48,30,23,13,50,50,49,48,50,49,49,55,48,50,50,51,90,23,13,51,55,49,48,49,55,49,55,48,50,50,50,90,48,79,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,38,48,36,6,3,85,4,3,12,29,83,83,76,46,99,111,109,32,84,76,83,32,84,114,97,110,115,105,116,32,69,67,67,32,67,65,32,82,50,48,118,48,16,6,7,42,134,72,206,61,2,1,6,5,43,129,4,0,34,3,98,0,4,100,231,125,153,3,83,92,145,30,234,248,216,35,173,161,191,44,226,99,137,239,40,104,246,237,215,240,236,171,104,174,119,43,228,118,151,27,80,235,219,89,40,45,111,184,54,185,171,141,151,202,76,34,158,238,137,217,2,63,253,122,115,58,144,141,43,204,47,58,55,131,218,13,180,45,69,27,115,114,9,101,95,120,33,61,231,139,102,63,71,253,9,41,214,20,15,163,130,1,89,48,130,1,85,48,18,6,3,85,29,19,1,1,255,4,8,48,6,1,1,255,2,1,1,48,31,6,3,85,29,35,4,24,48,22,128,20,137,143,47,163,232,43,160,20,84,123,243,86,184,38,95,103,56,11,156,208,48,76,6,8,43,6,1,5,5,7,1,1,4,64,48,62,48,60,6,8,43,6,1,5,5,7,48,2,134,48,104,116,116,112,58,47,47,99,101,114,116,46,115,115,108,46,99,111,109,47,83,83,76,99,111,109,45,84,76,83,45,82,111,111,116,45,50,48,50,50,45,69,67,67,46,99,101,114,48,63,6,3,85,29,32,4,56,48,54,48,52,6,4,85,29,32,0,48,44,48,42,6,8,43,6,1,5,5,7,2,1,22,30,104,116,116,112,115,58,47,47,119,119,119,46,115,115,108,46,99,111,109,47,114,101,112,111,115,105,116,111,114,121,48,29,6,3,85,29,37,4,22,48,20,6,8,43,6,1,5,5,7,3,2,6,8,43,6,1,5,5,7,3,1,48,65,6,3,85,29,31,4,58,48,56,48,54,160,52,160,50,134,48,104,116,116,112,58,47,47,99,114,108,115,46,115,115,108,46,99,111,109,47,83,83,76,99,111,109,45,84,76,83,45,82,111,111,116,45,50,48,50,50,45,69,67,67,46,99,114,108,48,29,6,3,85,29,14,4,22,4,20,50,162,199,216,88,139,255,127,192,60,242,85,105,51,236,206,204,31,188,151,48,14,6,3,85,29,15,1,1,255,4,4,3,2,1,134,48,10,6,8,42,134,72,206,61,4,3,3,3,105,0,48,102,2,49,0,184,74,66,62,123,103,45,179,89,211,55,211,2,70,41,125,239,62,182,225,108,75,2,159,120,133,62,237,53,145,143,100,183,189,98,34,200,203,56,10,169,111,219,19,70,246,135,208,2,49,0,175,87,149,204,69,203,167,3,41,80,104,36,28,224,159,198,193,29,105,239,42,207,11,64,209,89,103,178,44,9,16,90,46,88,150,214,189,15,25,87,239,132,5,22,75,5,8,253]},\"issuerCertificate\":{\"subject\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"SSL.com TLS ECC Root CA 2022\"},\"issuer\":{\"C\":\"GB\",\"ST\":\"Greater Manchester\",\"L\":\"Salford\",\"O\":\"Comodo CA Limited\",\"CN\":\"AAA Certificate Services\"},\"infoAccess\":{\"OCSP - URI\":[\"http://ocsp.comodoca.com\"]},\"ca\":true,\"bits\":384,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,69,41,53,115,250,194,184,35,206,20,125,168,177,77,160,91,54,238,42,44,83,195,96,9,53,178,36,102,38,105,192,179,149,214,93,146,64,25,14,198,165,19,112,244,239,18,81,40,93,231,204,189,249,60,133,193,207,148,144,201,43,206,146,66,88,89,103,253,148,39,16,100,140,79,4,177,77,73,228,123,79,155,245,231,8,248,3,136,247,167,195,146,75,25,84,129]},\"asn1Curve\":\"secp384r1\",\"nistCurve\":\"P-384\",\"valid_from\":\"Aug  1 00:00:00 2025 GMT\",\"valid_to\":\"Dec 31 23:59:59 2028 GMT\",\"fingerprint\":\"5C:85:D2:01:CA:68:B3:9F:CC:D2:4A:89:27:35:9E:F2:95:AF:83:DC\",\"fingerprint256\":\"BA:06:D3:D3:E3:48:FC:E7:47:8C:C8:4B:42:2D:0E:63:8E:9E:22:1E:F1:A0:B5:3A:DC:14:CC:70:E0:4B:8A:B8\",\"fingerprint512\":\"30:38:EA:15:FC:E0:CB:24:AA:F3:5E:35:E1:64:08:2B:9E:99:55:20:87:87:75:7F:BB:E3:CD:F9:C2:93:8D:96:FF:97:FA:A6:41:7B:A9:7B:38:3C:E4:0A:1D:81:AD:64:C7:AB:BC:49:BE:98:78:BE:F9:00:4A:90:B3:AD:3A:B2\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.1\",\"1.3.6.1.5.5.7.3.2\"],\"serialNumber\":\"173DCA60EA9F8E1026DAFCD72810E48E\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,4,62,48,130,3,38,160,3,2,1,2,2,16,23,61,202,96,234,159,142,16,38,218,252,215,40,16,228,142,48,13,6,9,42,134,72,134,247,13,1,1,11,5,0,48,123,49,11,48,9,6,3,85,4,6,19,2,71,66,49,27,48,25,6,3,85,4,8,12,18,71,114,101,97,116,101,114,32,77,97,110,99,104,101,115,116,101,114,49,16,48,14,6,3,85,4,7,12,7,83,97,108,102,111,114,100,49,26,48,24,6,3,85,4,10,12,17,67,111,109,111,100,111,32,67,65,32,76,105,109,105,116,101,100,49,33,48,31,6,3,85,4,3,12,24,65,65,65,32,67,101,114,116,105,102,105,99,97,116,101,32,83,101,114,118,105,99,101,115,48,30,23,13,50,53,48,56,48,49,48,48,48,48,48,48,90,23,13,50,56,49,50,51,49,50,51,53,57,53,57,90,48,78,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,37,48,35,6,3,85,4,3,12,28,83,83,76,46,99,111,109,32,84,76,83,32,69,67,67,32,82,111,111,116,32,67,65,32,50,48,50,50,48,118,48,16,6,7,42,134,72,206,61,2,1,6,5,43,129,4,0,34,3,98,0,4,69,41,53,115,250,194,184,35,206,20,125,168,177,77,160,91,54,238,42,44,83,195,96,9,53,178,36,102,38,105,192,179,149,214,93,146,64,25,14,198,165,19,112,244,239,18,81,40,93,231,204,189,249,60,133,193,207,148,144,201,43,206,146,66,88,89,103,253,148,39,16,100,140,79,4,177,77,73,228,123,79,155,245,231,8,248,3,136,247,167,195,146,75,25,84,129,163,130,1,151,48,130,1,147,48,31,6,3,85,29,35,4,24,48,22,128,20,160,17,10,35,62,150,241,7,236,226,175,41,239,130,165,127,208,48,164,180,48,29,6,3,85,29,14,4,22,4,20,137,143,47,163,232,43,160,20,84,123,243,86,184,38,95,103,56,11,156,208,48,14,6,3,85,29,15,1,1,255,4,4,3,2,1,134,48,18,6,3,85,29,19,1,1,255,4,8,48,6,1,1,255,2,1,2,48,29,6,3,85,29,37,4,22,48,20,6,8,43,6,1,5,5,7,3,1,6,8,43,6,1,5,5,7,3,2,48,129,146,6,3,85,29,32,4,129,138,48,129,135,48,7,6,5,103,129,12,1,1,48,8,6,6,103,129,12,1,2,1,48,8,6,6,103,129,12,1,2,2,48,8,6,6,103,129,12,1,2,3,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,1,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,2,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,3,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,4,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,5,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,6,48,67,6,3,85,29,31,4,60,48,58,48,56,160,54,160,52,134,50,104,116,116,112,58,47,47,99,114,108,46,99,111,109,111,100,111,99,97,46,99,111,109,47,65,65,65,67,101,114,116,105,102,105,99,97,116,101,83,101,114,118,105,99,101,115,46,99,114,108,48,52,6,8,43,6,1,5,5,7,1,1,4,40,48,38,48,36,6,8,43,6,1,5,5,7,48,1,134,24,104,116,116,112,58,47,47,111,99,115,112,46,99,111,109,111,100,111,99,97,46,99,111,109,48,13,6,9,42,134,72,134,247,13,1,1,11,5,0,3,130,1,1,0,67,229,112,251,75,238,36,60,240,56,40,134,203,222,70,195,43,43,156,96,88,0,87,91,107,232,208,78,69,216,190,66,86,26,210,3,215,23,242,82,18,159,242,214,110,167,5,17,166,67,168,84,31,189,102,251,17,215,144,89,240,228,153,173,28,60,82,29,246,67,62,155,97,169,94,220,246,124,210,44,192,134,80,48,73,100,116,60,62,204,67,19,20,197,143,91,216,107,2,189,38,252,207,85,224,39,23,204,161,148,145,68,36,213,129,96,225,185,174,214,7,99,1,118,211,126,3,207,51,161,119,88,252,248,85,158,140,116,53,150,204,197,119,66,73,103,54,202,136,212,16,237,192,193,193,132,113,50,253,248,141,112,232,15,61,162,115,80,209,34,159,25,123,63,95,62,148,106,52,252,155,72,7,212,27,93,81,70,238,175,118,129,223,233,244,204,159,212,238,237,249,12,168,134,191,153,166,52,111,53,149,214,107,90,48,83,39,84,253,127,21,72,90,105,155,77,92,217,191,40,13,50,213,46,153,43,57,13,233,101,142,25,73,2,39,64,177,55,62,230,32,129,38,106,145,22]},\"validTo\":\"2028-12-31T23:59:59.000Z\",\"daysRemaining\":785,\"certType\":\"root CA\"},\"validTo\":\"2037-10-17T17:02:22.000Z\",\"daysRemaining\":3996,\"certType\":\"intermediate CA\"},\"validTo\":\"2035-05-27T19:49:44.000Z\",\"daysRemaining\":3122,\"certType\":\"intermediate CA\"},\"validTo\":\"2026-12-25T22:56:35.000Z\",\"validFor\":[\"example.com\",\"*.example.com\"],\"daysRemaining\":48,\"certType\":\"server\"},\"hostnameMatchMonitorUrl\":true}'),
(2,3,'{\"valid\":true,\"certInfo\":{\"subject\":{\"CN\":\"example.com\"},\"issuer\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"Cloudflare TLS Issuing ECC CA 3\"},\"subjectaltname\":\"DNS:example.com, DNS:*.example.com\",\"infoAccess\":{\"CA Issuers - URI\":[\"http://i.cf-i.ssl.com/Cloudflare-TLS-I-E3.cer\"],\"OCSP - URI\":[\"http://o.cf-i.ssl.com\"]},\"ca\":false,\"bits\":256,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,106,105,209,49,91,60,83,39,132,183,45,44,202,35,48,0,123,33,116,246,173,16,183,65,116,94,98,226,102,38,137,72,61,18,64,248,85,43,126,169,80,169,76,231,148,212,178,210,103,173,78,223,251,183,165,19,73,25,17,34,179,205,198,237]},\"asn1Curve\":\"prime256v1\",\"nistCurve\":\"P-256\",\"valid_from\":\"Sep 26 22:49:11 2026 GMT\",\"valid_to\":\"Dec 25 22:56:35 2026 GMT\",\"fingerprint\":\"89:9C:B2:59:E7:DE:8D:F1:00:33:A2:AF:48:C2:2C:2F:65:80:A2:1D\",\"fingerprint256\":\"85:CA:6A:B0:68:E9:BC:CE:88:B6:C4:AA:3C:47:F7:D1:72:28:13:4A:45:7F:87:0D:38:00:E6:22:3A:0D:F0:7A\",\"fingerprint512\":\"CE:F6:04:11:25:73:B1:B7:BE:CC:BA:5D:BE:81:D7:F1:C2:D9:AE:77:38:9F:DF:20:BA:25:D7:4A:39:2A:14:6E:BD:55:69:C1:31:9B:2B:67:69:D2:D0:11:B9:8D:4C:3C:1A:2A:E7:DD:BA:A7:50:4F:21:6A:1D:88:76:4B:6E:3E\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.1\"],\"serialNumber\":\"01EEE6AABB521D5E14FC315FD9985690\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,3,230,48,130,3,141,160,3,2,1,2,2,16,1,238,230,170,187,82,29,94,20,252,49,95,217,152,86,144,48,10,6,8,42,134,72,206,61,4,3,2,48,81,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,40,48,38,6,3,85,4,3,12,31,67,108,111,117,100,102,108,97,114,101,32,84,76,83,32,73,115,115,117,105,110,103,32,69,67,67,32,67,65,32,51,48,30,23,13,50,54,48,57,50,54,50,50,52,57,49,49,90,23,13,50,54,49,50,50,53,50,50,53,54,51,53,90,48,22,49,20,48,18,6,3,85,4,3,12,11,101,120,97,109,112,108,101,46,99,111,109,48,89,48,19,6,7,42,134,72,206,61,2,1,6,8,42,134,72,206,61,3,1,7,3,66,0,4,106,105,209,49,91,60,83,39,132,183,45,44,202,35,48,0,123,33,116,246,173,16,183,65,116,94,98,226,102,38,137,72,61,18,64,248,85,43,126,169,80,169,76,231,148,212,178,210,103,173,78,223,251,183,165,19,73,25,17,34,179,205,198,237,163,130,2,128,48,130,2,124,48,12,6,3,85,29,19,1,1,255,4,2,48,0,48,31,6,3,85,29,35,4,24,48,22,128,20,131,3,253,231,246,245,74,77,21,65,244,237,34,22,211,50,10,62,202,102,48,108,6,8,43,6,1,5,5,7,1,1,4,96,48,94,48,57,6,8,43,6,1,5,5,7,48,2,134,45,104,116,116,112,58,47,47,105,46,99,102,45,105,46,115,115,108,46,99,111,109,47,67,108,111,117,100,102,108,97,114,101,45,84,76,83,45,73,45,69,51,46,99,101,114,48,33,6,8,43,6,1,5,5,7,48,1,134,21,104,116,116,112,58,47,47,111,46,99,102,45,105,46,115,115,108,46,99,111,109,48,37,6,3,85,29,17,4,30,48,28,130,11,101,120,97,109,112,108,101,46,99,111,109,130,13,42,46,101,120,97,109,112,108,101,46,99,111,109,48,35,6,3,85,29,32,4,28,48,26,48,8,6,6,103,129,12,1,2,1,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,1,48,19,6,3,85,29,37,4,12,48,10,6,8,43,6,1,5,5,7,3,1,48,83,6,3,85,29,31,4,76,48,74,48,72,160,70,160,68,134,66,104,116,116,112,58,47,47,99,46,99,102,45,105,46,115,115,108,46,99,111,109,47,97,101,56,48,49,101,100,49,99,53,53,98,98,53,55,57,100,55,57,50,48,56,98,48,100,55,55,50,97,99,102,98,56,99,99,51,97,50,48,56,46,99,114,108,48,14,6,3,85,29,15,1,1,255,4,4,3,2,7,128,48,15,6,9,43,6,1,4,1,130,218,75,44,4,2,5,0,48,130,1,4,6,10,43,6,1,4,1,214,121,2,4,2,4,129,245,4,129,242,0,240,0,118,0,148,78,67,135,250,236,193,239,129,243,25,36,38,168,24,101,1,199,211,95,56,2,1,63,114,103,125,85,55,46,25,216,0,0,1,160,223,241,173,98,0,0,4,3,0,71,48,69,2,33,0,174,9,70,161,69,172,240,57,202,163,220,14,251,203,199,151,117,0,105,46,81,130,142,255,67,255,27,215,119,117,251,132,2,32,109,205,10,49,47,140,176,3,88,229,98,198,164,120,19,147,117,49,147,72,53,85,100,208,7,191,47,97,19,32,107,81,0,118,0,203,56,247,21,137,124,132,161,68,95,91,193,221,251,201,110,242,154,89,205,71,10,105,5,133,176,203,20,195,20,88,231,0,0,1,160,223,241,173,126,0,0,4,3,0,71,48,69,2,32,29,152,55,249,89,63,167,146,4,203,85,208,20,235,24,62,202,75,34,149,187,248,186,241,156,218,247,185,98,247,161,92,2,33,0,214,107,111,78,115,60,205,179,74,143,160,43,82,149,166,129,52,84,68,48,224,56,46,17,212,45,150,229,196,138,233,242,48,10,6,8,42,134,72,206,61,4,3,2,3,71,0,48,68,2,32,52,105,87,197,98,211,78,169,249,249,116,14,144,253,58,125,56,26,17,184,195,48,1,212,32,185,136,106,156,124,139,155,2,32,7,147,35,252,105,105,241,153,52,31,71,192,215,196,121,77,57,202,31,154,74,255,121,164,63,18,54,210,0,88,218,244]},\"issuerCertificate\":{\"subject\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"Cloudflare TLS Issuing ECC CA 3\"},\"issuer\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"SSL.com TLS Transit ECC CA R2\"},\"infoAccess\":{\"CA Issuers - URI\":[\"http://cert.ssl.com/SSL.com-TLS-T-ECC-R2.cer\"]},\"ca\":true,\"bits\":256,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,253,29,20,20,142,35,66,147,38,207,112,208,137,215,177,30,114,172,62,93,89,82,129,223,28,245,214,37,185,214,119,7,133,11,141,18,177,43,63,64,185,31,114,6,224,255,64,82,10,147,73,248,201,113,176,126,238,224,163,69,81,24,68,116]},\"asn1Curve\":\"prime256v1\",\"nistCurve\":\"P-256\",\"valid_from\":\"May 29 19:49:45 2025 GMT\",\"valid_to\":\"May 27 19:49:44 2035 GMT\",\"fingerprint\":\"AE:80:1E:D1:C5:5B:B5:79:D7:92:08:B0:D7:72:AC:FB:8C:C3:A2:08\",\"fingerprint256\":\"F1:5F:29:AB:EF:73:AA:4D:D9:AB:75:4B:AE:AE:36:85:BD:D3:87:4B:46:B5:25:07:11:77:62:86:85:71:80:26\",\"fingerprint512\":\"2B:20:5D:F6:95:5B:37:99:A7:CE:63:03:E0:8C:D9:CC:A7:01:5D:32:84:9E:1C:97:EE:8A:57:D3:7C:31:36:10:38:B1:31:8F:B9:9F:1D:C3:C3:43:F1:E7:CE:17:A6:C9:57:AD:95:AC:0C:3A:2D:3C:A3:6A:5F:7F:17:D1:98:ED\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.2\",\"1.3.6.1.5.5.7.3.1\"],\"serialNumber\":\"31EEE88AFB87CD9EF8336604743F9B27\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,2,226,48,130,2,105,160,3,2,1,2,2,16,49,238,232,138,251,135,205,158,248,51,102,4,116,63,155,39,48,10,6,8,42,134,72,206,61,4,3,3,48,79,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,38,48,36,6,3,85,4,3,12,29,83,83,76,46,99,111,109,32,84,76,83,32,84,114,97,110,115,105,116,32,69,67,67,32,67,65,32,82,50,48,30,23,13,50,53,48,53,50,57,49,57,52,57,52,53,90,23,13,51,53,48,53,50,55,49,57,52,57,52,52,90,48,81,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,40,48,38,6,3,85,4,3,12,31,67,108,111,117,100,102,108,97,114,101,32,84,76,83,32,73,115,115,117,105,110,103,32,69,67,67,32,67,65,32,51,48,89,48,19,6,7,42,134,72,206,61,2,1,6,8,42,134,72,206,61,3,1,7,3,66,0,4,253,29,20,20,142,35,66,147,38,207,112,208,137,215,177,30,114,172,62,93,89,82,129,223,28,245,214,37,185,214,119,7,133,11,141,18,177,43,63,64,185,31,114,6,224,255,64,82,10,147,73,248,201,113,176,126,238,224,163,69,81,24,68,116,163,130,1,35,48,130,1,31,48,18,6,3,85,29,19,1,1,255,4,8,48,6,1,1,255,2,1,0,48,31,6,3,85,29,35,4,24,48,22,128,20,50,162,199,216,88,139,255,127,192,60,242,85,105,51,236,206,204,31,188,151,48,72,6,8,43,6,1,5,5,7,1,1,4,60,48,58,48,56,6,8,43,6,1,5,5,7,48,2,134,44,104,116,116,112,58,47,47,99,101,114,116,46,115,115,108,46,99,111,109,47,83,83,76,46,99,111,109,45,84,76,83,45,84,45,69,67,67,45,82,50,46,99,101,114,48,17,6,3,85,29,32,4,10,48,8,48,6,6,4,85,29,32,0,48,29,6,3,85,29,37,4,22,48,20,6,8,43,6,1,5,5,7,3,2,6,8,43,6,1,5,5,7,3,1,48,61,6,3,85,29,31,4,54,48,52,48,50,160,48,160,46,134,44,104,116,116,112,58,47,47,99,114,108,115,46,115,115,108,46,99,111,109,47,83,83,76,46,99,111,109,45,84,76,83,45,84,45,69,67,67,45,82,50,46,99,114,108,48,29,6,3,85,29,14,4,22,4,20,131,3,253,231,246,245,74,77,21,65,244,237,34,22,211,50,10,62,202,102,48,14,6,3,85,29,15,1,1,255,4,4,3,2,1,134,48,10,6,8,42,134,72,206,61,4,3,3,3,103,0,48,100,2,48,100,91,247,96,76,10,229,223,170,113,232,181,76,91,55,199,46,253,41,20,63,143,244,26,14,99,56,171,103,102,146,216,140,44,64,241,219,210,180,29,100,198,57,54,8,83,78,78,2,48,40,176,13,78,245,126,27,88,85,27,165,81,206,123,228,114,107,160,181,224,143,224,100,166,64,110,109,209,231,111,111,154,62,144,135,236,179,250,64,212,63,94,111,77,138,167,113,3]},\"issuerCertificate\":{\"subject\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"SSL.com TLS Transit ECC CA R2\"},\"issuer\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"SSL.com TLS ECC Root CA 2022\"},\"infoAccess\":{\"CA Issuers - URI\":[\"http://cert.ssl.com/SSLcom-TLS-Root-2022-ECC.cer\"]},\"ca\":true,\"bits\":384,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,100,231,125,153,3,83,92,145,30,234,248,216,35,173,161,191,44,226,99,137,239,40,104,246,237,215,240,236,171,104,174,119,43,228,118,151,27,80,235,219,89,40,45,111,184,54,185,171,141,151,202,76,34,158,238,137,217,2,63,253,122,115,58,144,141,43,204,47,58,55,131,218,13,180,45,69,27,115,114,9,101,95,120,33,61,231,139,102,63,71,253,9,41,214,20,15]},\"asn1Curve\":\"secp384r1\",\"nistCurve\":\"P-384\",\"valid_from\":\"Oct 21 17:02:23 2022 GMT\",\"valid_to\":\"Oct 17 17:02:22 2037 GMT\",\"fingerprint\":\"E1:51:88:4D:AE:92:4B:08:EF:26:46:01:21:D7:79:06:D5:D1:8A:C8\",\"fingerprint256\":\"5D:1B:C3:99:27:4E:64:9E:1C:72:69:7D:E9:1A:54:AD:72:50:88:C5:22:1C:B6:1E:17:EE:9C:29:0B:C4:2A:92\",\"fingerprint512\":\"7B:34:2C:8E:C1:DA:B8:64:F2:93:10:1F:E2:1D:11:F1:05:17:15:73:BE:5B:68:6E:EF:82:6E:11:07:E4:3F:5A:92:74:E0:50:6B:B8:9A:21:88:61:BC:21:C6:61:73:CD:C4:C8:72:5F:FB:6A:C0:D3:FC:40:CD:2E:6A:93:6E:AE\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.2\",\"1.3.6.1.5.5.7.3.1\"],\"serialNumber\":\"604D8AF8D00BA8748B955853172C5F2E\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,3,52,48,130,2,185,160,3,2,1,2,2,16,96,77,138,248,208,11,168,116,139,149,88,83,23,44,95,46,48,10,6,8,42,134,72,206,61,4,3,3,48,78,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,37,48,35,6,3,85,4,3,12,28,83,83,76,46,99,111,109,32,84,76,83,32,69,67,67,32,82,111,111,116,32,67,65,32,50,48,50,50,48,30,23,13,50,50,49,48,50,49,49,55,48,50,50,51,90,23,13,51,55,49,48,49,55,49,55,48,50,50,50,90,48,79,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,38,48,36,6,3,85,4,3,12,29,83,83,76,46,99,111,109,32,84,76,83,32,84,114,97,110,115,105,116,32,69,67,67,32,67,65,32,82,50,48,118,48,16,6,7,42,134,72,206,61,2,1,6,5,43,129,4,0,34,3,98,0,4,100,231,125,153,3,83,92,145,30,234,248,216,35,173,161,191,44,226,99,137,239,40,104,246,237,215,240,236,171,104,174,119,43,228,118,151,27,80,235,219,89,40,45,111,184,54,185,171,141,151,202,76,34,158,238,137,217,2,63,253,122,115,58,144,141,43,204,47,58,55,131,218,13,180,45,69,27,115,114,9,101,95,120,33,61,231,139,102,63,71,253,9,41,214,20,15,163,130,1,89,48,130,1,85,48,18,6,3,85,29,19,1,1,255,4,8,48,6,1,1,255,2,1,1,48,31,6,3,85,29,35,4,24,48,22,128,20,137,143,47,163,232,43,160,20,84,123,243,86,184,38,95,103,56,11,156,208,48,76,6,8,43,6,1,5,5,7,1,1,4,64,48,62,48,60,6,8,43,6,1,5,5,7,48,2,134,48,104,116,116,112,58,47,47,99,101,114,116,46,115,115,108,46,99,111,109,47,83,83,76,99,111,109,45,84,76,83,45,82,111,111,116,45,50,48,50,50,45,69,67,67,46,99,101,114,48,63,6,3,85,29,32,4,56,48,54,48,52,6,4,85,29,32,0,48,44,48,42,6,8,43,6,1,5,5,7,2,1,22,30,104,116,116,112,115,58,47,47,119,119,119,46,115,115,108,46,99,111,109,47,114,101,112,111,115,105,116,111,114,121,48,29,6,3,85,29,37,4,22,48,20,6,8,43,6,1,5,5,7,3,2,6,8,43,6,1,5,5,7,3,1,48,65,6,3,85,29,31,4,58,48,56,48,54,160,52,160,50,134,48,104,116,116,112,58,47,47,99,114,108,115,46,115,115,108,46,99,111,109,47,83,83,76,99,111,109,45,84,76,83,45,82,111,111,116,45,50,48,50,50,45,69,67,67,46,99,114,108,48,29,6,3,85,29,14,4,22,4,20,50,162,199,216,88,139,255,127,192,60,242,85,105,51,236,206,204,31,188,151,48,14,6,3,85,29,15,1,1,255,4,4,3,2,1,134,48,10,6,8,42,134,72,206,61,4,3,3,3,105,0,48,102,2,49,0,184,74,66,62,123,103,45,179,89,211,55,211,2,70,41,125,239,62,182,225,108,75,2,159,120,133,62,237,53,145,143,100,183,189,98,34,200,203,56,10,169,111,219,19,70,246,135,208,2,49,0,175,87,149,204,69,203,167,3,41,80,104,36,28,224,159,198,193,29,105,239,42,207,11,64,209,89,103,178,44,9,16,90,46,88,150,214,189,15,25,87,239,132,5,22,75,5,8,253]},\"issuerCertificate\":{\"subject\":{\"C\":\"US\",\"O\":\"SSL Corporation\",\"CN\":\"SSL.com TLS ECC Root CA 2022\"},\"issuer\":{\"C\":\"GB\",\"ST\":\"Greater Manchester\",\"L\":\"Salford\",\"O\":\"Comodo CA Limited\",\"CN\":\"AAA Certificate Services\"},\"infoAccess\":{\"OCSP - URI\":[\"http://ocsp.comodoca.com\"]},\"ca\":true,\"bits\":384,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,69,41,53,115,250,194,184,35,206,20,125,168,177,77,160,91,54,238,42,44,83,195,96,9,53,178,36,102,38,105,192,179,149,214,93,146,64,25,14,198,165,19,112,244,239,18,81,40,93,231,204,189,249,60,133,193,207,148,144,201,43,206,146,66,88,89,103,253,148,39,16,100,140,79,4,177,77,73,228,123,79,155,245,231,8,248,3,136,247,167,195,146,75,25,84,129]},\"asn1Curve\":\"secp384r1\",\"nistCurve\":\"P-384\",\"valid_from\":\"Aug  1 00:00:00 2025 GMT\",\"valid_to\":\"Dec 31 23:59:59 2028 GMT\",\"fingerprint\":\"5C:85:D2:01:CA:68:B3:9F:CC:D2:4A:89:27:35:9E:F2:95:AF:83:DC\",\"fingerprint256\":\"BA:06:D3:D3:E3:48:FC:E7:47:8C:C8:4B:42:2D:0E:63:8E:9E:22:1E:F1:A0:B5:3A:DC:14:CC:70:E0:4B:8A:B8\",\"fingerprint512\":\"30:38:EA:15:FC:E0:CB:24:AA:F3:5E:35:E1:64:08:2B:9E:99:55:20:87:87:75:7F:BB:E3:CD:F9:C2:93:8D:96:FF:97:FA:A6:41:7B:A9:7B:38:3C:E4:0A:1D:81:AD:64:C7:AB:BC:49:BE:98:78:BE:F9:00:4A:90:B3:AD:3A:B2\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.1\",\"1.3.6.1.5.5.7.3.2\"],\"serialNumber\":\"173DCA60EA9F8E1026DAFCD72810E48E\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,4,62,48,130,3,38,160,3,2,1,2,2,16,23,61,202,96,234,159,142,16,38,218,252,215,40,16,228,142,48,13,6,9,42,134,72,134,247,13,1,1,11,5,0,48,123,49,11,48,9,6,3,85,4,6,19,2,71,66,49,27,48,25,6,3,85,4,8,12,18,71,114,101,97,116,101,114,32,77,97,110,99,104,101,115,116,101,114,49,16,48,14,6,3,85,4,7,12,7,83,97,108,102,111,114,100,49,26,48,24,6,3,85,4,10,12,17,67,111,109,111,100,111,32,67,65,32,76,105,109,105,116,101,100,49,33,48,31,6,3,85,4,3,12,24,65,65,65,32,67,101,114,116,105,102,105,99,97,116,101,32,83,101,114,118,105,99,101,115,48,30,23,13,50,53,48,56,48,49,48,48,48,48,48,48,90,23,13,50,56,49,50,51,49,50,51,53,57,53,57,90,48,78,49,11,48,9,6,3,85,4,6,19,2,85,83,49,24,48,22,6,3,85,4,10,12,15,83,83,76,32,67,111,114,112,111,114,97,116,105,111,110,49,37,48,35,6,3,85,4,3,12,28,83,83,76,46,99,111,109,32,84,76,83,32,69,67,67,32,82,111,111,116,32,67,65,32,50,48,50,50,48,118,48,16,6,7,42,134,72,206,61,2,1,6,5,43,129,4,0,34,3,98,0,4,69,41,53,115,250,194,184,35,206,20,125,168,177,77,160,91,54,238,42,44,83,195,96,9,53,178,36,102,38,105,192,179,149,214,93,146,64,25,14,198,165,19,112,244,239,18,81,40,93,231,204,189,249,60,133,193,207,148,144,201,43,206,146,66,88,89,103,253,148,39,16,100,140,79,4,177,77,73,228,123,79,155,245,231,8,248,3,136,247,167,195,146,75,25,84,129,163,130,1,151,48,130,1,147,48,31,6,3,85,29,35,4,24,48,22,128,20,160,17,10,35,62,150,241,7,236,226,175,41,239,130,165,127,208,48,164,180,48,29,6,3,85,29,14,4,22,4,20,137,143,47,163,232,43,160,20,84,123,243,86,184,38,95,103,56,11,156,208,48,14,6,3,85,29,15,1,1,255,4,4,3,2,1,134,48,18,6,3,85,29,19,1,1,255,4,8,48,6,1,1,255,2,1,2,48,29,6,3,85,29,37,4,22,48,20,6,8,43,6,1,5,5,7,3,1,6,8,43,6,1,5,5,7,3,2,48,129,146,6,3,85,29,32,4,129,138,48,129,135,48,7,6,5,103,129,12,1,1,48,8,6,6,103,129,12,1,2,1,48,8,6,6,103,129,12,1,2,2,48,8,6,6,103,129,12,1,2,3,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,1,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,2,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,3,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,4,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,5,48,14,6,12,43,6,1,4,1,130,169,48,1,3,1,6,48,67,6,3,85,29,31,4,60,48,58,48,56,160,54,160,52,134,50,104,116,116,112,58,47,47,99,114,108,46,99,111,109,111,100,111,99,97,46,99,111,109,47,65,65,65,67,101,114,116,105,102,105,99,97,116,101,83,101,114,118,105,99,101,115,46,99,114,108,48,52,6,8,43,6,1,5,5,7,1,1,4,40,48,38,48,36,6,8,43,6,1,5,5,7,48,1,134,24,104,116,116,112,58,47,47,111,99,115,112,46,99,111,109,111,100,111,99,97,46,99,111,109,48,13,6,9,42,134,72,134,247,13,1,1,11,5,0,3,130,1,1,0,67,229,112,251,75,238,36,60,240,56,40,134,203,222,70,195,43,43,156,96,88,0,87,91,107,232,208,78,69,216,190,66,86,26,210,3,215,23,242,82,18,159,242,214,110,167,5,17,166,67,168,84,31,189,102,251,17,215,144,89,240,228,153,173,28,60,82,29,246,67,62,155,97,169,94,220,246,124,210,44,192,134,80,48,73,100,116,60,62,204,67,19,20,197,143,91,216,107,2,189,38,252,207,85,224,39,23,204,161,148,145,68,36,213,129,96,225,185,174,214,7,99,1,118,211,126,3,207,51,161,119,88,252,248,85,158,140,116,53,150,204,197,119,66,73,103,54,202,136,212,16,237,192,193,193,132,113,50,253,248,141,112,232,15,61,162,115,80,209,34,159,25,123,63,95,62,148,106,52,252,155,72,7,212,27,93,81,70,238,175,118,129,223,233,244,204,159,212,238,237,249,12,168,134,191,153,166,52,111,53,149,214,107,90,48,83,39,84,253,127,21,72,90,105,155,77,92,217,191,40,13,50,213,46,153,43,57,13,233,101,142,25,73,2,39,64,177,55,62,230,32,129,38,106,145,22]},\"validTo\":\"2028-12-31T23:59:59.000Z\",\"daysRemaining\":785,\"certType\":\"root CA\"},\"validTo\":\"2037-10-17T17:02:22.000Z\",\"daysRemaining\":3996,\"certType\":\"intermediate CA\"},\"validTo\":\"2035-05-27T19:49:44.000Z\",\"daysRemaining\":3122,\"certType\":\"intermediate CA\"},\"validTo\":\"2026-12-25T22:56:35.000Z\",\"validFor\":[\"example.com\",\"*.example.com\"],\"daysRemaining\":48,\"certType\":\"server\"},\"hostnameMatchMonitorUrl\":true}'),
(3,4,'{\"valid\":true,\"certInfo\":{\"subject\":{\"CN\":\"httpbin.hbin02.postmanlabs.com\"},\"issuer\":{\"C\":\"US\",\"O\":\"Let\'s Encrypt\",\"CN\":\"YE2\"},\"subjectaltname\":\"DNS:eu.httpbin.org, DNS:httpbin.hbin02.postmanlabs.com, DNS:httpbin.org, DNS:www.httpbin.org\",\"infoAccess\":{\"CA Issuers - URI\":[\"http://ye2.i.lencr.org/\"]},\"ca\":false,\"bits\":256,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,186,120,134,243,3,151,237,243,171,97,254,16,41,231,221,5,221,33,217,51,208,153,216,197,58,178,128,235,167,70,193,230,184,229,112,255,102,108,195,129,27,128,138,193,42,78,34,117,57,65,203,156,238,239,121,234,41,244,24,143,15,173,211,39]},\"asn1Curve\":\"prime256v1\",\"nistCurve\":\"P-256\",\"valid_from\":\"Nov  5 19:21:40 2026 GMT\",\"valid_to\":\"Feb  3 19:21:39 2027 GMT\",\"fingerprint\":\"39:77:D2:A0:DE:28:C7:DD:C9:0A:3E:DB:D2:35:66:CF:0A:1F:18:14\",\"fingerprint256\":\"6B:A3:B7:42:CE:55:B5:DC:42:56:50:02:44:B9:03:B5:14:1C:80:E8:4C:B7:7A:A5:BF:49:EE:31:77:4C:AD:55\",\"fingerprint512\":\"BE:D4:5B:92:52:21:99:B3:32:52:E1:B3:0F:BB:B7:8C:3A:E5:77:FE:F8:A3:72:41:A6:56:24:79:AC:65:5F:0C:E7:5C:3F:7E:F5:BB:04:30:55:C1:C0:DF:C3:12:6D:98:1F:7A:57:7E:A5:E1:36:9C:86:18:91:73:67:F6:BC:C2\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.1\"],\"serialNumber\":\"05BC5FDB7C8EEC5863F7A41B012265ABF3A3\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,3,216,48,130,3,94,160,3,2,1,2,2,18,5,188,95,219,124,142,236,88,99,247,164,27,1,34,101,171,243,163,48,10,6,8,42,134,72,206,61,4,3,3,48,51,49,11,48,9,6,3,85,4,6,19,2,85,83,49,22,48,20,6,3,85,4,10,19,13,76,101,116,39,115,32,69,110,99,114,121,112,116,49,12,48,10,6,3,85,4,3,19,3,89,69,50,48,30,23,13,50,54,49,49,48,53,49,57,50,49,52,48,90,23,13,50,55,48,50,48,51,49,57,50,49,51,57,90,48,41,49,39,48,37,6,3,85,4,3,19,30,104,116,116,112,98,105,110,46,104,98,105,110,48,50,46,112,111,115,116,109,97,110,108,97,98,115,46,99,111,109,48,89,48,19,6,7,42,134,72,206,61,2,1,6,8,42,134,72,206,61,3,1,7,3,66,0,4,186,120,134,243,3,151,237,243,171,97,254,16,41,231,221,5,221,33,217,51,208,153,216,197,58,178,128,235,167,70,193,230,184,229,112,255,102,108,195,129,27,128,138,193,42,78,34,117,57,65,203,156,238,239,121,234,41,244,24,143,15,173,211,39,163,130,2,90,48,130,2,86,48,14,6,3,85,29,15,1,1,255,4,4,3,2,7,128,48,19,6,3,85,29,37,4,12,48,10,6,8,43,6,1,5,5,7,3,1,48,12,6,3,85,29,19,1,1,255,4,2,48,0,48,29,6,3,85,29,14,4,22,4,20,37,14,137,193,118,14,131,115,150,100,25,233,37,249,204,41,186,131,156,13,48,31,6,3,85,29,35,4,24,48,22,128,20,185,89,242,142,207,34,240,134,211,55,72,255,118,20,24,186,130,216,85,135,48,51,6,8,43,6,1,5,5,7,1,1,4,39,48,37,48,35,6,8,43,6,1,5,5,7,48,2,134,23,104,116,116,112,58,47,47,121,101,50,46,105,46,108,101,110,99,114,46,111,114,103,47,48,87,6,3,85,29,17,4,80,48,78,130,14,101,117,46,104,116,116,112,98,105,110,46,111,114,103,130,30,104,116,116,112,98,105,110,46,104,98,105,110,48,50,46,112,111,115,116,109,97,110,108,97,98,115,46,99,111,109,130,11,104,116,116,112,98,105,110,46,111,114,103,130,15,119,119,119,46,104,116,116,112,98,105,110,46,111,114,103,48,19,6,3,85,29,32,4,12,48,10,48,8,6,6,103,129,12,1,2,1,48,46,6,3,85,29,31,4,39,48,37,48,35,160,33,160,31,134,29,104,116,116,112,58,47,47,121,101,50,46,99,46,108,101,110,99,114,46,111,114,103,47,51,54,46,99,114,108,48,130,1,12,6,10,43,6,1,4,1,214,121,2,4,2,4,129,253,4,129,250,0,248,0,126,0,89,110,108,51,134,148,178,89,114,162,86,200,160,232,221,144,74,118,232,8,61,218,135,59,1,8,56,40,20,60,238,89,0,0,1,161,173,94,115,220,0,8,0,0,5,0,29,75,228,45,4,3,0,71,48,69,2,33,0,206,99,140,22,108,175,159,35,193,45,50,239,59,243,62,234,90,247,101,82,184,231,122,42,69,4,159,14,234,127,46,22,2,32,26,128,106,93,98,126,38,226,82,122,104,49,127,176,46,123,242,0,39,22,143,237,154,128,113,158,47,133,81,58,6,232,0,118,0,214,213,141,169,208,23,83,243,106,74,160,199,87,73,2,175,235,199,220,44,211,140,217,247,100,200,12,137,25,30,159,2,0,0,1,161,173,94,115,130,0,0,4,3,0,71,48,69,2,33,0,148,7,234,3,126,98,108,26,196,188,37,24,156,197,182,85,100,85,90,28,97,248,240,197,253,142,162,185,255,159,91,174,2,32,115,69,6,66,115,70,157,190,42,35,135,196,31,173,80,115,28,72,217,128,186,107,128,240,137,231,196,253,11,38,91,92,48,10,6,8,42,134,72,206,61,4,3,3,3,104,0,48,101,2,49,0,187,128,238,131,61,104,12,109,34,176,19,105,49,87,20,100,12,171,119,151,141,84,179,72,244,93,240,233,63,41,189,157,49,127,124,230,177,194,230,27,136,126,14,111,111,234,128,143,2,48,23,53,67,22,97,236,230,45,255,254,244,202,131,252,241,75,138,146,243,9,52,19,165,220,122,113,157,45,120,32,29,169,212,161,202,82,189,211,24,85,234,136,109,6,221,80,87,140]},\"issuerCertificate\":{\"subject\":{\"C\":\"US\",\"O\":\"Let\'s Encrypt\",\"CN\":\"YE2\"},\"issuer\":{\"C\":\"US\",\"O\":\"ISRG\",\"CN\":\"Root YE\"},\"infoAccess\":{\"CA Issuers - URI\":[\"http://ye.i.lencr.org/\"]},\"ca\":true,\"bits\":384,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,113,154,180,51,145,214,196,16,189,204,169,123,119,116,148,45,181,135,56,237,172,100,116,52,199,185,222,83,62,163,54,207,155,146,15,151,38,147,69,171,30,85,151,190,121,111,168,18,234,136,29,248,146,91,177,33,185,140,200,8,170,223,246,3,233,239,52,254,245,118,231,210,188,93,2,29,140,85,229,161,131,154,210,98,20,239,87,251,29,13,61,62,246,3,151,15]},\"asn1Curve\":\"secp384r1\",\"nistCurve\":\"P-384\",\"valid_from\":\"Sep  3 00:00:00 2025 GMT\",\"valid_to\":\"Sep  2 23:59:59 2028 GMT\",\"fingerprint\":\"F8:15:8C:A8:A3:A7:C5:DB:D7:84:22:18:F5:D9:49:CE:C8:3A:CD:BA\",\"fingerprint256\":\"97:65:8D:E8:C6:8D:FA:98:AC:E1:E5:02:8A:63:D5:4A:1A:AE:91:1B:3E:21:47:10:76:C6:85:0C:D0:8C:BA:B4\",\"fingerprint512\":\"2D:FD:6C:37:2D:61:71:42:AD:8B:BD:10:44:08:52:18:DD:A3:92:09:CC:F2:DA:45:84:82:51:21:6E:03:82:25:36:B3:43:21:12:63:42:23:B0:46:E3:7D:10:D3:E5:B4:D0:CF:B1:A1:69:AE:5F:F9:11:3F:12:95:22:2E:ED:87\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.1\"],\"serialNumber\":\"4DF3B15DD6C0784C507CD37B58E6F115\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,2,140,48,130,2,17,160,3,2,1,2,2,16,77,243,177,93,214,192,120,76,80,124,211,123,88,230,241,21,48,10,6,8,42,134,72,206,61,4,3,3,48,46,49,11,48,9,6,3,85,4,6,19,2,85,83,49,13,48,11,6,3,85,4,10,19,4,73,83,82,71,49,16,48,14,6,3,85,4,3,19,7,82,111,111,116,32,89,69,48,30,23,13,50,53,48,57,48,51,48,48,48,48,48,48,90,23,13,50,56,48,57,48,50,50,51,53,57,53,57,90,48,51,49,11,48,9,6,3,85,4,6,19,2,85,83,49,22,48,20,6,3,85,4,10,19,13,76,101,116,39,115,32,69,110,99,114,121,112,116,49,12,48,10,6,3,85,4,3,19,3,89,69,50,48,118,48,16,6,7,42,134,72,206,61,2,1,6,5,43,129,4,0,34,3,98,0,4,113,154,180,51,145,214,196,16,189,204,169,123,119,116,148,45,181,135,56,237,172,100,116,52,199,185,222,83,62,163,54,207,155,146,15,151,38,147,69,171,30,85,151,190,121,111,168,18,234,136,29,248,146,91,177,33,185,140,200,8,170,223,246,3,233,239,52,254,245,118,231,210,188,93,2,29,140,85,229,161,131,154,210,98,20,239,87,251,29,13,61,62,246,3,151,15,163,129,238,48,129,235,48,14,6,3,85,29,15,1,1,255,4,4,3,2,1,134,48,19,6,3,85,29,37,4,12,48,10,6,8,43,6,1,5,5,7,3,1,48,18,6,3,85,29,19,1,1,255,4,8,48,6,1,1,255,2,1,0,48,29,6,3,85,29,14,4,22,4,20,185,89,242,142,207,34,240,134,211,55,72,255,118,20,24,186,130,216,85,135,48,31,6,3,85,29,35,4,24,48,22,128,20,163,200,38,90,142,161,76,208,53,99,252,155,35,200,58,174,86,243,79,86,48,50,6,8,43,6,1,5,5,7,1,1,4,38,48,36,48,34,6,8,43,6,1,5,5,7,48,2,134,22,104,116,116,112,58,47,47,121,101,46,105,46,108,101,110,99,114,46,111,114,103,47,48,19,6,3,85,29,32,4,12,48,10,48,8,6,6,103,129,12,1,2,1,48,39,6,3,85,29,31,4,32,48,30,48,28,160,26,160,24,134,22,104,116,116,112,58,47,47,121,101,46,99,46,108,101,110,99,114,46,111,114,103,47,48,10,6,8,42,134,72,206,61,4,3,3,3,105,0,48,102,2,49,0,200,114,124,57,117,198,75,55,215,223,202,117,231,158,66,195,254,43,82,36,76,156,36,246,247,177,25,51,122,160,104,194,234,123,188,235,0,193,170,168,10,115,103,254,56,125,195,39,2,49,0,137,51,160,143,149,163,130,226,148,24,97,178,109,84,246,27,12,170,200,111,97,10,147,147,105,58,145,58,253,123,109,65,134,85,255,120,34,148,31,101,248,39,63,12,229,112,155,40]},\"issuerCertificate\":{\"subject\":{\"C\":\"US\",\"O\":\"ISRG\",\"CN\":\"Root YE\"},\"issuer\":{\"C\":\"US\",\"O\":\"Internet Security Research Group\",\"CN\":\"ISRG Root X2\"},\"infoAccess\":{\"CA Issuers - URI\":[\"http://x2.i.lencr.org/\"]},\"ca\":true,\"bits\":384,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,60,18,255,171,225,173,197,106,113,176,104,251,8,29,35,119,240,159,220,123,12,210,73,57,143,229,76,235,98,210,249,48,185,19,124,237,24,68,133,49,245,236,78,215,200,88,214,176,246,33,32,251,127,195,51,170,197,55,118,111,238,25,36,220,189,32,61,56,99,44,5,245,78,115,106,192,213,200,100,141,18,181,77,183,63,46,72,104,121,139,53,188,238,62,251,25]},\"asn1Curve\":\"secp384r1\",\"nistCurve\":\"P-384\",\"valid_from\":\"May 13 00:00:00 2026 GMT\",\"valid_to\":\"Sep  2 23:59:59 2032 GMT\",\"fingerprint\":\"F3:76:20:FC:4B:1C:DD:D2:A3:D7:4B:1D:5E:8D:B4:2E:24:4C:A0:06\",\"fingerprint256\":\"0F:C0:90:1C:CA:2B:AE:9E:9F:DB:B0:2D:50:D0:2F:10:94:F7:B3:66:72:08:69:91:B9:E8:97:62:6D:C4:85:F0\",\"fingerprint512\":\"90:98:75:58:8E:0B:19:28:92:6A:5F:A0:B7:D5:1B:B5:8D:89:A9:BB:33:97:4A:AB:7F:EC:E4:0B:AE:EF:5E:DF:3F:41:D3:B3:23:F2:17:7D:CE:6E:1C:D9:23:F7:59:81:DD:13:E9:E2:3E:B3:F6:9C:2D:5B:F7:4C:5F:77:C7:A9\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.1\"],\"serialNumber\":\"872165FC34B6E5FBA8ADD5B3705FB53A\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,2,166,48,130,2,43,160,3,2,1,2,2,17,0,135,33,101,252,52,182,229,251,168,173,213,179,112,95,181,58,48,10,6,8,42,134,72,206,61,4,3,3,48,79,49,11,48,9,6,3,85,4,6,19,2,85,83,49,41,48,39,6,3,85,4,10,19,32,73,110,116,101,114,110,101,116,32,83,101,99,117,114,105,116,121,32,82,101,115,101,97,114,99,104,32,71,114,111,117,112,49,21,48,19,6,3,85,4,3,19,12,73,83,82,71,32,82,111,111,116,32,88,50,48,30,23,13,50,54,48,53,49,51,48,48,48,48,48,48,90,23,13,51,50,48,57,48,50,50,51,53,57,53,57,90,48,46,49,11,48,9,6,3,85,4,6,19,2,85,83,49,13,48,11,6,3,85,4,10,19,4,73,83,82,71,49,16,48,14,6,3,85,4,3,19,7,82,111,111,116,32,89,69,48,118,48,16,6,7,42,134,72,206,61,2,1,6,5,43,129,4,0,34,3,98,0,4,60,18,255,171,225,173,197,106,113,176,104,251,8,29,35,119,240,159,220,123,12,210,73,57,143,229,76,235,98,210,249,48,185,19,124,237,24,68,133,49,245,236,78,215,200,88,214,176,246,33,32,251,127,195,51,170,197,55,118,111,238,25,36,220,189,32,61,56,99,44,5,245,78,115,106,192,213,200,100,141,18,181,77,183,63,46,72,104,121,139,53,188,238,62,251,25,163,129,235,48,129,232,48,14,6,3,85,29,15,1,1,255,4,4,3,2,1,6,48,19,6,3,85,29,37,4,12,48,10,6,8,43,6,1,5,5,7,3,1,48,15,6,3,85,29,19,1,1,255,4,5,48,3,1,1,255,48,29,6,3,85,29,14,4,22,4,20,163,200,38,90,142,161,76,208,53,99,252,155,35,200,58,174,86,243,79,86,48,31,6,3,85,29,35,4,24,48,22,128,20,124,66,150,174,222,75,72,59,250,146,248,158,140,207,109,139,169,114,55,149,48,50,6,8,43,6,1,5,5,7,1,1,4,38,48,36,48,34,6,8,43,6,1,5,5,7,48,2,134,22,104,116,116,112,58,47,47,120,50,46,105,46,108,101,110,99,114,46,111,114,103,47,48,19,6,3,85,29,32,4,12,48,10,48,8,6,6,103,129,12,1,2,1,48,39,6,3,85,29,31,4,32,48,30,48,28,160,26,160,24,134,22,104,116,116,112,58,47,47,120,50,46,99,46,108,101,110,99,114,46,111,114,103,47,48,10,6,8,42,134,72,206,61,4,3,3,3,105,0,48,102,2,49,0,197,53,245,96,173,155,21,77,15,197,7,5,148,104,153,174,61,103,184,207,179,174,3,153,173,30,78,237,78,10,21,91,7,253,137,236,101,119,158,246,10,3,30,120,186,77,70,245,2,49,0,237,38,136,181,73,187,195,203,54,37,215,94,72,132,254,11,10,220,36,209,76,95,218,122,142,27,49,126,54,103,14,52,114,196,84,232,0,182,234,20,150,215,242,22,105,171,58,118]},\"issuerCertificate\":{\"subject\":{\"C\":\"US\",\"O\":\"Internet Security Research Group\",\"CN\":\"ISRG Root X2\"},\"issuer\":{\"C\":\"US\",\"O\":\"Internet Security Research Group\",\"CN\":\"ISRG Root X1\"},\"infoAccess\":{\"CA Issuers - URI\":[\"http://x1.i.lencr.org/\"]},\"ca\":true,\"bits\":384,\"pubkey\":{\"type\":\"Buffer\",\"data\":[4,205,155,213,159,128,131,10,236,9,74,243,22,74,62,92,207,119,172,222,103,5,13,29,7,182,220,22,251,90,139,20,219,226,113,96,196,186,69,149,17,137,142,234,6,223,247,42,22,28,164,185,197,197,50,224,3,224,30,130,24,56,139,215,69,216,10,106,110,230,0,119,251,2,81,125,34,216,10,110,154,91,119,223,240,250,65,236,57,220,117,202,104,7,12,31,234]},\"asn1Curve\":\"secp384r1\",\"nistCurve\":\"P-384\",\"valid_from\":\"May 13 00:00:00 2026 GMT\",\"valid_to\":\"Sep  2 23:59:59 2032 GMT\",\"fingerprint\":\"3C:34:E3:56:C7:FC:17:F5:AC:F0:48:24:BC:58:7C:6C:62:39:51:A2\",\"fingerprint256\":\"EE:5F:7A:BD:69:81:BB:02:55:63:2C:D8:F4:92:83:45:1B:4B:18:84:4D:12:04:0B:44:EE:00:F0:7B:8F:E2:C6\",\"fingerprint512\":\"12:F1:FF:55:86:A6:CA:2C:BC:3A:72:DA:E9:98:E4:C7:ED:64:83:99:6C:98:12:19:51:AE:64:02:1D:02:28:CB:C2:30:C4:AB:44:91:6E:DE:83:FE:C3:15:5B:8E:6B:9A:73:01:9F:86:19:00:82:07:0B:56:49:67:90:DB:D7:3A\",\"ext_key_usage\":[\"1.3.6.1.5.5.7.3.1\",\"1.3.6.1.5.5.7.3.2\"],\"serialNumber\":\"6C8F1DC727C7117F7BAF853AC980F9CD\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,4,112,48,130,2,88,160,3,2,1,2,2,16,108,143,29,199,39,199,17,127,123,175,133,58,201,128,249,205,48,13,6,9,42,134,72,134,247,13,1,1,11,5,0,48,79,49,11,48,9,6,3,85,4,6,19,2,85,83,49,41,48,39,6,3,85,4,10,19,32,73,110,116,101,114,110,101,116,32,83,101,99,117,114,105,116,121,32,82,101,115,101,97,114,99,104,32,71,114,111,117,112,49,21,48,19,6,3,85,4,3,19,12,73,83,82,71,32,82,111,111,116,32,88,49,48,30,23,13,50,54,48,53,49,51,48,48,48,48,48,48,90,23,13,51,50,48,57,48,50,50,51,53,57,53,57,90,48,79,49,11,48,9,6,3,85,4,6,19,2,85,83,49,41,48,39,6,3,85,4,10,19,32,73,110,116,101,114,110,101,116,32,83,101,99,117,114,105,116,121,32,82,101,115,101,97,114,99,104,32,71,114,111,117,112,49,21,48,19,6,3,85,4,3,19,12,73,83,82,71,32,82,111,111,116,32,88,50,48,118,48,16,6,7,42,134,72,206,61,2,1,6,5,43,129,4,0,34,3,98,0,4,205,155,213,159,128,131,10,236,9,74,243,22,74,62,92,207,119,172,222,103,5,13,29,7,182,220,22,251,90,139,20,219,226,113,96,196,186,69,149,17,137,142,234,6,223,247,42,22,28,164,185,197,197,50,224,3,224,30,130,24,56,139,215,69,216,10,106,110,230,0,119,251,2,81,125,34,216,10,110,154,91,119,223,240,250,65,236,57,220,117,202,104,7,12,31,234,163,129,245,48,129,242,48,14,6,3,85,29,15,1,1,255,4,4,3,2,1,6,48,29,6,3,85,29,37,4,22,48,20,6,8,43,6,1,5,5,7,3,1,6,8,43,6,1,5,5,7,3,2,48,15,6,3,85,29,19,1,1,255,4,5,48,3,1,1,255,48,29,6,3,85,29,14,4,22,4,20,124,66,150,174,222,75,72,59,250,146,248,158,140,207,109,139,169,114,55,149,48,31,6,3,85,29,35,4,24,48,22,128,20,121,180,89,230,123,182,229,228,1,115,128,8,136,200,26,88,246,233,155,110,48,50,6,8,43,6,1,5,5,7,1,1,4,38,48,36,48,34,6,8,43,6,1,5,5,7,48,2,134,22,104,116,116,112,58,47,47,120,49,46,105,46,108,101,110,99,114,46,111,114,103,47,48,19,6,3,85,29,32,4,12,48,10,48,8,6,6,103,129,12,1,2,1,48,39,6,3,85,29,31,4,32,48,30,48,28,160,26,160,24,134,22,104,116,116,112,58,47,47,120,49,46,99,46,108,101,110,99,114,46,111,114,103,47,48,13,6,9,42,134,72,134,247,13,1,1,11,5,0,3,130,2,1,0,61,191,123,215,235,152,204,77,164,37,116,222,165,7,122,8,62,49,93,176,207,222,184,225,138,23,118,171,71,241,28,150,97,192,78,239,23,227,140,97,23,117,77,94,254,141,14,144,65,98,202,117,2,57,34,36,254,242,203,163,187,109,207,160,143,1,227,101,89,240,180,92,217,40,25,229,160,43,168,227,94,250,51,5,161,174,24,126,103,209,71,232,178,19,30,89,59,148,70,163,77,228,109,52,177,37,195,76,26,233,167,111,166,130,247,89,66,54,252,43,93,83,96,192,54,229,0,62,96,115,95,108,129,11,208,25,226,2,18,146,218,11,39,38,52,179,173,214,75,35,225,25,237,33,50,169,212,118,174,72,221,212,171,173,44,53,62,159,116,197,203,24,231,137,149,70,186,190,24,150,19,15,222,102,63,117,95,121,34,187,128,146,72,206,102,39,171,234,17,129,116,72,25,93,123,218,11,63,110,246,37,68,105,219,8,19,190,105,0,98,252,160,117,121,191,23,64,68,56,168,181,26,110,151,82,13,253,70,112,204,6,235,125,65,92,195,81,228,1,198,3,254,105,89,126,116,54,137,224,133,216,196,187,143,117,97,191,178,79,12,170,120,229,249,178,146,108,216,53,42,238,153,21,180,186,145,69,40,48,78,160,94,225,176,230,251,160,188,135,75,229,31,183,168,206,81,243,171,162,8,252,97,76,119,242,18,155,216,173,180,175,141,142,130,196,157,13,220,93,121,133,156,49,45,210,111,169,176,37,228,153,17,140,73,218,6,151,156,52,143,104,168,97,172,217,24,194,88,242,85,12,171,224,218,190,48,66,114,181,115,83,156,122,154,1,202,205,193,153,85,165,194,75,212,160,103,50,179,35,63,183,154,158,245,48,47,113,88,143,119,5,215,184,237,110,130,237,238,2,232,139,143,182,173,102,69,190,127,176,231,136,230,119,241,72,186,24,89,112,70,148,255,11,212,232,21,13,50,215,21,170,229,70,30,158,226,181,7,131,182,30,99,249,114,247,143,133,137,217,32,1,195,53,37,232,231,152,118,10,72,183,242,19,101,175,169,77,61,38,67,250,249,95,32,56,48,64,34,105,185,251,92,152,239,8,68,101,23,103,92,159,108,42,39,15,130,173,228,228,145,13,145]},\"issuerCertificate\":{\"subject\":{\"C\":\"US\",\"O\":\"Internet Security Research Group\",\"CN\":\"ISRG Root X1\"},\"issuer\":{\"C\":\"US\",\"O\":\"Internet Security Research Group\",\"CN\":\"ISRG Root X1\"},\"ca\":true,\"modulus\":\"ADE82473F41437F39B9E2B57281C87BEDCB7DF38908C6E3CE657A078F775C2A2FEF56A6EF6004F28DBDE68866C4493B6B163FD14126BBF1FD2EA319B217ED1333CBA48F5DD79DFB3B8FF12F1219A4BC18A8671694A66666C8F7E3C70BFAD292206F3E4C0E680AEE24B8FB7997E94039FD347977C99482353E838AE4F0A6F832ED149578C8074B6DA2FD0388D7B0370211B75F2303CFA8FAEDDDA63ABEB164FC28E114B7ECF0BE8FFB5772EF4B27B4AE04C12250C708D0329A0E15324EC13D9EE19BF10B34A8C3F89A36151DEAC870794F46371EC2EE26F5B9881E1895C34796C76EF3B906279E6DBA49A2F26C5D010E10EDED9108E16FBB7F7A8F7C7E50207988F360895E7E237960D36759EFB0E72B11D9BBC03F94905D881DD05B42AD641E9AC0176950A0FD8DFD5BD121F352F28176CD298C1A80964776E4737BACEAC595E689D7F72D689C50641293E593EDD26F524C911A75AA34C401F46A199B5A73A516E863B9E7D72A712057859ED3E5178150B038F8DD02F05B23E7B4A1C4B730512FCC6EAE050137C439374B3CA74E78E1F0108D030D45B7136B407BAC130305C48B7823B98A67D608AA2A32982CCBABD83041BA2830341A1D605F11BC2B6F0A87C863B46A8482A88DC769A76BF1F6AA53D198FEB38F364DEC82B0D0A28FFF7DBE21542D422D0275DE179FE18E77088AD4EE6D98B3AC6DD27516EFFBC64F533434F\",\"bits\":4096,\"exponent\":\"0x10001\",\"pubkey\":{\"type\":\"Buffer\",\"data\":[48,130,2,34,48,13,6,9,42,134,72,134,247,13,1,1,1,5,0,3,130,2,15,0,48,130,2,10,2,130,2,1,0,173,232,36,115,244,20,55,243,155,158,43,87,40,28,135,190,220,183,223,56,144,140,110,60,230,87,160,120,247,117,194,162,254,245,106,110,246,0,79,40,219,222,104,134,108,68,147,182,177,99,253,20,18,107,191,31,210,234,49,155,33,126,209,51,60,186,72,245,221,121,223,179,184,255,18,241,33,154,75,193,138,134,113,105,74,102,102,108,143,126,60,112,191,173,41,34,6,243,228,192,230,128,174,226,75,143,183,153,126,148,3,159,211,71,151,124,153,72,35,83,232,56,174,79,10,111,131,46,209,73,87,140,128,116,182,218,47,208,56,141,123,3,112,33,27,117,242,48,60,250,143,174,221,218,99,171,235,22,79,194,142,17,75,126,207,11,232,255,181,119,46,244,178,123,74,224,76,18,37,12,112,141,3,41,160,225,83,36,236,19,217,238,25,191,16,179,74,140,63,137,163,97,81,222,172,135,7,148,244,99,113,236,46,226,111,91,152,129,225,137,92,52,121,108,118,239,59,144,98,121,230,219,164,154,47,38,197,208,16,225,14,222,217,16,142,22,251,183,247,168,247,199,229,2,7,152,143,54,8,149,231,226,55,150,13,54,117,158,251,14,114,177,29,155,188,3,249,73,5,216,129,221,5,180,42,214,65,233,172,1,118,149,10,15,216,223,213,189,18,31,53,47,40,23,108,210,152,193,168,9,100,119,110,71,55,186,206,172,89,94,104,157,127,114,214,137,197,6,65,41,62,89,62,221,38,245,36,201,17,167,90,163,76,64,31,70,161,153,181,167,58,81,110,134,59,158,125,114,167,18,5,120,89,237,62,81,120,21,11,3,143,141,208,47,5,178,62,123,74,28,75,115,5,18,252,198,234,224,80,19,124,67,147,116,179,202,116,231,142,31,1,8,208,48,212,91,113,54,180,7,186,193,48,48,92,72,183,130,59,152,166,125,96,138,162,163,41,130,204,186,189,131,4,27,162,131,3,65,161,214,5,241,27,194,182,240,168,124,134,59,70,168,72,42,136,220,118,154,118,191,31,106,165,61,25,143,235,56,243,100,222,200,43,13,10,40,255,247,219,226,21,66,212,34,208,39,93,225,121,254,24,231,112,136,173,78,230,217,139,58,198,221,39,81,110,255,188,100,245,51,67,79,2,3,1,0,1]},\"valid_from\":\"Jun  4 11:04:38 2015 GMT\",\"valid_to\":\"Jun  4 11:04:38 2035 GMT\",\"fingerprint\":\"CA:BD:2A:79:A1:07:6A:31:F2:1D:25:36:35:CB:03:9D:43:29:A5:E8\",\"fingerprint256\":\"96:BC:EC:06:26:49:76:F3:74:60:77:9A:CF:28:C5:A7:CF:E8:A3:C0:AA:E1:1A:8F:FC:EE:05:C0:BD:DF:08:C6\",\"fingerprint512\":\"3B:40:F2:7E:82:83:23:F5:B9:1F:89:09:88:3A:78:A2:1C:86:55:17:61:F2:7B:38:02:9F:AA:EC:14:AF:5B:7A:A9:6F:B9:F9:CC:93:EE:20:1B:5E:B1:D0:FE:F1:7B:29:07:47:E8:B8:39:D2:E4:9A:8F:36:C5:EB:F3:C7:C9:10\",\"serialNumber\":\"8210CFB0D240E3594463E0BB63828B00\",\"raw\":{\"type\":\"Buffer\",\"data\":[48,130,5,107,48,130,3,83,160,3,2,1,2,2,17,0,130,16,207,176,210,64,227,89,68,99,224,187,99,130,139,0,48,13,6,9,42,134,72,134,247,13,1,1,11,5,0,48,79,49,11,48,9,6,3,85,4,6,19,2,85,83,49,41,48,39,6,3,85,4,10,19,32,73,110,116,101,114,110,101,116,32,83,101,99,117,114,105,116,121,32,82,101,115,101,97,114,99,104,32,71,114,111,117,112,49,21,48,19,6,3,85,4,3,19,12,73,83,82,71,32,82,111,111,116,32,88,49,48,30,23,13,49,53,48,54,48,52,49,49,48,52,51,56,90,23,13,51,53,48,54,48,52,49,49,48,52,51,56,90,48,79,49,11,48,9,6,3,85,4,6,19,2,85,83,49,41,48,39,6,3,85,4,10,19,32,73,110,116,101,114,110,101,116,32,83,101,99,117,114,105,116,121,32,82,101,115,101,97,114,99,104,32,71,114,111,117,112,49,21,48,19,6,3,85,4,3,19,12,73,83,82,71,32,82,111,111,116,32,88,49,48,130,2,34,48,13,6,9,42,134,72,134,247,13,1,1,1,5,0,3,130,2,15,0,48,130,2,10,2,130,2,1,0,173,232,36,115,244,20,55,243,155,158,43,87,40,28,135,190,220,183,223,56,144,140,110,60,230,87,160,120,247,117,194,162,254,245,106,110,246,0,79,40,219,222,104,134,108,68,147,182,177,99,253,20,18,107,191,31,210,234,49,155,33,126,209,51,60,186,72,245,221,121,223,179,184,255,18,241,33,154,75,193,138,134,113,105,74,102,102,108,143,126,60,112,191,173,41,34,6,243,228,192,230,128,174,226,75,143,183,153,126,148,3,159,211,71,151,124,153,72,35,83,232,56,174,79,10,111,131,46,209,73,87,140,128,116,182,218,47,208,56,141,123,3,112,33,27,117,242,48,60,250,143,174,221,218,99,171,235,22,79,194,142,17,75,126,207,11,232,255,181,119,46,244,178,123,74,224,76,18,37,12,112,141,3,41,160,225,83,36,236,19,217,238,25,191,16,179,74,140,63,137,163,97,81,222,172,135,7,148,244,99,113,236,46,226,111,91,152,129,225,137,92,52,121,108,118,239,59,144,98,121,230,219,164,154,47,38,197,208,16,225,14,222,217,16,142,22,251,183,247,168,247,199,229,2,7,152,143,54,8,149,231,226,55,150,13,54,117,158,251,14,114,177,29,155,188,3,249,73,5,216,129,221,5,180,42,214,65,233,172,1,118,149,10,15,216,223,213,189,18,31,53,47,40,23,108,210,152,193,168,9,100,119,110,71,55,186,206,172,89,94,104,157,127,114,214,137,197,6,65,41,62,89,62,221,38,245,36,201,17,167,90,163,76,64,31,70,161,153,181,167,58,81,110,134,59,158,125,114,167,18,5,120,89,237,62,81,120,21,11,3,143,141,208,47,5,178,62,123,74,28,75,115,5,18,252,198,234,224,80,19,124,67,147,116,179,202,116,231,142,31,1,8,208,48,212,91,113,54,180,7,186,193,48,48,92,72,183,130,59,152,166,125,96,138,162,163,41,130,204,186,189,131,4,27,162,131,3,65,161,214,5,241,27,194,182,240,168,124,134,59,70,168,72,42,136,220,118,154,118,191,31,106,165,61,25,143,235,56,243,100,222,200,43,13,10,40,255,247,219,226,21,66,212,34,208,39,93,225,121,254,24,231,112,136,173,78,230,217,139,58,198,221,39,81,110,255,188,100,245,51,67,79,2,3,1,0,1,163,66,48,64,48,14,6,3,85,29,15,1,1,255,4,4,3,2,1,6,48,15,6,3,85,29,19,1,1,255,4,5,48,3,1,1,255,48,29,6,3,85,29,14,4,22,4,20,121,180,89,230,123,182,229,228,1,115,128,8,136,200,26,88,246,233,155,110,48,13,6,9,42,134,72,134,247,13,1,1,11,5,0,3,130,2,1,0,85,31,88,169,188,178,168,80,208,12,177,216,26,105,32,39,41,8,172,97,117,92,138,110,248,130,229,105,47,213,246,86,75,185,184,115,16,89,211,33,151,126,231,76,113,251,178,210,96,173,57,168,11,234,23,33,86,133,241,80,14,89,235,206,224,89,233,186,201,21,239,134,157,143,132,128,246,228,233,145,144,220,23,155,98,27,69,240,102,149,210,124,111,194,234,59,239,31,207,203,214,174,39,241,169,176,200,174,253,125,126,154,250,34,4,235,255,217,127,234,145,43,34,177,23,14,143,242,138,52,91,88,216,252,1,201,84,185,184,38,204,138,136,51,137,76,45,132,60,130,223,238,150,87,5,186,44,187,247,196,183,199,78,59,130,190,49,200,34,115,115,146,209,194,128,164,57,57,16,51,35,130,76,60,159,134,178,85,152,29,190,41,134,140,34,155,158,226,107,59,87,58,130,112,77,220,9,199,137,203,10,7,77,108,232,93,142,201,239,206,171,199,187,181,43,78,69,214,74,208,38,204,229,114,202,8,106,165,149,227,21,161,247,164,237,201,44,95,165,251,255,172,40,2,46,190,215,123,187,227,113,123,144,22,211,7,94,70,83,124,55,7,66,140,211,196,150,156,213,153,181,42,224,149,26,128,72,174,76,57,7,206,204,71,164,82,149,43,186,184,251,173,210,51,83,125,229,29,77,109,213,161,177,199,66,111,230,64,39,53,92,163,40,183,7,141,231,141,51,144,231,35,159,251,80,156,121,108,70,213,180,21,179,150,110,126,155,12,150,58,184,82,45,63,214,91,225,251,8,194,132,254,36,168,163,137,218,172,106,225,24,42,177,168,67,97,91,211,31,220,59,141,118,242,45,232,141,117,223,23,51,108,61,83,251,123,203,65,95,255,220,162,208,97,56,225,150,184,172,93,139,55,215,117,213,51,192,153,17,174,157,65,193,114,117,132,190,2,65,66,95,103,36,72,148,209,155,39,190,7,63,185,184,79,129,116,81,225,122,183,237,157,35,226,190,224,213,40,4,19,60,49,3,158,221,122,108,143,198,7,24,198,127,222,71,142,63,40,158,4,6,207,165,84,52,119,189,236,137,155,233,23,67,223,91,219,95,254,142,30,87,162,205,64,157,126,98,34,218,222,24,39]},\"issuerCertificate\":null,\"validTo\":\"2035-06-04T11:04:38.000Z\",\"daysRemaining\":3130,\"certType\":\"root CA\"},\"validTo\":\"2032-09-02T23:59:59.000Z\",\"daysRemaining\":2126,\"certType\":\"intermediate CA\"},\"validTo\":\"2032-09-02T23:59:59.000Z\",\"daysRemaining\":2126,\"certType\":\"intermediate CA\"},\"validTo\":\"2028-09-02T23:59:59.000Z\",\"daysRemaining\":665,\"certType\":\"intermediate CA\"},\"validTo\":\"2027-02-03T19:21:39.000Z\",\"validFor\":[\"eu.httpbin.org\",\"httpbin.hbin02.postmanlabs.com\",\"httpbin.org\",\"www.httpbin.org\"],\"daysRemaining\":87,\"certType\":\"server\"},\"hostnameMatchMonitorUrl\":true}');
/*!40000 ALTER TABLE `monitor_tls_info` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification`
--

DROP TABLE IF EXISTS `notification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `user_id` int(10) unsigned DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `config` longtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification`
--

LOCK TABLES `notification` WRITE;
/*!40000 ALTER TABLE `notification` DISABLE KEYS */;
INSERT INTO `notification` VALUES
(1,'Ops e-mail',1,1,1,'{\"name\":\"Ops e-mail\",\"type\":\"smtp\",\"isDefault\":true,\"applyExisting\":false,\"smtpHost\":\"smtp.example.com\",\"smtpPort\":587,\"smtpSecure\":false,\"smtpUsername\":\"u\",\"smtpPassword\":\"p\",\"smtpFrom\":\"kuma@example.com\",\"smtpTo\":\"ops@example.com\",\"smtpCC\":\"\",\"smtpBCC\":\"\"}'),
(2,'Webhook',1,1,0,'{\"name\":\"Webhook\",\"type\":\"webhook\",\"isDefault\":false,\"applyExisting\":false,\"webhookURL\":\"https://hooks.example.com/kuma\",\"webhookContentType\":\"json\",\"webhookAdditionalHeaders\":\"{\\\"X-Token\\\":\\\"abc\\\"}\"}');
/*!40000 ALTER TABLE `notification` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_sent_history`
--

DROP TABLE IF EXISTS `notification_sent_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_sent_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL,
  `monitor_id` int(10) unsigned NOT NULL,
  `days` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_sent_history_type_monitor_id_days_unique` (`type`,`monitor_id`,`days`),
  KEY `good_index` (`type`,`monitor_id`,`days`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_sent_history`
--

LOCK TABLES `notification_sent_history` WRITE;
/*!40000 ALTER TABLE `notification_sent_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_sent_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `proxy`
--

DROP TABLE IF EXISTS `proxy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proxy` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `protocol` varchar(10) NOT NULL,
  `host` varchar(255) NOT NULL,
  `port` int(11) DEFAULT NULL,
  `auth` tinyint(1) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `default` tinyint(1) NOT NULL DEFAULT 0,
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `proxy_user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `proxy`
--

LOCK TABLES `proxy` WRITE;
/*!40000 ALTER TABLE `proxy` DISABLE KEYS */;
INSERT INTO `proxy` VALUES
(1,1,'http','proxy.example.com',3128,1,'pu','pp',1,0,'2026-11-07 22:52:24');
/*!40000 ALTER TABLE `proxy` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `remote_browser`
--

DROP TABLE IF EXISTS `remote_browser`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `remote_browser` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `remote_browser`
--

LOCK TABLES `remote_browser` WRITE;
/*!40000 ALTER TABLE `remote_browser` DISABLE KEYS */;
/*!40000 ALTER TABLE `remote_browser` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `setting`
--

DROP TABLE IF EXISTS `setting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `setting` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(200) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key_unique` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `setting`
--

LOCK TABLES `setting` WRITE;
/*!40000 ALTER TABLE `setting` DISABLE KEYS */;
INSERT INTO `setting` VALUES
(1,'migrateAggregateTableState','\"migrated\"',NULL),
(2,'jwtSecret','$2a$10$4XQxQ4pFTP3rAr3l5OWQ9.wqVzlzLD1xrGczh7RCEYuJTCO.wfM.S',NULL),
(3,'tlsExpiryNotifyDays','[7,14,21]','general'),
(4,'rdapDnsData','{\"description\":\"RDAP bootstrap file for Domain Name System registrations\",\"publication\":\"2026-10-28T22:00:04Z\",\"services\":[[[\"kg\"],[\"http://rdap.cctld.kg/\"]],[[\"mg\"],[\"http://rdap.nic.mg/\"]],[[\"xn--kpry57d\"],[\"https://ccrdap.twnic.tw/taiwan/\"]],[[\"tw\"],[\"https://ccrdap.twnic.tw/tw/\"]],[[\"na\"],[\"https://keetmans.omadhina.co.na/\"]],[[\"samsung\",\"xn--cg4bki\"],[\"https://nic.samsung/rdap/\"]],[[\"ads\",\"android\",\"app\",\"boo\",\"cal\",\"channel\",\"chrome\",\"dad\",\"day\",\"dclk\",\"dev\",\"docs\",\"drive\",\"eat\",\"esq\",\"fly\",\"foo\",\"gbiz\",\"gle\",\"gmail\",\"goog\",\"google\",\"guge\",\"hangout\",\"here\",\"how\",\"ing\",\"map\",\"meet\",\"meme\",\"mov\",\"new\",\"nexus\",\"page\",\"phd\",\"play\",\"prod\",\"prof\",\"rsvp\",\"search\",\"soy\",\"xn--flw351e\",\"xn--q9jyb4c\",\"xn--qcka1pmc\",\"youtube\",\"zip\"],[\"https://pubapi.registry.google/rdap/\"]],[[\"merck\"],[\"https://rdap-merck.dns.business/rdap/\"]],[[\"alibaba\"],[\"https://rdap.aliregistry.cn/rdap/\"]],[[\"bh\",\"xn--mgbcpq6gpa1a\"],[\"https://rdap.bhregistry.bh/rdap/\"]],[[\"blog\"],[\"https://rdap.blog.fury.ca/rdap/\"]],[[\"ca\"],[\"https://rdap.ca.fury.ca/rdap/\"]],[[\"au\"],[\"https://rdap.cctld.au/rdap/\"]],[[\"uz\"],[\"https://rdap.cctld.uz/\"]],[[\"allfinanz\"],[\"https://rdap.centralnic.com/allfinanz/\"]],[[\"art\"],[\"https://rdap.centralnic.com/art/\"]],[[\"audio\"],[\"https://rdap.centralnic.com/audio/\"]],[[\"auto\"],[\"https://rdap.centralnic.com/auto/\"]],[[\"autos\"],[\"https://rdap.centralnic.com/autos/\"]],[[\"baby\"],[\"https://rdap.centralnic.com/baby/\"]],[[\"beauty\"],[\"https://rdap.centralnic.com/beauty/\"]],[[\"best\"],[\"https://rdap.centralnic.com/best/\"]],[[\"bmw\"],[\"https://rdap.centralnic.com/bmw/\"]],[[\"boats\"],[\"https://rdap.centralnic.com/boats/\"]],[[\"bond\"],[\"https://rdap.centralnic.com/bond/\"]],[[\"box\"],[\"https://rdap.centralnic.com/box/\"]],[[\"build\"],[\"https://rdap.centralnic.com/build/\"]],[[\"cam\"],[\"https://rdap.centralnic.com/cam/\"]],[[\"car\"],[\"https://rdap.centralnic.com/car/\"]],[[\"cars\"],[\"https://rdap.centralnic.com/cars/\"]],[[\"case\"],[\"https://rdap.centralnic.com/case/\"]],[[\"ceo\"],[\"https://rdap.centralnic.com/ceo/\"]],[[\"cfd\"],[\"https://rdap.centralnic.com/cfd/\"]],[[\"christmas\"],[\"https://rdap.centralnic.com/christmas/\"]],[[\"college\"],[\"https://rdap.centralnic.com/college/\"]],[[\"cyou\"],[\"https://rdap.centralnic.com/cyou/\"]],[[\"dealer\"],[\"https://rdap.centralnic.com/dealer/\"]],[[\"deloitte\"],[\"https://rdap.centralnic.com/deloitte/\"]],[[\"dhl\"],[\"https://rdap.centralnic.com/dhl/\"]],[[\"diet\"],[\"https://rdap.centralnic.com/diet/\"]],[[\"dvag\"],[\"https://rdap.centralnic.com/dvag/\"]],[[\"fans\"],[\"https://rdap.centralnic.com/fans/\"]],[[\"flowers\"],[\"https://rdap.centralnic.com/flowers/\"]],[[\"fm\"],[\"https://rdap.centralnic.com/fm/\"]],[[\"fo\"],[\"https://rdap.centralnic.com/fo/\"]],[[\"fresenius\"],[\"https://rdap.centralnic.com/fresenius/\"]],[[\"frl\"],[\"https://rdap.centralnic.com/frl/\"]],[[\"game\"],[\"https://rdap.centralnic.com/game/\"]],[[\"gd\"],[\"https://rdap.centralnic.com/gd/\"]],[[\"gent\"],[\"https://rdap.centralnic.com/gent/\"]],[[\"guitars\"],[\"https://rdap.centralnic.com/guitars/\"]],[[\"hair\"],[\"https://rdap.centralnic.com/hair/\"]],[[\"help\"],[\"https://rdap.centralnic.com/help/\"]],[[\"homes\"],[\"https://rdap.centralnic.com/homes/\"]],[[\"hosting\"],[\"https://rdap.centralnic.com/hosting/\"]],[[\"icu\"],[\"https://rdap.centralnic.com/icu/\"]],[[\"inc\"],[\"https://rdap.centralnic.com/inc/\"]],[[\"kpn\"],[\"https://rdap.centralnic.com/kpn/\"]],[[\"kred\"],[\"https://rdap.centralnic.com/kred/\"]],[[\"lat\"],[\"https://rdap.centralnic.com/lat/\"]],[[\"lidl\"],[\"https://rdap.centralnic.com/lidl/\"]],[[\"llp\"],[\"https://rdap.centralnic.com/llp/\"]],[[\"lol\"],[\"https://rdap.centralnic.com/lol/\"]],[[\"london\"],[\"https://rdap.centralnic.com/london/\"]],[[\"lpl\"],[\"https://rdap.centralnic.com/lpl/\"]],[[\"lplfinancial\"],[\"https://rdap.centralnic.com/lplfinancial/\"]],[[\"luxury\"],[\"https://rdap.centralnic.com/luxury/\"]],[[\"makeup\"],[\"https://rdap.centralnic.com/makeup/\"]],[[\"mini\"],[\"https://rdap.centralnic.com/mini/\"]],[[\"mom\"],[\"https://rdap.centralnic.com/mom/\"]],[[\"monster\"],[\"https://rdap.centralnic.com/monster/\"]],[[\"motorcycles\"],[\"https://rdap.centralnic.com/motorcycles/\"]],[[\"nokia\"],[\"https://rdap.centralnic.com/nokia/\"]],[[\"ooo\"],[\"https://rdap.centralnic.com/ooo/\"]],[[\"pics\"],[\"https://rdap.centralnic.com/pics/\"]],[[\"pohl\"],[\"https://rdap.centralnic.com/pohl/\"]],[[\"protection\"],[\"https://rdap.centralnic.com/protection/\"]],[[\"qpon\"],[\"https://rdap.centralnic.com/qpon/\"]],[[\"quest\"],[\"https://rdap.centralnic.com/quest/\"]],[[\"radio\"],[\"https://rdap.centralnic.com/radio/\"]],[[\"reit\"],[\"https://rdap.centralnic.com/reit/\"]],[[\"rent\"],[\"https://rdap.centralnic.com/rent/\"]],[[\"ruhr\"],[\"https://rdap.centralnic.com/ruhr/\"]],[[\"saarland\"],[\"https://rdap.centralnic.com/saarland/\"]],[[\"sbs\"],[\"https://rdap.centralnic.com/sbs/\"]],[[\"schwarz\"],[\"https://rdap.centralnic.com/schwarz/\"]],[[\"security\"],[\"https://rdap.centralnic.com/security/\"]],[[\"sfr\"],[\"https://rdap.centralnic.com/sfr/\"]],[[\"skin\"],[\"https://rdap.centralnic.com/skin/\"]],[[\"smart\"],[\"https://rdap.centralnic.com/smart/\"]],[[\"stc\"],[\"https://rdap.centralnic.com/stc/\"]],[[\"stcgroup\"],[\"https://rdap.centralnic.com/stcgroup/\"]],[[\"storage\"],[\"https://rdap.centralnic.com/storage/\"]],[[\"theatre\"],[\"https://rdap.centralnic.com/theatre/\"]],[[\"tickets\"],[\"https://rdap.centralnic.com/tickets/\"]],[[\"tui\"],[\"https://rdap.centralnic.com/tui/\"]],[[\"vg\"],[\"https://rdap.centralnic.com/vg/\"]],[[\"viva\"],[\"https://rdap.centralnic.com/viva/\"]],[[\"wme\"],[\"https://rdap.centralnic.com/wme/\"]],[[\"xn--4gbrim\"],[\"https://rdap.centralnic.com/xn--4gbrim/\"]],[[\"xn--vermgensberater-ctb\"],[\"https://rdap.centralnic.com/xn--vermgensberater-ctb/\"]],[[\"xn--vermgensberatung-pwb\"],[\"https://rdap.centralnic.com/xn--vermgensberatung-pwb/\"]],[[\"xyz\"],[\"https://rdap.centralnic.com/xyz/\"]],[[\"yachts\"],[\"https://rdap.centralnic.com/yachts/\"]],[[\"zuerich\"],[\"https://rdap.centralnic.com/zuerich/\"]],[[\"jnj\"],[\"https://rdap.centralnicregistry.com/jnj/\"]],[[\"crown\"],[\"https://rdap.crown.fury.ca/rdap/\"]],[[\"pl\"],[\"https://rdap.dns.pl/\"]],[[\"eco\"],[\"https://rdap.eco.fury.ca/rdap/\"]],[[\"fi\"],[\"https://rdap.fi/rdap/rdap/\"]],[[\"moscow\",\"xn--80adxhks\"],[\"https://rdap.flexireg.net/\"]],[[\"bridgestone\",\"brother\",\"canon\",\"datsun\",\"dnp\",\"epson\",\"firestone\",\"fujitsu\",\"ggee\",\"gmo\",\"goldpoint\",\"hisamitsu\",\"hitachi\",\"honda\",\"hyundai\",\"infiniti\",\"jcb\",\"kddi\",\"kia\",\"komatsu\",\"kyoto\",\"lexus\",\"lotte\",\"mitsubishi\",\"nagoya\",\"nec\",\"nhk\",\"nico\",\"nissan\",\"okinawa\",\"otsuka\",\"panasonic\",\"playstation\",\"ricoh\",\"ryukyu\",\"sharp\",\"shop\",\"softbank\",\"sony\",\"suzuki\",\"tokyo\",\"toray\",\"toshiba\",\"toyota\",\"yodobashi\",\"yokohama\"],[\"https://rdap.gmoregistry.net/rdap/\"]],[[\"bom\",\"final\",\"globo\",\"rio\",\"uol\"],[\"https://rdap.gtlds.nic.br/\"]],[[\"ua\"],[\"https://rdap.hostmaster.ua/\"]],[[\"int\"],[\"https://rdap.iana.org/\"]],[[\"abb\",\"abbott\",\"abc\",\"academy\",\"accenture\",\"accountants\",\"actor\",\"aeg\",\"aero\",\"agakhan\",\"agency\",\"ai\",\"airbus\",\"airforce\",\"akdn\",\"alipay\",\"allstate\",\"aol\",\"apartments\",\"archi\",\"army\",\"arte\",\"asda\",\"asia\",\"associates\",\"attorney\",\"auction\",\"audi\",\"band\",\"barclaycard\",\"barclays\",\"barefoot\",\"bargains\",\"bbt\",\"bcg\",\"beats\",\"bestbuy\",\"bet\",\"bike\",\"bingo\",\"bio\",\"black\",\"bloomberg\",\"blue\",\"bm\",\"bms\",\"bnpparibas\",\"boehringer\",\"bofa\",\"bosch\",\"boutique\",\"bradesco\",\"broker\",\"builders\",\"business\",\"cab\",\"cafe\",\"camera\",\"camp\",\"capital\",\"cards\",\"care\",\"careers\",\"cash\",\"casino\",\"catering\",\"center\",\"cern\",\"cfa\",\"chanel\",\"chat\",\"cheap\",\"church\",\"cipriani\",\"circle\",\"citadel\",\"city\",\"claims\",\"cleaning\",\"clinic\",\"clinique\",\"clothing\",\"clubmed\",\"coach\",\"codes\",\"coffee\",\"community\",\"company\",\"computer\",\"condos\",\"construction\",\"consulting\",\"contact\",\"contractors\",\"cool\",\"coupon\",\"coupons\",\"credit\",\"creditcard\",\"crs\",\"cruise\",\"cruises\",\"dance\",\"dating\",\"deals\",\"degree\",\"delivery\",\"delta\",\"democrat\",\"dental\",\"dentist\",\"diamonds\",\"digital\",\"direct\",\"directory\",\"discount\",\"discover\",\"doctor\",\"dog\",\"domains\",\"edeka\",\"education\",\"email\",\"emerck\",\"energy\",\"engineer\",\"engineering\",\"enterprises\",\"equipment\",\"ericsson\",\"estate\",\"events\",\"exchange\",\"expert\",\"exposed\",\"express\",\"extraspace\",\"fage\",\"fail\",\"family\",\"fan\",\"farm\",\"fedex\",\"ferrari\",\"fidelity\",\"fido\",\"finance\",\"financial\",\"fish\",\"fitness\",\"flights\",\"florist\",\"football\",\"forex\",\"forsale\",\"frogans\",\"fund\",\"furniture\",\"futbol\",\"fyi\",\"gallery\",\"gallo\",\"gallup\",\"games\",\"genting\",\"gifts\",\"glass\",\"global\",\"gmbh\",\"gold\",\"golf\",\"goodyear\",\"got\",\"graphics\",\"gratis\",\"green\",\"gripe\",\"group\",\"guide\",\"guru\",\"haus\",\"hdfc\",\"hdfcbank\",\"healthcare\",\"hermes\",\"hkt\",\"hockey\",\"holdings\",\"holiday\",\"homedepot\",\"hospital\",\"house\",\"hughes\",\"ice\",\"imamat\",\"immo\",\"immobilien\",\"industries\",\"info\",\"institute\",\"insure\",\"international\",\"investments\",\"irish\",\"ismaili\",\"ist\",\"istanbul\",\"itv\",\"jaguar\",\"java\",\"jeep\",\"jetzt\",\"jewelry\",\"jio\",\"jll\",\"jot\",\"juegos\",\"kaufen\",\"kerryhotels\",\"kerryproperties\",\"kids\",\"kim\",\"kitchen\",\"kosher\",\"kuokgroup\",\"lamborghini\",\"lamer\",\"land\",\"landrover\",\"lasalle\",\"lawyer\",\"lds\",\"lease\",\"lefrak\",\"legal\",\"lego\",\"lgbt\",\"life\",\"lighting\",\"limited\",\"limo\",\"live\",\"llc\",\"loans\",\"lotto\",\"ltd\",\"ltda\",\"lundbeck\",\"maif\",\"maison\",\"management\",\"market\",\"marketing\",\"markets\",\"marriott\",\"mba\",\"mckinsey\",\"media\",\"memorial\",\"mit\",\"mobi\",\"moda\",\"money\",\"mormon\",\"mortgage\",\"movie\",\"mu\",\"nab\",\"navy\",\"network\",\"news\",\"next\",\"nextdirect\",\"nikon\",\"ninja\",\"nissay\",\"nowtv\",\"nra\",\"obi\",\"onl\",\"oracle\",\"orange\",\"organic\",\"origins\",\"partners\",\"parts\",\"pccw\",\"pet\",\"photography\",\"photos\",\"pictet\",\"pictures\",\"pink\",\"pizza\",\"place\",\"plumbing\",\"plus\",\"pnc\",\"poker\",\"post\",\"pro\",\"productions\",\"progressive\",\"promo\",\"properties\",\"pub\",\"pwc\",\"recipes\",\"red\",\"redumbrella\",\"rehab\",\"reise\",\"reisen\",\"reliance\",\"rentals\",\"repair\",\"report\",\"republican\",\"restaurant\",\"reviews\",\"rexroth\",\"rich\",\"richardli\",\"ril\",\"rip\",\"rocks\",\"rogers\",\"run\",\"rwe\",\"safety\",\"sale\",\"salon\",\"sanofi\",\"sarl\",\"saxo\",\"sbi\",\"sc\",\"scholarships\",\"school\",\"schule\",\"sener\",\"services\",\"sew\",\"shangrila\",\"shiksha\",\"shoes\",\"shopping\",\"show\",\"singles\",\"ski\",\"soccer\",\"social\",\"software\",\"solar\",\"solutions\",\"song\",\"spa\",\"srl\",\"stada\",\"star\",\"statebank\",\"stockholm\",\"studio\",\"style\",\"supplies\",\"supply\",\"support\",\"surgery\",\"systems\",\"taobao\",\"tatamotors\",\"tax\",\"taxi\",\"team\",\"technology\",\"temasek\",\"tennis\",\"thd\",\"theater\",\"tiaa\",\"tienda\",\"tips\",\"tires\",\"tmall\",\"today\",\"tools\",\"tours\",\"town\",\"toys\",\"trading\",\"training\",\"travel\",\"travelers\",\"travelersinsurance\",\"trv\",\"tvs\",\"ubank\",\"ubs\",\"university\",\"ups\",\"vacations\",\"vanguard\",\"vegas\",\"ventures\",\"vet\",\"viajes\",\"video\",\"vig\",\"viking\",\"villas\",\"vin\",\"visa\",\"vision\",\"volvo\",\"vote\",\"voto\",\"voyage\",\"watch\",\"watches\",\"weber\",\"weir\",\"wine\",\"works\",\"world\",\"wtf\",\"xin\",\"xn--1ck2e1b\",\"xn--5su34j936bgsg\",\"xn--5tzm5g\",\"xn--6frz82g\",\"xn--b4w605ferd\",\"xn--bck1b9a5dre4c\",\"xn--cck2b3b\",\"xn--czrs0t\",\"xn--eckvdtc9d\",\"xn--fct429k\",\"xn--fjq720a\",\"xn--fzys8d69uvgm\",\"xn--gckr3f0f\",\"xn--gk3at1e\",\"xn--jvr189m\",\"xn--rovu88b\",\"xn--unup4y\",\"xn--vhquv\",\"xn--w4r85el8fhu5dnra\",\"xn--w4rs40l\",\"yahoo\",\"zara\",\"zero\",\"zone\"],[\"https://rdap.identitydigital.services/rdap/\"]],[[\"is\"],[\"https://rdap.isnic.is/rdap/\"]],[[\"ke\"],[\"https://rdap.kenic.or.ke/\"]],[[\"kiwi\"],[\"https://rdap.kiwi.fury.ca/rdap/\"]],[[\"lb\"],[\"https://rdap.lbdr.org.lb/\"]],[[\"mls\"],[\"https://rdap.mls.fury.ca/rdap/\"]],[[\"blockbuster\",\"data\",\"dish\",\"dot\",\"dtv\",\"dvr\",\"latino\",\"mobile\",\"ollo\",\"ott\",\"phone\",\"sling\"],[\"https://rdap.mobile-registry.com/rdap/\"]],[[\"sina\",\"weibo\",\"xn--55qw42g\",\"xn--55qx5d\",\"xn--9krt00a\",\"xn--io0a7i\",\"xn--zfr164b\"],[\"https://rdap.ngtld.cn/\"]],[[\"aaa\"],[\"https://rdap.nic.aaa/\"]],[[\"aarp\"],[\"https://rdap.nic.aarp/\"]],[[\"able\"],[\"https://rdap.nic.able/\"]],[[\"abogado\"],[\"https://rdap.nic.abogado/\"]],[[\"abudhabi\"],[\"https://rdap.nic.abudhabi/\"]],[[\"accountant\"],[\"https://rdap.nic.accountant/\"]],[[\"aco\"],[\"https://rdap.nic.aco/\"]],[[\"ad\"],[\"https://rdap.nic.ad/\"]],[[\"adult\"],[\"https://rdap.nic.adult/\"]],[[\"aetna\"],[\"https://rdap.nic.aetna/\"]],[[\"afl\"],[\"https://rdap.nic.afl/\"]],[[\"africa\"],[\"https://rdap.nic.africa/rdap/\"]],[[\"aig\"],[\"https://rdap.nic.aig/\"]],[[\"airtel\"],[\"https://rdap.nic.airtel/\"]],[[\"ally\"],[\"https://rdap.nic.ally/\"]],[[\"alsace\"],[\"https://rdap.nic.alsace/\"]],[[\"alstom\"],[\"https://rdap.nic.alstom/\"]],[[\"americanexpress\"],[\"https://rdap.nic.americanexpress/\"]],[[\"americanfamily\"],[\"https://rdap.nic.americanfamily/\"]],[[\"amex\"],[\"https://rdap.nic.amex/\"]],[[\"amfam\"],[\"https://rdap.nic.amfam/\"]],[[\"amica\"],[\"https://rdap.nic.amica/\"]],[[\"amsterdam\"],[\"https://rdap.nic.amsterdam/\"]],[[\"analytics\"],[\"https://rdap.nic.analytics/\"]],[[\"anz\"],[\"https://rdap.nic.anz/\"]],[[\"apple\"],[\"https://rdap.nic.apple/\"]],[[\"aquarelle\"],[\"https://rdap.nic.aquarelle/\"]],[[\"ar\"],[\"https://rdap.nic.ar/\"]],[[\"arab\"],[\"https://rdap.nic.arab/\"]],[[\"aramco\"],[\"https://rdap.nic.aramco/\"]],[[\"as\"],[\"https://rdap.nic.as/\"]],[[\"athleta\"],[\"https://rdap.nic.athleta/\"]],[[\"auspost\"],[\"https://rdap.nic.auspost/\"]],[[\"axa\"],[\"https://rdap.nic.axa/\"]],[[\"banamex\"],[\"https://rdap.nic.banamex/\"]],[[\"bank\"],[\"https://rdap.nic.bank/\"]],[[\"barcelona\"],[\"https://rdap.nic.barcelona/\"]],[[\"baseball\"],[\"https://rdap.nic.baseball/\"]],[[\"basketball\"],[\"https://rdap.nic.basketball/\"]],[[\"bauhaus\"],[\"https://rdap.nic.bauhaus/\"]],[[\"bayern\"],[\"https://rdap.nic.bayern/\"]],[[\"bcn\"],[\"https://rdap.nic.bcn/\"]],[[\"beer\"],[\"https://rdap.nic.beer/\"]],[[\"berlin\"],[\"https://rdap.nic.berlin/v1/\"]],[[\"bharti\"],[\"https://rdap.nic.bharti/\"]],[[\"bible\"],[\"https://rdap.nic.bible/\"]],[[\"bid\"],[\"https://rdap.nic.bid/\"]],[[\"biz\"],[\"https://rdap.nic.biz/\"]],[[\"blackfriday\"],[\"https://rdap.nic.blackfriday/\"]],[[\"booking\"],[\"https://rdap.nic.booking/\"]],[[\"bostik\"],[\"https://rdap.nic.bostik/\"]],[[\"boston\"],[\"https://rdap.nic.boston/\"]],[[\"brussels\"],[\"https://rdap.nic.brussels/\"]],[[\"buzz\"],[\"https://rdap.nic.buzz/\"]],[[\"bzh\"],[\"https://rdap.nic.bzh/\"]],[[\"calvinklein\"],[\"https://rdap.nic.calvinklein/\"]],[[\"capetown\"],[\"https://rdap.nic.capetown/rdap/\"]],[[\"capitalone\"],[\"https://rdap.nic.capitalone/\"]],[[\"caravan\"],[\"https://rdap.nic.caravan/\"]],[[\"casa\"],[\"https://rdap.nic.casa/\"]],[[\"cat\"],[\"https://rdap.nic.cat/\"]],[[\"catholic\"],[\"https://rdap.nic.catholic/\"]],[[\"cba\"],[\"https://rdap.nic.cba/\"]],[[\"cbn\"],[\"https://rdap.nic.cbn/\"]],[[\"cbre\"],[\"https://rdap.nic.cbre/\"]],[[\"chase\"],[\"https://rdap.nic.chase/\"]],[[\"chintai\"],[\"https://rdap.nic.chintai/\"]],[[\"cisco\"],[\"https://rdap.nic.cisco/\"]],[[\"citi\"],[\"https://rdap.nic.citi/\"]],[[\"club\"],[\"https://rdap.nic.club/\"]],[[\"cm\"],[\"https://rdap.nic.cm/\"]],[[\"commbank\"],[\"https://rdap.nic.commbank/\"]],[[\"compare\"],[\"https://rdap.nic.compare/\"]],[[\"cooking\"],[\"https://rdap.nic.cooking/\"]],[[\"corsica\"],[\"https://rdap.nic.corsica/\"]],[[\"courses\"],[\"https://rdap.nic.courses/\"]],[[\"cpa\"],[\"https://rdap.nic.cpa/\"]],[[\"cr\"],[\"https://rdap.nic.cr/\"]],[[\"cricket\"],[\"https://rdap.nic.cricket/\"]],[[\"cuisinella\"],[\"https://rdap.nic.cuisinella/\"]],[[\"cv\"],[\"https://rdap.nic.cv/\"]],[[\"cx\"],[\"https://rdap.nic.cx/\"]],[[\"cz\"],[\"https://rdap.nic.cz/\"]],[[\"date\"],[\"https://rdap.nic.date/\"]],[[\"dds\"],[\"https://rdap.nic.dds/\"]],[[\"dell\"],[\"https://rdap.nic.dell/\"]],[[\"design\"],[\"https://rdap.nic.design/\"]],[[\"download\"],[\"https://rdap.nic.download/\"]],[[\"dubai\"],[\"https://rdap.nic.dubai/\"]],[[\"dupont\"],[\"https://rdap.nic.dupont/\"]],[[\"durban\"],[\"https://rdap.nic.durban/rdap/\"]],[[\"earth\"],[\"https://rdap.nic.earth/\"]],[[\"erni\"],[\"https://rdap.nic.erni/\"]],[[\"eurovision\"],[\"https://rdap.nic.eurovision/\"]],[[\"eus\"],[\"https://rdap.nic.eus/\"]],[[\"faith\"],[\"https://rdap.nic.faith/\"]],[[\"farmers\"],[\"https://rdap.nic.farmers/\"]],[[\"fashion\"],[\"https://rdap.nic.fashion/\"]],[[\"ferrero\"],[\"https://rdap.nic.ferrero/\"]],[[\"film\"],[\"https://rdap.nic.film/\"]],[[\"firmdale\"],[\"https://rdap.nic.firmdale/\"]],[[\"fishing\"],[\"https://rdap.nic.fishing/\"]],[[\"fit\"],[\"https://rdap.nic.fit/\"]],[[\"flickr\"],[\"https://rdap.nic.flickr/\"]],[[\"flir\"],[\"https://rdap.nic.flir/\"]],[[\"ford\"],[\"https://rdap.nic.ford/\"]],[[\"fox\"],[\"https://rdap.nic.fox/\"]],[[\"fr\"],[\"https://rdap.nic.fr/\"]],[[\"frontier\"],[\"https://rdap.nic.frontier/\"]],[[\"ftr\"],[\"https://rdap.nic.ftr/\"]],[[\"gal\"],[\"https://rdap.nic.gal/\"]],[[\"gap\"],[\"https://rdap.nic.gap/\"]],[[\"garden\"],[\"https://rdap.nic.garden/\"]],[[\"gay\"],[\"https://rdap.nic.gay/\"]],[[\"gdn\"],[\"https://rdap.nic.gdn/\"]],[[\"gea\"],[\"https://rdap.nic.gea/\"]],[[\"george\"],[\"https://rdap.nic.george/\"]],[[\"gmx\"],[\"https://rdap.nic.gmx/\"]],[[\"godaddy\"],[\"https://rdap.nic.godaddy/\"]],[[\"gov\"],[\"https://rdap.nic.gov/rdap/\"]],[[\"grainger\"],[\"https://rdap.nic.grainger/\"]],[[\"grocery\"],[\"https://rdap.nic.grocery/\"]],[[\"gs\"],[\"https://rdap.nic.gs/\"]],[[\"hamburg\"],[\"https://rdap.nic.hamburg/v1/\"]],[[\"hbo\"],[\"https://rdap.nic.hbo/\"]],[[\"health\"],[\"https://rdap.nic.health/\"]],[[\"hn\"],[\"https://rdap.nic.hn/\"]],[[\"homegoods\"],[\"https://rdap.nic.homegoods/\"]],[[\"homesense\"],[\"https://rdap.nic.homesense/\"]],[[\"horse\"],[\"https://rdap.nic.horse/\"]],[[\"hotels\"],[\"https://rdap.nic.hotels/\"]],[[\"hsbc\"],[\"https://rdap.nic.hsbc/\"]],[[\"ht\"],[\"https://rdap.nic.ht/\"]],[[\"hyatt\"],[\"https://rdap.nic.hyatt/\"]],[[\"ibm\"],[\"https://rdap.nic.ibm/\"]],[[\"ifm\"],[\"https://rdap.nic.ifm/\"]],[[\"ikano\"],[\"https://rdap.nic.ikano/v1/\"]],[[\"ink\"],[\"https://rdap.nic.ink/\"]],[[\"insurance\"],[\"https://rdap.nic.insurance/\"]],[[\"intuit\"],[\"https://rdap.nic.intuit/\"]],[[\"ipiranga\"],[\"https://rdap.nic.ipiranga/\"]],[[\"itau\"],[\"https://rdap.nic.itau/\"]],[[\"jmp\"],[\"https://rdap.nic.jmp/\"]],[[\"joburg\"],[\"https://rdap.nic.joburg/rdap/\"]],[[\"jpmorgan\"],[\"https://rdap.nic.jpmorgan/\"]],[[\"jprs\"],[\"https://rdap.nic.jprs/rdap/\"]],[[\"kpmg\"],[\"https://rdap.nic.kpmg/\"]],[[\"krd\"],[\"https://rdap.nic.krd/\"]],[[\"lacaixa\"],[\"https://rdap.nic.lacaixa/\"]],[[\"lanxess\"],[\"https://rdap.nic.lanxess/\"]],[[\"latrobe\"],[\"https://rdap.nic.latrobe/\"]],[[\"law\"],[\"https://rdap.nic.law/\"]],[[\"leclerc\"],[\"https://rdap.nic.leclerc/\"]],[[\"lifeinsurance\"],[\"https://rdap.nic.lifeinsurance/\"]],[[\"lilly\"],[\"https://rdap.nic.lilly/\"]],[[\"lincoln\"],[\"https://rdap.nic.lincoln/\"]],[[\"loan\"],[\"https://rdap.nic.loan/\"]],[[\"locker\"],[\"https://rdap.nic.locker/rdap/\"]],[[\"luxe\"],[\"https://rdap.nic.luxe/\"]],[[\"ly\"],[\"https://rdap.nic.ly/\"]],[[\"madrid\"],[\"https://rdap.nic.madrid/\"]],[[\"man\"],[\"https://rdap.nic.man/\"]],[[\"mango\"],[\"https://rdap.nic.mango/\"]],[[\"marshalls\"],[\"https://rdap.nic.marshalls/\"]],[[\"mattel\"],[\"https://rdap.nic.mattel/\"]],[[\"melbourne\"],[\"https://rdap.nic.melbourne/\"]],[[\"men\"],[\"https://rdap.nic.men/\"]],[[\"menu\"],[\"https://rdap.nic.menu/\"]],[[\"merckmsd\"],[\"https://rdap.nic.merckmsd/\"]],[[\"miami\"],[\"https://rdap.nic.miami/\"]],[[\"mint\"],[\"https://rdap.nic.mint/\"]],[[\"ml\"],[\"https://rdap.nic.ml/\"]],[[\"mlb\"],[\"https://rdap.nic.mlb/\"]],[[\"mma\"],[\"https://rdap.nic.mma/\"]],[[\"moe\"],[\"https://rdap.nic.moe/\"]],[[\"monash\"],[\"https://rdap.nic.monash/\"]],[[\"moto\"],[\"https://rdap.nic.moto/\"]],[[\"ms\"],[\"https://rdap.nic.ms/\"]],[[\"msd\"],[\"https://rdap.nic.msd/\"]],[[\"museum\"],[\"https://rdap.nic.museum/\"]],[[\"nba\"],[\"https://rdap.nic.nba/\"]],[[\"ng\"],[\"https://rdap.nic.net.ng/\"]],[[\"netbank\"],[\"https://rdap.nic.netbank/\"]],[[\"netflix\"],[\"https://rdap.nic.netflix/\"]],[[\"neustar\"],[\"https://rdap.nic.neustar/\"]],[[\"nf\"],[\"https://rdap.nic.nf/\"]],[[\"nfl\"],[\"https://rdap.nic.nfl/\"]],[[\"nike\"],[\"https://rdap.nic.nike/\"]],[[\"norton\"],[\"https://rdap.nic.norton/\"]],[[\"nrw\"],[\"https://rdap.nic.nrw/\"]],[[\"ntt\"],[\"https://rdap.nic.ntt/rdap/\"]],[[\"nyc\"],[\"https://rdap.nic.nyc/\"]],[[\"olayan\"],[\"https://rdap.nic.olayan/\"]],[[\"olayangroup\"],[\"https://rdap.nic.olayangroup/\"]],[[\"one\"],[\"https://rdap.nic.one/\"]],[[\"open\"],[\"https://rdap.nic.open/\"]],[[\"kr\",\"xn--3e0b707e\"],[\"https://rdap.nic.or.kr/\"]],[[\"osaka\"],[\"https://rdap.nic.osaka/\"]],[[\"ovh\"],[\"https://rdap.nic.ovh/\"]],[[\"paris\"],[\"https://rdap.nic.paris/\"]],[[\"party\"],[\"https://rdap.nic.party/\"]],[[\"pfizer\"],[\"https://rdap.nic.pfizer/\"]],[[\"pg\"],[\"https://rdap.nic.pg/\"]],[[\"philips\"],[\"https://rdap.nic.philips/\"]],[[\"photo\"],[\"https://rdap.nic.photo/\"]],[[\"physio\"],[\"https://rdap.nic.physio/\"]],[[\"ping\"],[\"https://rdap.nic.ping/\"]],[[\"pm\"],[\"https://rdap.nic.pm/\"]],[[\"politie\"],[\"https://rdap.nic.politie/\"]],[[\"porn\"],[\"https://rdap.nic.porn/\"]],[[\"praxi\"],[\"https://rdap.nic.praxi/\"]],[[\"pru\"],[\"https://rdap.nic.pru/\"]],[[\"prudential\"],[\"https://rdap.nic.prudential/\"]],[[\"quebec\"],[\"https://rdap.nic.quebec/\"]],[[\"racing\"],[\"https://rdap.nic.racing/\"]],[[\"re\"],[\"https://rdap.nic.re/\"]],[[\"review\"],[\"https://rdap.nic.review/\"]],[[\"rodeo\"],[\"https://rdap.nic.rodeo/\"]],[[\"rugby\"],[\"https://rdap.nic.rugby/\"]],[[\"sakura\"],[\"https://rdap.nic.sakura/rdap/\"]],[[\"samsclub\"],[\"https://rdap.nic.samsclub/\"]],[[\"sandvik\"],[\"https://rdap.nic.sandvik/\"]],[[\"sandvikcoromant\"],[\"https://rdap.nic.sandvikcoromant/\"]],[[\"sap\"],[\"https://rdap.nic.sap/\"]],[[\"sas\"],[\"https://rdap.nic.sas/\"]],[[\"scb\"],[\"https://rdap.nic.scb/\"]],[[\"schaeffler\"],[\"https://rdap.nic.schaeffler/\"]],[[\"schmidt\"],[\"https://rdap.nic.schmidt/\"]],[[\"science\"],[\"https://rdap.nic.science/\"]],[[\"scot\"],[\"https://rdap.nic.scot/\"]],[[\"sd\"],[\"https://rdap.nic.sd/\"]],[[\"seat\"],[\"https://rdap.nic.seat/\"]],[[\"seek\"],[\"https://rdap.nic.seek/\"]],[[\"select\"],[\"https://rdap.nic.select/\"]],[[\"seven\"],[\"https://rdap.nic.seven/\"]],[[\"sex\"],[\"https://rdap.nic.sex/\"]],[[\"sn\"],[\"https://rdap.nic.sn/whois43/\"]],[[\"sncf\"],[\"https://rdap.nic.sncf/\"]],[[\"sport\"],[\"https://rdap.nic.sport/\"]],[[\"ss\"],[\"https://rdap.nic.ss/\"]],[[\"staples\"],[\"https://rdap.nic.staples/\"]],[[\"statefarm\"],[\"https://rdap.nic.statefarm/\"]],[[\"stream\"],[\"https://rdap.nic.stream/\"]],[[\"study\"],[\"https://rdap.nic.study/\"]],[[\"sucks\"],[\"https://rdap.nic.sucks/\"]],[[\"surf\"],[\"https://rdap.nic.surf/\"]],[[\"swiss\"],[\"https://rdap.nic.swiss/\"]],[[\"sydney\"],[\"https://rdap.nic.sydney/\"]],[[\"tab\"],[\"https://rdap.nic.tab/\"]],[[\"taipei\"],[\"https://rdap.nic.taipei/\"]],[[\"target\"],[\"https://rdap.nic.target/\"]],[[\"tattoo\"],[\"https://rdap.nic.tattoo/\"]],[[\"tdk\"],[\"https://rdap.nic.tdk/\"]],[[\"tel\"],[\"https://rdap.nic.tel/\"]],[[\"teva\"],[\"https://rdap.nic.teva/\"]],[[\"tf\"],[\"https://rdap.nic.tf/\"]],[[\"tjmaxx\"],[\"https://rdap.nic.tjmaxx/\"]],[[\"tjx\"],[\"https://rdap.nic.tjx/\"]],[[\"tkmaxx\"],[\"https://rdap.nic.tkmaxx/\"]],[[\"total\"],[\"https://rdap.nic.total/\"]],[[\"trade\"],[\"https://rdap.nic.trade/\"]],[[\"tube\"],[\"https://rdap.nic.tube/\"]],[[\"tv\"],[\"https://rdap.nic.tv/\"]],[[\"versicherung\"],[\"https://rdap.nic.versicherung/v1/\"]],[[\"vi\"],[\"https://rdap.nic.vi/\"]],[[\"vip\"],[\"https://rdap.nic.vip/\"]],[[\"vivo\"],[\"https://rdap.nic.vivo/\"]],[[\"vlaanderen\"],[\"https://rdap.nic.vlaanderen/\"]],[[\"vodka\"],[\"https://rdap.nic.vodka/\"]],[[\"voting\"],[\"https://rdap.nic.voting/\"]],[[\"walmart\"],[\"https://rdap.nic.walmart/\"]],[[\"walter\"],[\"https://rdap.nic.walter/\"]],[[\"weather\"],[\"https://rdap.nic.weather/\"]],[[\"weatherchannel\"],[\"https://rdap.nic.weatherchannel/\"]],[[\"webcam\"],[\"https://rdap.nic.webcam/\"]],[[\"wedding\"],[\"https://rdap.nic.wedding/\"]],[[\"wf\"],[\"https://rdap.nic.wf/\"]],[[\"whoswho\"],[\"https://rdap.nic.whoswho/\"]],[[\"wiki\"],[\"https://rdap.nic.wiki/\"]],[[\"williamhill\"],[\"https://rdap.nic.williamhill/\"]],[[\"win\"],[\"https://rdap.nic.win/\"]],[[\"winners\"],[\"https://rdap.nic.winners/\"]],[[\"woodside\"],[\"https://rdap.nic.woodside/\"]],[[\"work\"],[\"https://rdap.nic.work/\"]],[[\"wtc\"],[\"https://rdap.nic.wtc/\"]],[[\"xerox\"],[\"https://rdap.nic.xerox/\"]],[[\"xn--80aqecdr1a\"],[\"https://rdap.nic.xn--80aqecdr1a/\"]],[[\"xn--80asehdb\"],[\"https://rdap.nic.xn--80asehdb/\"]],[[\"xn--80aswg\"],[\"https://rdap.nic.xn--80aswg/\"]],[[\"xn--g2xx48c\"],[\"https://rdap.nic.xn--g2xx48c/\"]],[[\"xn--kcrx77d1x4a\"],[\"https://rdap.nic.xn--kcrx77d1x4a/\"]],[[\"xn--mgba3a3ejt\"],[\"https://rdap.nic.xn--mgba3a3ejt/\"]],[[\"xn--mgba7c0bbn0a\"],[\"https://rdap.nic.xn--mgba7c0bbn0a/\"]],[[\"xn--mgbab2bd\"],[\"https://rdap.nic.xn--mgbab2bd/\"]],[[\"xn--mgbca7dzdo\"],[\"https://rdap.nic.xn--mgbca7dzdo/\"]],[[\"xn--mgbi4ecexp\"],[\"https://rdap.nic.xn--mgbi4ecexp/\"]],[[\"xn--ngbc5azd\"],[\"https://rdap.nic.xn--ngbc5azd/\"]],[[\"xn--ngbrx\"],[\"https://rdap.nic.xn--ngbrx/\"]],[[\"xn--p1acf\"],[\"https://rdap.nic.xn--p1acf/\"]],[[\"xn--tiq49xqyj\"],[\"https://rdap.nic.xn--tiq49xqyj/\"]],[[\"xxx\"],[\"https://rdap.nic.xxx/\"]],[[\"yandex\"],[\"https://rdap.nic.yandex/rdap/\"]],[[\"yoga\"],[\"https://rdap.nic.yoga/\"]],[[\"yt\"],[\"https://rdap.nic.yt/\"]],[[\"zm\"],[\"https://rdap.nic.zm/\"]],[[\"in\"],[\"https://rdap.nixiregistry.in/rdap/\"]],[[\"abbvie\"],[\"https://rdap.nominet.uk/abbvie/\"]],[[\"amazon\"],[\"https://rdap.nominet.uk/amazon/\"]],[[\"audible\"],[\"https://rdap.nominet.uk/audible/\"]],[[\"author\"],[\"https://rdap.nominet.uk/author/\"]],[[\"aws\"],[\"https://rdap.nominet.uk/aws/\"]],[[\"azure\"],[\"https://rdap.nominet.uk/azure/\"]],[[\"bbc\"],[\"https://rdap.nominet.uk/bbc/\"]],[[\"bbva\"],[\"https://rdap.nominet.uk/bbva/\"]],[[\"bing\"],[\"https://rdap.nominet.uk/bing/\"]],[[\"book\"],[\"https://rdap.nominet.uk/book/\"]],[[\"bot\"],[\"https://rdap.nominet.uk/bot/\"]],[[\"broadway\"],[\"https://rdap.nominet.uk/broadway/\"]],[[\"buy\"],[\"https://rdap.nominet.uk/buy/\"]],[[\"call\"],[\"https://rdap.nominet.uk/call/\"]],[[\"career\"],[\"https://rdap.nominet.uk/career/\"]],[[\"cymru\"],[\"https://rdap.nominet.uk/cymru/\"]],[[\"deal\"],[\"https://rdap.nominet.uk/deal/\"]],[[\"desi\"],[\"https://rdap.nominet.uk/desi/\"]],[[\"fairwinds\"],[\"https://rdap.nominet.uk/fairwinds/\"]],[[\"fast\"],[\"https://rdap.nominet.uk/fast/\"]],[[\"fire\"],[\"https://rdap.nominet.uk/fire/\"]],[[\"free\"],[\"https://rdap.nominet.uk/free/\"]],[[\"gop\"],[\"https://rdap.nominet.uk/gop/\"]],[[\"gucci\"],[\"https://rdap.nominet.uk/gucci/\"]],[[\"hot\"],[\"https://rdap.nominet.uk/hot/\"]],[[\"hotmail\"],[\"https://rdap.nominet.uk/hotmail/\"]],[[\"ieee\"],[\"https://rdap.nominet.uk/ieee/\"]],[[\"imdb\"],[\"https://rdap.nominet.uk/imdb/\"]],[[\"jobs\"],[\"https://rdap.nominet.uk/jobs/\"]],[[\"joy\"],[\"https://rdap.nominet.uk/joy/\"]],[[\"kindle\"],[\"https://rdap.nominet.uk/kindle/\"]],[[\"like\"],[\"https://rdap.nominet.uk/like/\"]],[[\"locus\"],[\"https://rdap.nominet.uk/locus/\"]],[[\"med\"],[\"https://rdap.nominet.uk/med/\"]],[[\"microsoft\"],[\"https://rdap.nominet.uk/microsoft/\"]],[[\"moi\"],[\"https://rdap.nominet.uk/moi/\"]],[[\"mtn\"],[\"https://rdap.nominet.uk/mtn/\"]],[[\"now\"],[\"https://rdap.nominet.uk/now/\"]],[[\"nowruz\"],[\"https://rdap.nominet.uk/nowruz/\"]],[[\"office\"],[\"https://rdap.nominet.uk/office/\"]],[[\"omega\"],[\"https://rdap.nominet.uk/omega/\"]],[[\"pars\"],[\"https://rdap.nominet.uk/pars/\"]],[[\"pay\"],[\"https://rdap.nominet.uk/pay/\"]],[[\"pharmacy\"],[\"https://rdap.nominet.uk/pharmacy/\"]],[[\"pin\"],[\"https://rdap.nominet.uk/pin/\"]],[[\"pioneer\"],[\"https://rdap.nominet.uk/pioneer/\"]],[[\"pn\"],[\"https://rdap.nominet.uk/pn/\"]],[[\"prime\"],[\"https://rdap.nominet.uk/prime/\"]],[[\"read\"],[\"https://rdap.nominet.uk/read/\"]],[[\"realestate\"],[\"https://rdap.nominet.uk/realestate/\"]],[[\"realtor\"],[\"https://rdap.nominet.uk/realtor/\"]],[[\"room\"],[\"https://rdap.nominet.uk/room/\"]],[[\"safe\"],[\"https://rdap.nominet.uk/safe/\"]],[[\"save\"],[\"https://rdap.nominet.uk/save/\"]],[[\"secure\"],[\"https://rdap.nominet.uk/secure/\"]],[[\"shell\"],[\"https://rdap.nominet.uk/shell/\"]],[[\"shia\"],[\"https://rdap.nominet.uk/shia/\"]],[[\"silk\"],[\"https://rdap.nominet.uk/silk/\"]],[[\"sky\"],[\"https://rdap.nominet.uk/sky/\"]],[[\"skype\"],[\"https://rdap.nominet.uk/skype/\"]],[[\"smile\"],[\"https://rdap.nominet.uk/smile/\"]],[[\"spot\"],[\"https://rdap.nominet.uk/spot/\"]],[[\"swatch\"],[\"https://rdap.nominet.uk/swatch/\"]],[[\"talk\"],[\"https://rdap.nominet.uk/talk/\"]],[[\"tci\"],[\"https://rdap.nominet.uk/tci/\"]],[[\"tunes\"],[\"https://rdap.nominet.uk/tunes/\"]],[[\"tushu\"],[\"https://rdap.nominet.uk/tushu/\"]],[[\"uk\"],[\"https://rdap.nominet.uk/uk/\"]],[[\"virgin\"],[\"https://rdap.nominet.uk/virgin/\"]],[[\"wales\"],[\"https://rdap.nominet.uk/wales/\"]],[[\"wanggou\"],[\"https://rdap.nominet.uk/wanggou/\"]],[[\"wed\"],[\"https://rdap.nominet.uk/wed/\"]],[[\"windows\"],[\"https://rdap.nominet.uk/windows/\"]],[[\"wow\"],[\"https://rdap.nominet.uk/wow/\"]],[[\"xbox\"],[\"https://rdap.nominet.uk/xbox/\"]],[[\"xn--cckwcxetd\"],[\"https://rdap.nominet.uk/xn--cckwcxetd/\"]],[[\"xn--jlq480n2rg\"],[\"https://rdap.nominet.uk/xn--jlq480n2rg/\"]],[[\"xn--mgbt3dhd\"],[\"https://rdap.nominet.uk/xn--mgbt3dhd/\"]],[[\"yamaxun\"],[\"https://rdap.nominet.uk/yamaxun/\"]],[[\"you\"],[\"https://rdap.nominet.uk/you/\"]],[[\"zappos\"],[\"https://rdap.nominet.uk/zappos/\"]],[[\"no\"],[\"https://rdap.norid.no/\"]],[[\"id\"],[\"https://rdap.pandi.id/rdap/\"]],[[\"charity\",\"foundation\",\"gives\",\"giving\",\"ngo\",\"ong\",\"org\",\"xn--c1avg\",\"xn--i1b6b1a6a2e\",\"xn--nqv7f\",\"xn--nqv7fs00ema\"],[\"https://rdap.publicinterestregistry.org/rdap/\"]],[[\"fun\",\"host\",\"online\",\"press\",\"pw\",\"site\",\"space\",\"store\",\"tech\",\"uno\",\"website\"],[\"https://rdap.radix.host/rdap/\"]],[[\"si\"],[\"https://rdap.register.si/\"]],[[\"br\"],[\"https://rdap.registro.br/\"]],[[\"bar\",\"rest\"],[\"https://rdap.registry.bar/rdap/\"]],[[\"click\",\"country\",\"diy\",\"feedback\",\"food\",\"forum\",\"hiv\",\"lifestyle\",\"living\",\"observer\",\"pid\",\"property\",\"realty\",\"sexy\",\"trust\",\"vana\"],[\"https://rdap.registry.click/rdap/\"]],[[\"cloud\"],[\"https://rdap.registry.cloud/rdap/\"]],[[\"coop\",\"creditunion\"],[\"https://rdap.registry.coop/rdap/\"]],[[\"ec\"],[\"https://rdap.registry.ec/\"]],[[\"gift\"],[\"https://rdap.registry.gift/rdap/\"]],[[\"gy\"],[\"https://rdap.registry.gy/\"]],[[\"hiphop\"],[\"https://rdap.registry.hiphop/rdap/\"]],[[\"kfh\",\"xn--ngbe9e0a\"],[\"https://rdap.registry.kfh/rdap/\"]],[[\"love\"],[\"https://rdap.registry.love/rdap/\"]],[[\"music\"],[\"https://rdap.registryservices.music/rdap/\"]],[[\"rw\"],[\"https://rdap.ricta.org.rw/\"]],[[\"cologne\",\"koeln\",\"tirol\",\"wien\"],[\"https://rdap.ryce-rsp.com/rdap/\"]],[[\"sg\"],[\"https://rdap.sgnic.sg/rdap/\"]],[[\"nl\"],[\"https://rdap.sidn.nl/\"]],[[\"xn--clchc0ea0b2g2a9gcd\"],[\"https://rdap.ta.sgnic.sg/rdap/\"]],[[\"anquan\",\"shouji\",\"xihuan\",\"xn--vuq861b\",\"yun\"],[\"https://rdap.teleinfo.cn/\"]],[[\"xn--3ds443g\"],[\"https://rdap.teleinfo.cn/xn--3ds443g/\"]],[[\"xn--fiq228c5hs\"],[\"https://rdap.teleinfo.cn/xn--fiq228c5hs/\"]],[[\"xn--kput3i\"],[\"https://rdap.teleinfo.cn/xn--kput3i/\"]],[[\"xn--nyqy26a\"],[\"https://rdap.teleinfo.cn/xn--nyqy26a/\"]],[[\"xn--rhqv96g\"],[\"https://rdap.teleinfo.cn/xn--rhqv96g/\"]],[[\"th\",\"xn--o3cw4h\"],[\"https://rdap.thains.co.th/\"]],[[\"to\"],[\"https://rdap.tonicregistry.to/rdap/\"]],[[\"xn--mxtq1m\"],[\"https://rdap.twnic.tw/rdap/\"]],[[\"tz\"],[\"https://rdap.tznic.or.tz/\"]],[[\"link\"],[\"https://rdap.uniregistry.net/rdap/\"]],[[\"com\"],[\"https://rdap.verisign.com/com/v1/\"]],[[\"net\"],[\"https://rdap.verisign.com/net/v1/\"]],[[\"ye\"],[\"https://rdap.y.net.ye/\"]],[[\"xn--45q11c\"],[\"https://rdap.zdnsgtld.com/XN--45Q11C/\"]],[[\"xn--efvy88h\"],[\"https://rdap.zdnsgtld.com/XN--EFVY88H/\"]],[[\"baidu\"],[\"https://rdap.zdnsgtld.com/baidu/\"]],[[\"citic\"],[\"https://rdap.zdnsgtld.com/citic/\"]],[[\"icbc\"],[\"https://rdap.zdnsgtld.com/icbc/\"]],[[\"ren\"],[\"https://rdap.zdnsgtld.com/ren/\"]],[[\"sohu\"],[\"https://rdap.zdnsgtld.com/sohu/\"]],[[\"top\"],[\"https://rdap.zdnsgtld.com/top/\"]],[[\"unicom\"],[\"https://rdap.zdnsgtld.com/unicom/\"]],[[\"wang\"],[\"https://rdap.zdnsgtld.com/wang/\"]],[[\"xn--30rr7y\"],[\"https://rdap.zdnsgtld.com/xn--30rr7y/\"]],[[\"xn--3bst00m\"],[\"https://rdap.zdnsgtld.com/xn--3bst00m/\"]],[[\"xn--6qq986b3xl\"],[\"https://rdap.zdnsgtld.com/xn--6qq986b3xl/\"]],[[\"xn--8y0a063a\"],[\"https://rdap.zdnsgtld.com/xn--8y0a063a/\"]],[[\"xn--9et52u\"],[\"https://rdap.zdnsgtld.com/xn--9et52u/\"]],[[\"xn--czr694b\"],[\"https://rdap.zdnsgtld.com/xn--czr694b/\"]],[[\"xn--czru2d\"],[\"https://rdap.zdnsgtld.com/xn--czru2d/\"]],[[\"xn--fiq64b\"],[\"https://rdap.zdnsgtld.com/xn--fiq64b/\"]],[[\"xn--hxt814e\"],[\"https://rdap.zdnsgtld.com/xn--hxt814e/\"]],[[\"xn--imr513n\"],[\"https://rdap.zdnsgtld.com/xn--imr513n/\"]],[[\"xn--otu796d\"],[\"https://rdap.zdnsgtld.com/xn--otu796d/\"]],[[\"xn--ses554g\"],[\"https://rdap.zdnsgtld.com/xn--ses554g/\"]],[[\"xn--yfro4i67o\"],[\"https://rdap.zh.sgnic.sg/rdap/\"]],[[\"xn--1qqw23a\",\"xn--xhq521b\"],[\"https://restwhois.ngtld.cn/\"]],[[\"cc\"],[\"https://tld-rdap.verisign.com/cc/v1/\"]],[[\"comsec\"],[\"https://tld-rdap.verisign.com/comsec/v1/\"]],[[\"name\"],[\"https://tld-rdap.verisign.com/name/v1/\"]],[[\"verisign\"],[\"https://tld-rdap.verisign.com/verisign/v1/\"]],[[\"web\"],[\"https://tld-rdap.verisign.com/web/v1/\"]],[[\"xn--11b4c3d\"],[\"https://tld-rdap.verisign.com/xn--11b4c3d/v1/\"]],[[\"xn--3pxu8k\"],[\"https://tld-rdap.verisign.com/xn--3pxu8k/v1/\"]],[[\"xn--42c2d9a\"],[\"https://tld-rdap.verisign.com/xn--42c2d9a/v1/\"]],[[\"xn--9dbq2a\"],[\"https://tld-rdap.verisign.com/xn--9dbq2a/v1/\"]],[[\"xn--c2br7g\"],[\"https://tld-rdap.verisign.com/xn--c2br7g/v1/\"]],[[\"xn--fhbei\"],[\"https://tld-rdap.verisign.com/xn--fhbei/v1/\"]],[[\"xn--j1aef\"],[\"https://tld-rdap.verisign.com/xn--j1aef/v1/\"]],[[\"xn--mk1bu44c\"],[\"https://tld-rdap.verisign.com/xn--mk1bu44c/v1/\"]],[[\"xn--pssy2u\"],[\"https://tld-rdap.verisign.com/xn--pssy2u/v1/\"]],[[\"xn--t60b56a\"],[\"https://tld-rdap.verisign.com/xn--t60b56a/v1/\"]],[[\"xn--tckwe\"],[\"https://tld-rdap.verisign.com/xn--tckwe/v1/\"]],[[\"ky\"],[\"https://whois.kyregistry.ky/rdap/\"]],[[\"mtr\"],[\"https://whois.nic.mtr/rdap/\"]],[[\"tatar\"],[\"https://whois.nic.tatar/rdap/\"]],[[\"xn--d1acj3b\"],[\"https://whois.nic.xn--d1acj3b/rdap/\"]],[[\"sr\"],[\"https://whois.sr/rdap/\"]],[[\"fj\"],[\"https://www.rdap.fj/\"]]],\"version\":\"1.0\"}',NULL),
(5,'apiKeysEnabled','true',NULL);
/*!40000 ALTER TABLE `setting` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stat_daily`
--

DROP TABLE IF EXISTS `stat_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stat_daily` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_id` int(10) unsigned NOT NULL,
  `timestamp` int(11) NOT NULL COMMENT 'Unix timestamp rounded down to the nearest day',
  `ping` float(20,2) NOT NULL,
  `up` int(10) unsigned NOT NULL,
  `down` int(10) unsigned NOT NULL,
  `ping_min` float(20,2) NOT NULL DEFAULT 0.00,
  `ping_max` float(20,2) NOT NULL DEFAULT 0.00,
  `extras` text DEFAULT NULL COMMENT 'Extra statistics during this time period',
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_daily_monitor_id_timestamp_unique` (`monitor_id`,`timestamp`),
  CONSTRAINT `stat_daily_monitor_id_foreign` FOREIGN KEY (`monitor_id`) REFERENCES `monitor` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='This table contains the daily aggregate statistics for each monitor';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stat_daily`
--

LOCK TABLES `stat_daily` WRITE;
/*!40000 ALTER TABLE `stat_daily` DISABLE KEYS */;
INSERT INTO `stat_daily` VALUES
(1,1,1794009600,0.00,0,1,0.00,0.00,NULL),
(2,7,1794009600,0.05,1,0,0.05,0.05,NULL),
(3,6,1794009600,36.00,1,0,36.00,36.00,NULL),
(4,2,1794009600,124.00,1,0,124.00,124.00,NULL),
(5,3,1794009600,101.00,1,0,101.00,101.00,NULL),
(6,8,1794009600,23.00,1,0,23.00,23.00,NULL),
(7,4,1794009600,408.00,1,0,408.00,408.00,NULL);
/*!40000 ALTER TABLE `stat_daily` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stat_hourly`
--

DROP TABLE IF EXISTS `stat_hourly`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stat_hourly` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_id` int(10) unsigned NOT NULL,
  `timestamp` int(11) NOT NULL COMMENT 'Unix timestamp rounded down to the nearest hour',
  `ping` float(20,2) NOT NULL,
  `ping_min` float(20,2) NOT NULL DEFAULT 0.00,
  `ping_max` float(20,2) NOT NULL DEFAULT 0.00,
  `up` smallint(6) NOT NULL,
  `down` smallint(6) NOT NULL,
  `extras` text DEFAULT NULL COMMENT 'Extra statistics during this time period',
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_hourly_monitor_id_timestamp_unique` (`monitor_id`,`timestamp`),
  CONSTRAINT `stat_hourly_monitor_id_foreign` FOREIGN KEY (`monitor_id`) REFERENCES `monitor` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='This table contains the hourly aggregate statistics for each monitor';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stat_hourly`
--

LOCK TABLES `stat_hourly` WRITE;
/*!40000 ALTER TABLE `stat_hourly` DISABLE KEYS */;
INSERT INTO `stat_hourly` VALUES
(1,1,1794088800,0.00,0.00,0.00,0,1,NULL),
(2,7,1794088800,0.05,0.05,0.05,1,0,NULL),
(3,6,1794088800,36.00,36.00,36.00,1,0,NULL),
(4,2,1794088800,124.00,124.00,124.00,1,0,NULL),
(5,3,1794088800,101.00,101.00,101.00,1,0,NULL),
(6,8,1794088800,23.00,23.00,23.00,1,0,NULL),
(7,4,1794088800,408.00,408.00,408.00,1,0,NULL);
/*!40000 ALTER TABLE `stat_hourly` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stat_minutely`
--

DROP TABLE IF EXISTS `stat_minutely`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stat_minutely` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_id` int(10) unsigned NOT NULL,
  `timestamp` int(11) NOT NULL COMMENT 'Unix timestamp rounded down to the nearest minute',
  `ping` float(20,2) NOT NULL,
  `up` smallint(6) NOT NULL,
  `down` smallint(6) NOT NULL,
  `ping_min` float(20,2) NOT NULL DEFAULT 0.00,
  `ping_max` float(20,2) NOT NULL DEFAULT 0.00,
  `extras` text DEFAULT NULL COMMENT 'Extra statistics during this time period',
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_minutely_monitor_id_timestamp_unique` (`monitor_id`,`timestamp`),
  CONSTRAINT `stat_minutely_monitor_id_foreign` FOREIGN KEY (`monitor_id`) REFERENCES `monitor` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='This table contains the minutely aggregate statistics for each monitor';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stat_minutely`
--

LOCK TABLES `stat_minutely` WRITE;
/*!40000 ALTER TABLE `stat_minutely` DISABLE KEYS */;
INSERT INTO `stat_minutely` VALUES
(1,1,1794091920,0.00,0,1,0.00,0.00,NULL),
(2,7,1794091920,0.05,1,0,0.05,0.05,NULL),
(3,6,1794091920,36.00,1,0,36.00,36.00,NULL),
(4,2,1794091920,124.00,1,0,124.00,124.00,NULL),
(5,3,1794091920,101.00,1,0,101.00,101.00,NULL),
(6,8,1794091920,23.00,1,0,23.00,23.00,NULL),
(7,4,1794091920,408.00,1,0,408.00,408.00,NULL);
/*!40000 ALTER TABLE `stat_minutely` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `status_page`
--

DROP TABLE IF EXISTS `status_page`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `status_page` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(255) NOT NULL,
  `theme` varchar(30) NOT NULL,
  `published` tinyint(1) NOT NULL DEFAULT 1,
  `search_engine_index` tinyint(1) NOT NULL DEFAULT 1,
  `show_tags` tinyint(1) NOT NULL DEFAULT 0,
  `password` varchar(255) DEFAULT NULL,
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  `modified_date` datetime NOT NULL DEFAULT current_timestamp(),
  `footer_text` text DEFAULT NULL,
  `custom_css` text DEFAULT NULL,
  `show_powered_by` tinyint(1) NOT NULL DEFAULT 1,
  `analytics_id` varchar(255) DEFAULT NULL,
  `show_certificate_expiry` tinyint(1) NOT NULL DEFAULT 0,
  `auto_refresh_interval` int(10) unsigned DEFAULT 300,
  `analytics_script_url` varchar(255) DEFAULT NULL,
  `analytics_type` varchar(255) DEFAULT NULL,
  `show_only_last_heartbeat` tinyint(1) NOT NULL DEFAULT 0,
  `rss_title` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `status_page_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `status_page`
--

LOCK TABLES `status_page` WRITE;
/*!40000 ALTER TABLE `status_page` DISABLE KEYS */;
INSERT INTO `status_page` VALUES
(1,'public','Public Status','desc','/icon.svg','dark',1,1,1,NULL,'2026-11-07 22:52:24','2026-11-07 22:52:24','footer **md**','body{}',0,'G-TEST',1,300,NULL,'google',0,'RSS');
/*!40000 ALTER TABLE `status_page` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `status_page_cname`
--

DROP TABLE IF EXISTS `status_page_cname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `status_page_cname` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `status_page_id` int(10) unsigned DEFAULT NULL,
  `domain` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `status_page_cname_domain_unique` (`domain`),
  KEY `status_page_cname_status_page_id_foreign` (`status_page_id`),
  CONSTRAINT `status_page_cname_status_page_id_foreign` FOREIGN KEY (`status_page_id`) REFERENCES `status_page` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `status_page_cname`
--

LOCK TABLES `status_page_cname` WRITE;
/*!40000 ALTER TABLE `status_page_cname` DISABLE KEYS */;
INSERT INTO `status_page_cname` VALUES
(1,1,'status.example.com');
/*!40000 ALTER TABLE `status_page_cname` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tag`
--

DROP TABLE IF EXISTS `tag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tag` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `color` varchar(255) NOT NULL,
  `created_date` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tag`
--

LOCK TABLES `tag` WRITE;
/*!40000 ALTER TABLE `tag` DISABLE KEYS */;
INSERT INTO `tag` VALUES
(1,'prod','#059669','2026-11-07 22:52:24');
/*!40000 ALTER TABLE `tag` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user`
--

DROP TABLE IF EXISTS `user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `timezone` varchar(150) DEFAULT NULL,
  `twofa_secret` varchar(64) DEFAULT NULL,
  `twofa_status` tinyint(1) NOT NULL DEFAULT 0,
  `twofa_last_token` varchar(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_username_unique` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user`
--

LOCK TABLES `user` WRITE;
/*!40000 ALTER TABLE `user` DISABLE KEYS */;
INSERT INTO `user` VALUES
(1,'admin','$2a$10$TKaRh8LGnwqHTgeFl8Fh2OnHBwZZ31mmDl8sa.y.quo7sePYcj5FS',1,NULL,NULL,0,NULL);
/*!40000 ALTER TABLE `user` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-07 22:52:41
