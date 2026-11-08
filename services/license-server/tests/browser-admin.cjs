const path = require("node:path");
let chromium;
try {
  ({ chromium } = require(
    process.env.FLUXFILES_TEST_PLAYWRIGHT_MODULE || "playwright",
  ));
} catch (_) {
  try {
    ({ chromium } = require(
      path.resolve(
        __dirname,
        "../../../packages/core/tests/browser/node_modules/playwright",
      ),
    ));
  } catch (_) {
    throw new Error(
      "Install Playwright as documented in README or set FLUXFILES_TEST_PLAYWRIGHT_MODULE.",
    );
  }
}
const { spawn, execFileSync } = require("node:child_process");
const fs = require("node:fs");
const os = require("node:os");
const net = require("node:net");
const php = process.env.FLUXFILES_TEST_PHP_BINARY || "php";
const output =
  process.env.FLUXFILES_TEST_OUTPUT_DIR ||
  path.join(os.tmpdir(), "fluxfiles-admin-screenshots");
fs.mkdirSync(output, { recursive: true });
const root = path.resolve(__dirname, "..");
const dir = fs.mkdtempSync(os.tmpdir() + "/ff-admin-visual-");
const children = [];
let browser;
let page;
const port = () =>
  new Promise((resolve) => {
    const s = net.createServer();
    s.listen(0, "127.0.0.1", () => {
      const p = s.address().port;
      s.close(() => resolve(p));
    });
  });
const start = async (file, env) => {
  const p = await port();
  const child = spawn(php, ["-S", "127.0.0.1:" + p, file], {
    env: { PATH: process.env.PATH, ...env },
    stdio: "ignore",
  });
  children.push(child);
  for (let i = 0; i < 100; i++) {
    try {
      await fetch("http://127.0.0.1:" + p + "/health");
      return p;
    } catch {}
    await new Promise((r) => setTimeout(r, 50));
  }
  throw Error("server start");
};
(async () => {
  const setup = `require '${root}/LicenseStore.php';require '${root}/LicenseSigner.php';require '${root}/LicenseIssuer.php';require '${root}/Plans.php';require '${root}/admin/src/Auth.php';$a=new FluxFiles\\LicenseServer\\Admin\\Auth('${dir}/admin.sqlite');$secret=FluxFiles\\LicenseServer\\Admin\\Totp::secret();$a->db->prepare('INSERT INTO users(name,pass_hash,totp_secret,created_at) VALUES(?,?,?,?)')->execute(['owner',password_hash('test-password-long',PASSWORD_BCRYPT),$secret,time()]);$signer=new FluxFiles\\LicenseServer\\LicenseSigner(base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));$s=new FluxFiles\\LicenseServer\\LicenseStore('${dir}/l.sqlite');$issuer=new FluxFiles\\LicenseServer\\LicenseIssuer($signer,$s);$r=$issuer->issue(['email'=>'linh@example.test','customer'=>'Linh Nguyễn','plan'=>'pro-monthly','gateway'=>'polar','order_id'=>'ord-visual']);$s->markMailed($r['record']['jti']);$s->upsertOrder(['order_id'=>'ord-visual','currency'=>'usd','status'=>'paid','paid_at'=>time(),'net_amount'=>1900,'total_amount'=>1900,'plan'=>'pro-monthly','jti'=>$r['record']['jti']]);$a->db->prepare('INSERT INTO users(name,pass_hash,totp_secret,created_at,role) VALUES(?,?,?,?,?)')->execute(['viewer',password_hash('test-password-long',PASSWORD_BCRYPT),$secret,time(),'viewer']);$s->pdo()->prepare('INSERT INTO install_pings VALUES(?,?,?,?,?,?,0,1,1,?,?)')->execute([gmdate('Y-m-d'),$r['record']['jti'],'share','hmac','1.2.1','1.2.1',time(),time()]);$s->pdo()->prepare('INSERT INTO advisories VALUES(?,?,?,?,?,?,?,?)')->execute(['FF-BROWSER-1','share','<1.2.3','1.2.3','high','Browser security notice','https://example.test/fix',time()]);$s->ingest('public_counters',gmdate('Y-m-d'),[['source'=>'github','subject'=>'test/repo','metric'=>'stargazers_count','value'=>10]]);$s->ingest('demo_daily',gmdate('Y-m-d'),[['sandboxes_created'=>3,'sandbox_limit_hits'=>1,'page_limit_hits'=>2,'sandboxes_live'=>2,'uploads_bytes'=>1000]]);$s->state('heartbeat',['at'=>time(),'stage_errors'=>['mail_jobs']]);echo json_encode(['secret'=>$secret,'jti'=>$r['record']['jti']]);`;
  const seed = JSON.parse(
    execFileSync(php, ["-r", setup], { encoding: "utf8" }),
  );
  const api = await start(root + "/server.php", {
    FLUXFILES_LICENSE_DB: dir + "/l.sqlite",
    FLUXFILES_LICENSE_ADMIN_TOKEN: "a",
    FLUXFILES_LICENSE_UI_TOKEN: "u",
    FLUXFILES_LICENSE_OPS_TOKEN: "o",
    FLUXFILES_UPDATE_STATUS_TOKEN: "s",
  });
  const ui = await start(root + "/admin/public/index.php", {
    FLUXFILES_ADMIN_API: "http://127.0.0.1:" + api,
    FLUXFILES_ADMIN_UI_TOKEN: "u",
    FLUXFILES_ADMIN_DB: dir + "/admin.sqlite",
    FLUXFILES_ADMIN_TEST_CLOCK: "1",
  });
  browser = await chromium.launch({ headless: true });
  page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto("http://127.0.0.1:" + ui + "/login");
  await page.locator("[name=username]").fill("owner");
  await page.locator("[name=password]").fill("test-password-long");
  const code = execFileSync(
    php,
    [
      "-r",
      `require '${root}/admin/src/Totp.php';echo FluxFiles\\LicenseServer\\Admin\\Totp::code('${seed.secret}',intdiv(time(),30));`,
    ],
    { encoding: "utf8" },
  );
  await page.locator("[name=code]").fill(code);
  const loginResponse = page.waitForResponse(
    (r) => r.request().method() === "POST",
  );
  await page.locator("button.primary").click();
  const lr = await loginResponse;
  if (lr.status() >= 400) {
    const hh = await lr.request().allHeaders();
    console.log(
      JSON.stringify({
        origin: hh.origin,
        fetchSite: hh["sec-fetch-site"],
        hasCookie: !!hh.cookie,
      }),
    );
  }
  if (lr.status() >= 400)
    throw Error(
      "login status " +
        lr.status() +
        " " +
        (await page.locator("main").innerText()),
    );
  await page.waitForURL("http://127.0.0.1:" + ui + "/");
  const shortControl = await page
    .locator(".brand, button, .button")
    .evaluateAll((nodes) =>
      nodes.some(
        (node) =>
          node.getBoundingClientRect().width > 0 &&
          node.getBoundingClientRect().height < 44,
      ),
    );
  if (shortControl) throw Error("Interactive controls below 44px");
  if (
    !(await page.locator("main").innerText()).includes("Gửi thư trong hàng đợi")
  )
    throw Error("Dashboard omits worker stage errors");
  if (
    !(await page.locator("main").innerText()).includes(
      "Chưa cấu hình mã giảm giá theo dõi",
    )
  )
    throw Error("Missing discount empty guidance");
  await page.screenshot({
    path: path.join(output, "ff-admin-desktop.png"),
    fullPage: true,
  });
  await page.emulateMedia({ colorScheme: "dark" });
  await page.screenshot({
    path: path.join(output, "ff-admin-dark.png"),
    fullPage: true,
  });
  await page.emulateMedia({ colorScheme: "light" });
  await page.goto("http://127.0.0.1:" + ui + "/licences");
  await page.keyboard.press("/");
  if (
    await page
      .locator("[data-search]")
      .evaluate((el) => el !== document.activeElement)
  )
    throw Error("search shortcut");
  const badHeader = await page
    .locator("th")
    .evaluateAll((nodes) =>
      nodes.some(
        (n) =>
          getComputedStyle(n).whiteSpace !== "nowrap" ||
          getComputedStyle(n).overflowWrap === "anywhere",
      ),
    );
  if (badHeader) throw Error("Licence table headers may wrap mid-word");
  if (
    await page
      .locator(".licence-list .table-wrap")
      .evaluate((el) => el.scrollWidth > el.clientWidth + 1)
  )
    throw Error("1440px licence list needs horizontal scrolling");
  if (
    await page
      .locator(".licence-list time")
      .evaluateAll((nodes) =>
        nodes.some((el) => getComputedStyle(el).whiteSpace !== "nowrap"),
      )
  )
    throw Error("Licence dates wrap");
  await page.locator("[data-search]").blur();
  await page.keyboard.press("j");
  if ((await page.locator("tr.selected").count()) !== 1)
    throw Error("row navigation");
  const stepTime = Math.floor(Date.now() / 1000) + 61;
  await page.setExtraHTTPHeaders({ "X-FluxFiles-Test-Time": String(stepTime) });
  const freshCode = execFileSync(
    php,
    [
      "-r",
      `require '${root}/admin/src/Totp.php';echo FluxFiles\\LicenseServer\\Admin\\Totp::code('${seed.secret}',intdiv(${stepTime},30));`,
    ],
    { encoding: "utf8" },
  );
  await page.goto(
    "http://127.0.0.1:" + ui + "/licences/" + seed.jti + "?confirm=reveal",
  );
  await page.locator("[data-reveal-form] [name=code]").fill(freshCode);
  await page.locator("[data-reveal-form] button").click();
  await page.waitForSelector("#revealed[open]");
  if (!(await page.locator("[data-key]").inputValue()).includes("."))
    throw Error("reveal");
  await page.locator("#revealed [data-close]").click();
  if ((await page.locator("[data-key]").inputValue()) !== "")
    throw Error("key not cleared");
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto("http://127.0.0.1:" + ui + "/");
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth > window.innerWidth,
  );
  await page.screenshot({
    path: path.join(output, "ff-admin-mobile.png"),
    fullPage: true,
  });
  if (overflow) throw Error("mobile viewport overflow");
  const headerRows = await page.locator(".topbar").evaluate((el) => {
    const brand = el.querySelector(".brand").getBoundingClientRect();
    const controls = el
      .querySelector(".header-controls")
      .getBoundingClientRect();
    return (
      Math.abs(
        (brand.top + brand.bottom) / 2 - (controls.top + controls.bottom) / 2,
      ) > 2
    );
  });
  if (headerRows) throw Error("Mobile header has multiple rows");
  if (await page.locator("#admin-nav").isVisible())
    throw Error("Mobile navigation must collapse");
  await page.locator("[data-nav-toggle]").click();
  if (!(await page.locator("#admin-nav").isVisible()))
    throw Error("Mobile menu does not open");
  if (
    await page
      .locator("#admin-nav")
      .evaluate(
        (el) =>
          getComputedStyle(el).display !== "grid" ||
          el.scrollWidth > el.clientWidth,
      )
  )
    throw Error("Mobile menu is a horizontal strip");
  await page.locator("[data-nav-toggle]").click();
  await page.goto("http://127.0.0.1:" + ui + "/licences");
  await page.screenshot({
    path: path.join(output, "ff-admin-mobile-list.png"),
    fullPage: true,
  });
  if (
    await page.evaluate(
      () => document.documentElement.scrollWidth > window.innerWidth,
    )
  )
    throw Error("mobile list overflow");
  if (
    await page
      .locator(".row-link")
      .first()
      .evaluate((el) => el.getBoundingClientRect().height < 44)
  )
    throw Error("Mobile row link below 44px");
  if (
    await page
      .locator(".list-actions")
      .evaluate(
        (el) =>
          getComputedStyle(el).position !== "fixed" ||
          el.getBoundingClientRect().bottom > innerHeight + 1,
      )
  )
    throw Error("Mobile list lacks sticky action bar");
  await page.goto("http://127.0.0.1:" + ui + "/stats/revenue");
  if (
    (await page.locator(".chart-legend").count()) === 0 ||
    (await page.locator(".y-axis").count()) === 0
  )
    throw Error("Revenue legend/axis missing");
  if (!(await page.locator("main").innerText()).includes("19.00 USD"))
    throw Error("Raw minor units in revenue table");
  const refunds = page
    .locator("section.card")
    .filter({ has: page.locator("h2", { hasText: "Hoàn tiền theo tháng" }) });
  if (
    (await refunds.locator("table").count()) > 0 &&
    (await refunds.locator(".empty").count()) > 0
  )
    throw Error("Stray empty guidance under populated refund table");
  const routes = [
    "/customers",
    "/mail",
    "/activity",
    "/advisories",
    "/notices",
    "/stats/customers",
    "/stats/versions",
    "/stats/downloads",
    "/stats/growth",
    "/stats/demo",
    "/stats/anomalies",
    "/system",
    "/export",
  ];
  for (const width of [1440, 375]) {
    await page.setViewportSize({ width, height: 900 });
    for (const route of routes) {
      const response = await page.goto("http://127.0.0.1:" + ui + route);
      if (response.status() !== 200)
        throw Error(route + " status " + response.status());
      if (
        await page.evaluate(
          () => document.documentElement.scrollWidth > window.innerWidth,
        )
      )
        throw Error(route + " overflow at " + width);
    }
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(
    "http://127.0.0.1:" + ui + "/notices/new?advisory=FF-BROWSER-1",
  );
  await page.locator("[name=subject]").fill("Security fix {fixed_in}");
  if (
    (await page.locator("[data-preview-subject]").innerText()) !==
    "Security fix 1.2.3"
  )
    throw Error("live preview");
  await page.locator("[name=confirmation]").fill("FF-BROWSER-1");
  await page.locator("[data-notice-form] button.primary").click();
  await page.waitForURL("**/notices?notice=success");
  await page.screenshot({
    path: path.join(output, "ff-admin-phase-notices.png"),
    fullPage: true,
  });
  await page.goto("http://127.0.0.1:" + ui + "/export");
  const downloadPromise = page.waitForEvent("download");
  await page.locator("main form button").click();
  const download = await downloadPromise;
  const bytes = fs.readFileSync(await download.path());
  if (bytes.subarray(0, 3).toString("hex") !== "efbbbf") throw Error("CSV BOM");
  const viewerContext = await browser.newContext();
  const viewer = await viewerContext.newPage();
  viewer.on("pageerror", (e) => errors.push(e.message));
  await viewer.goto("http://127.0.0.1:" + ui + "/login");
  await viewer.locator("[name=username]").fill("viewer");
  await viewer.locator("[name=password]").fill("test-password-long");
  const viewerCode = execFileSync(
    php,
    [
      "-r",
      `require '${root}/admin/src/Totp.php';echo FluxFiles\\LicenseServer\\Admin\\Totp::code('${seed.secret}',intdiv(time(),30));`,
    ],
    { encoding: "utf8" },
  );
  await viewer.locator("[name=code]").fill(viewerCode);
  await viewer.locator("button.primary").click();
  await viewer.waitForURL("http://127.0.0.1:" + ui + "/");
  for (const route of routes.filter((x) => x !== "/export")) {
    const response = await viewer.goto("http://127.0.0.1:" + ui + route);
    if (response.status() !== 200) throw Error("viewer " + route);
  }
  for (const route of [
    "/issue",
    "/export",
    "/notices/new?advisory=FF-BROWSER-1",
  ]) {
    if ((await viewer.goto("http://127.0.0.1:" + ui + route)).status() !== 403)
      throw Error("viewer write allowed");
  }
  await viewerContext.close();
  const sbxApi = await start(root + "/server.php", {
    FLUXFILES_LICENSE_INSTANCE: "sandbox",
    FLUXFILES_LICENSE_DB: dir + "/sbx.sqlite",
    FLUXFILES_LICENSE_ADMIN_TOKEN: "a2",
    FLUXFILES_LICENSE_UI_TOKEN: "u2",
    FLUXFILES_LICENSE_OPS_TOKEN: "o2",
    FLUXFILES_UPDATE_STATUS_TOKEN: "s2",
  });
  const sbxUi = await start(root + "/admin/public/index.php", {
    FLUXFILES_LICENSE_INSTANCE: "sandbox",
    FLUXFILES_ADMIN_API: "http://127.0.0.1:" + sbxApi,
    FLUXFILES_ADMIN_UI_TOKEN: "u2",
    FLUXFILES_ADMIN_DB: dir + "/sbx-admin.sqlite",
  });
  await page.emulateMedia({ colorScheme: "dark" });
  await page.goto("http://127.0.0.1:" + sbxUi + "/login");
  if (!(await page.title()).startsWith("[SANDBOX]"))
    throw Error("sandbox title");
  if ((await page.locator(".sandbox-banner").count()) !== 1)
    throw Error("sandbox banner");
  if (
    (
      await page.evaluate(() =>
        getComputedStyle(document.documentElement).getPropertyValue("--accent"),
      )
    ).trim() !== "#ffd082"
  )
    throw Error("sandbox dark accent");
  await page.screenshot({
    path: path.join(output, "ff-admin-sandbox.png"),
    fullPage: true,
  });
  const hostUi = await start(root + "/admin/public/index.php", {
    FLUXFILES_ADMIN_DB: dir + "/host-admin.sqlite",
    FLUXFILES_ADMIN_HOSTS: "prod.localhost,sbx.localhost",
  });
  await page.goto("http://prod.localhost:" + hostUi + "/login");
  if (
    (await page.context().cookies("http://sbx.localhost:" + hostUi)).some(
      (c) => c.name === "fxadm",
    )
  )
    throw Error("Production cookie crossed instance host");
  const hostCookies = await page
    .context()
    .cookies("http://prod.localhost:" + hostUi);
  if (
    !hostCookies.some(
      (c) => c.name === "fxadm" && c.domain === "prod.localhost",
    )
  )
    throw Error("Host-only cookie missing");
  await browser.close();
  if (errors.length) throw Error(errors.join("; "));
  console.log(
    "Browser checks passed: all phase screens at 1440/375px, notice live preview and queue, CSV download/BOM, viewer read-only;  login, desktop/light/dark, 375px layouts, keyboard, reveal/clear, isolated sandbox banner and amber dark accent.",
  );
})()
  .catch((e) => {
    console.error(e.message);
    process.exitCode = 1;
  })
  .finally(async () => {
    if (browser) await browser.close();
    for (const c of children) c.kill();
    fs.rmSync(dir, { recursive: true, force: true });
  });
