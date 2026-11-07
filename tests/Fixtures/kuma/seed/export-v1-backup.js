// Logs into an already-seeded Uptime Kuma 1.23 instance and writes the JSON that the
// 1.23 Settings -> Backup -> Export button produces (src/components/settings/Backup.vue downloadBackup()).
// Usage: node export-v1-backup.js <baseUrl> <out.json>
const { io } = require("socket.io-client");
const fs = require("fs");

const [, , baseUrl, out] = process.argv;
const socket = io(baseUrl, { transports: ["websocket"], reconnection: false });
const state = {};
socket.on("info", (i) => (state.info = i));
socket.on("monitorList", (l) => (state.monitorList = l));
socket.on("notificationList", (l) => (state.notificationList = l));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
    await new Promise((r) => socket.on("connect", r));
    for (let i = 0; i < 50 && !state.info; i++) await sleep(100);
    await sleep(500);
    await new Promise((resolve, reject) =>
        socket.emit("login", { username: "admin", password: "Research-Pass-123!", token: "" }, (res) =>
            res.ok ? resolve() : reject(new Error(res.msg))));
    for (let i = 0; i < 100 && !(state.monitorList && state.notificationList); i++) await sleep(100);
    const exportData = {
        version: state.info.version,
        notificationList: state.notificationList,
        monitorList: Object.values(state.monitorList),
    };
    fs.writeFileSync(out, JSON.stringify(exportData, null, 4));
    console.log("wrote", out, exportData.monitorList.length, "monitors");
    socket.close();
})().catch((e) => {
    console.error("FAILED", e.message);
    process.exit(1);
});
