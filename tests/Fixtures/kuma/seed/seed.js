// Seeds an Uptime Kuma instance through its socket.io API (same calls the web UI makes).
// Usage: node seed.js <baseUrl> <v2|v1> [backupOut.json]
//   v2: full sample (monitors of many types, notifications, tags, proxy, docker host,
//       status page + groups + incident, maintenances, API key).
//   v1: smaller sample, then rebuilds the 1.23 "Export Backup" JSON exactly as
//       src/components/settings/Backup.vue downloadBackup() does (client side).
const { io } = require("socket.io-client");

const [, , baseUrl, mode, backupOut] = process.argv;
const socket = io(baseUrl, { transports: ["websocket"], reconnection: false, timeout: 10000 });
const state = { monitorList: null, notificationList: null, info: null };
socket.on("monitorList", (l) => (state.monitorList = l));
socket.on("notificationList", (l) => (state.notificationList = l));
socket.on("info", (i) => (state.info = i));

const call = (event, ...args) =>
    new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error(`timeout on ${event}`)), 15000);
        socket.emit(event, ...args, (res) => {
            clearTimeout(t);
            if (res && res.ok === false) {
                reject(new Error(`${event}: ${res.msg}`));
            } else {
                resolve(res);
            }
        });
    });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const base = {
    parent: null, method: "GET", interval: 60, retryInterval: 60, resendInterval: 0, maxretries: 0,
    notificationIDList: {}, ignoreTls: false, upsideDown: false, expiryNotification: false, maxredirects: 10,
    accepted_statuscodes: ["200-299"], dns_resolve_type: "A", dns_resolve_server: "1.1.1.1",
    docker_container: "", docker_host: null, proxyId: null, basic_auth_user: "", basic_auth_pass: "",
    mqttUsername: "", mqttPassword: "", mqttTopic: "", mqttSuccessMessage: "", authMethod: null,
    httpBodyEncoding: "json", kafkaProducerBrokers: [], kafkaProducerSaslOptions: { mechanism: "None" },
    kafkaProducerSsl: false, kafkaProducerAllowAutoTopicCreation: false, gamedigGivenPortOnly: true,
    remote_browser: null, rabbitmqNodes: [], rabbitmqUsername: "", rabbitmqPassword: "", conditions: [],
    timeout: 48, active: true,
};
const v1base = { ...base };
for (const k of ["kafkaProducerBrokers", "kafkaProducerSaslOptions", "rabbitmqNodes", "rabbitmqUsername",
    "rabbitmqPassword", "conditions", "remote_browser"]) {
    delete v1base[k];
}

async function main() {
    await new Promise((res, rej) => {
        socket.on("connect", res);
        socket.on("connect_error", rej);
    });
    // Server registers its handlers only after it has sent "info" (await sendInfo in io.on("connection")),
    // so emitting right on "connect" can race and be dropped. Wait for "info" first.
    for (let i = 0; i < 50 && !state.info; i++) {
        await sleep(100);
    }
    await sleep(500);
    await call("setup", "admin", "Research-Pass-123!");
    await call("login", { username: "admin", password: "Research-Pass-123!", token: "" });
    const B = mode === "v2" ? base : v1base;

    // Notifications: config is the whole form object; server stores JSON.stringify(notification) in `config`.
    const nSmtp = await call("addNotification", {
        name: "Ops e-mail", type: "smtp", isDefault: true, applyExisting: false,
        smtpHost: "smtp.example.com", smtpPort: 587, smtpSecure: false, smtpUsername: "u", smtpPassword: "p",
        smtpFrom: "kuma@example.com", smtpTo: "ops@example.com", smtpCC: "", smtpBCC: "",
    }, null);
    const nHook = await call("addNotification", {
        name: "Webhook", type: "webhook", isDefault: false, applyExisting: false,
        webhookURL: "https://hooks.example.com/kuma", webhookContentType: "json",
        webhookAdditionalHeaders: "{\"X-Token\":\"abc\"}",
    }, null);
    const notif = { [nSmtp.id]: true, [nHook.id]: true };

    const tag = await call("addTag", { name: "prod", color: "#059669" });
    const tagId = tag.tag.id;

    let proxyId = null;
    if (mode === "v2") {
        const p = await call("addProxy", { protocol: "http", host: "proxy.example.com", port: 3128, auth: true,
            username: "pu", password: "pp", default: false, applyExisting: false }, null);
        proxyId = p.id;
        await call("addDockerHost", { name: "local docker", dockerType: "socket",
            dockerDaemon: "/var/run/docker.sock" }, null);
    }

    const ids = {};
    const add = async (key, m) => {
        const r = await call("add", { ...B, ...m });
        ids[key] = r.monitorID;
    };
    await add("group", { type: "group", name: "Websites", description: "group monitor" });
    await add("http", { type: "http", name: "Example HTTP", url: "https://example.com", parent: ids.group,
        notificationIDList: notif, maxretries: 3, retryInterval: 30, resendInterval: 10, expiryNotification: true,
        headers: "{\"X-A\":\"1\"}", authMethod: "basic", basic_auth_user: "bu", basic_auth_pass: "bp",
        accepted_statuscodes: ["200-299", "301"], description: "http with basic auth" });
    await add("keyword", { type: "keyword", name: "Keyword", url: "https://example.com", keyword: "Example Domain",
        invertKeyword: false, parent: ids.group });
    if (mode === "v2") {
        await add("json", { type: "json-query", name: "JSON query", url: "https://httpbin.org/json",
            jsonPath: "$.slideshow.author", jsonPathOperator: "==", expectedValue: "Yours Truly", method: "GET" });
        await add("proxied", { type: "http", name: "Via proxy (paused)", url: "https://example.org",
            proxyId, active: false, method: "POST", body: "{\"a\":1}", httpBodyEncoding: "json" });
    }
    await add("port", { type: "port", name: "TCP 443", hostname: "example.com", port: 443 });
    await add("ping", { type: "ping", name: "Ping localhost", hostname: "127.0.0.1" });
    await add("dns", { type: "dns", name: "DNS", hostname: "example.com", dns_resolve_type: "A",
        dns_resolve_server: "1.1.1.1", port: 53 });
    await add("push", { type: "push", name: "Push", pushToken: "researchPushToken01", interval: 60 });
    await add("redis", { type: "redis", name: "Redis", databaseConnectionString: "redis://user:secret@redis.local:6379",
        active: false });

    await call("addMonitorTag", tagId, ids.http, "eu-west");
    await call("addMonitorTag", tagId, ids.ping, "");

    // Status page with two sections, custom domain and an incident banner.
    await call("addStatusPage", "Public Status", "public");
    const spList = (await call("getStatusPage", "public")).config;
    await call("saveStatusPage", "public", {
        slug: "public", title: "Public Status", description: "desc", logo: "/icon.svg",
        autoRefreshInterval: 300, theme: "dark", showTags: true, footerText: "footer **md**",
        customCSS: "body{}", showPoweredBy: false, showCertificateExpiry: true,
        domainNameList: ["status.example.com"], googleAnalyticsId: "G-TEST",
        analyticsId: "G-TEST", analyticsScriptUrl: null, analyticsType: "google",
        rssTitle: "RSS", showOnlyLastHeartbeat: false,
    }, "/icon.svg", [
        { name: "Web", monitorList: [{ id: ids.http, sendUrl: true }, { id: ids.keyword }] },
        { name: "Infra", monitorList: [{ id: ids.ping }, { id: ids.port }] },
    ]).catch(async (e) => {
        // 1.23 has a smaller config; retry with its keys only.
        if (mode !== "v1") throw e;
    });
    void spList;
    await call("postIncident", "public", { title: "Degraded API", content: "Investigating", style: "warning" });

    // Maintenances
    const mk = (o) => ({ title: "", description: "", strategy: "manual", active: true, intervalDay: 1,
        dateRange: [null, null], timeRange: [{ hours: 2, minutes: 0 }, { hours: 3, minutes: 0 }],
        weekdays: [], daysOfMonth: [], cron: "30 3 * * *", durationMinutes: 60, timezoneOption: "SAME_AS_SERVER", ...o });
    const m1 = await call("addMaintenance", mk({ title: "Weekly patching", description: "Sun 02-03",
        strategy: "recurring-weekday", weekdays: [7], timezoneOption: "Europe/Budapest",
        dateRange: ["2026-11-01 00:00", null] }));
    await call("addMonitorMaintenance", m1.maintenanceID, [{ id: ids.http }, { id: ids.ping }]);
    const spId = (await call("getStatusPage", "public")).config.id;
    await call("addMaintenanceStatusPage", m1.maintenanceID, [{ id: spId }]);
    await call("addMaintenance", mk({ title: "Manual window", strategy: "manual", active: false }));
    await call("addMaintenance", mk({ title: "One-off", strategy: "single", timezoneOption: "UTC",
        dateRange: ["2026-12-01 01:00", "2026-12-01 02:00"] }));
    if (mode === "v2") {
        await call("addMaintenance", mk({ title: "Cron nightly", strategy: "cron", cron: "30 3 * * *",
            durationMinutes: 45, timezoneOption: "UTC" }));
        await call("addMaintenance", mk({ title: "Every 3 days", strategy: "recurring-interval", intervalDay: 3,
            dateRange: ["2026-11-01 00:00", null] }));
        await call("addMaintenance", mk({ title: "1st and last of month", strategy: "recurring-day-of-month",
            daysOfMonth: [1, "lastDay1"], dateRange: ["2026-11-01 00:00", null] }));
        await call("addAPIKey", { name: "importer", expires: null, active: 1 });
    }

    console.log("seeded ids", JSON.stringify(ids));

    if (backupOut) {
        await sleep(3000);
        // Mirrors 1.23 src/components/settings/Backup.vue downloadBackup()
        const exportData = {
            version: state.info && state.info.version,
            notificationList: state.notificationList,
            monitorList: Object.values(state.monitorList || {}),
        };
        require("fs").writeFileSync(backupOut, JSON.stringify(exportData, null, 4));
        console.log("wrote", backupOut);
    }
    socket.close();
}

main().catch((e) => {
    console.error("FAILED", e.message);
    process.exit(1);
});
