/**
 * v1.0.7 自动化功能测试：自建统计 + 外链自动改写为 go.php
 * 用法: electron test-v107.js
 * 输出: TEST_RESULTS {...} 一行 JSON
 */
const { app, BrowserWindow } = require('electron');
const http = require('http');
const path = require('path');

app.disableHardwareAcceleration();
app.commandLine.appendSwitch('disable-gpu');
app.commandLine.appendSwitch('no-sandbox');

process.env.VLINK_TEST = '1';
require('./main.js');

let served = '<html><body>empty</body></html>';
const srv = http.createServer((req, res) => {
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.end(served);
});

app.whenReady().then(async () => {
  const out = {};
  try {
    await new Promise(r => srv.listen(8123, '127.0.0.1', r));

    /* ---------- 窗口1：工具本体，生成导出页面 ---------- */
    const win = new BrowserWindow({
      show: false,
      webPreferences: {
        preload: path.join(__dirname, 'preload.js'),
        contextIsolation: true, nodeIntegration: false, sandbox: false
      }
    });
    await win.loadFile(path.join(__dirname, 'src', 'index.html'));

    const gen = await win.webContents.executeJavaScript(`(async () => {
      const o = {};
      try {
        /* 基线：一个外链模块 */
        state.blocks.length = 0;
        state.blocks.push({ id: 'lk1', type: 'link', title: '测试链接A', url: 'https://a.example.com/x', iconType: 'none' });
        state.blocks.push({ id: 'lk2', type: 'link', title: '测试链接B', url: 'http://b.example.net/y', iconType: 'none' });

        /* A. 自建统计，地址留空（同源部署） */
        state.settings.statsOn = true;
        state.settings.statsType = 'own';
        state.settings.statsHost = '';
        o.a = buildStatsHTML();
        o.aHasPixel = o.a.indexOf('src="/hit.php"') > -1;
        o.aHasGoScript = o.a.indexOf('/go.php?u=') > -1;
        o.aWillExport = statsWillExport();

        /* B. 自建统计，填了服务器地址 */
        state.settings.statsHost = 'https://supersubsidy.top';
        o.b = buildStatsHTML();
        o.bHasPixel = o.b.indexOf('https://supersubsidy.top/hit.php') > -1;
        o.bHasHostGo = o.b.indexOf('B="https://supersubsidy.top"') > -1;

        /* C. 关掉统计 → 不该有任何统计代码 */
        state.settings.statsOn = false;
        o.c = buildStatsHTML();
        o.cClean = o.c === '' || o.c.indexOf('hit.php') === -1;

        /* D. 完整页面（带自建统计，同源） */
        state.settings.statsOn = true;
        state.settings.statsHost = '';
        o.page = buildPageHTML();
        o.pageHasStat = o.page.indexOf('id="vl-stat"') > -1;
        o.pageKB = Math.round(o.page.length / 1024);

        /* E. 回归：图片分离导出仍正常 */
        const cv = document.createElement('canvas');
        cv.width = 1200; cv.height = 1600;
        const cx = cv.getContext('2d');
        cx.fillStyle = '#e74c3c'; cx.fillRect(0, 0, 1200, 1600);
        for (let i = 0; i < 2000; i++) {
          cx.fillStyle = 'rgba(255,255,255,' + (Math.random() * 0.6).toFixed(2) + ')';
          cx.fillRect(Math.random() * 1200, Math.random() * 1600, 50, 50);
        }
        const blob = await new Promise(r => cv.toBlob(r, 'image/jpeg', 0.95));
        const file = new File([blob], 't.jpg', { type: 'image/jpeg' });
        const st = await new Promise(res => compressImage(file, res));
        state.blocks.push({ id: 'cv1', type: 'cover', title: '封面', url: 'https://c.example.com', image: st });
        const sp = buildSplitParts();
        o.split = { htmlKB: Math.round(sp.html.length / 1024), images: sp.images.length, noBase64: sp.html.indexOf('data:image') === -1 };
        o.splitPass = sp.images.length >= 1 && o.split.noBase64;
        /* 分离导出也要带统计代码 */
        o.splitHasStat = sp.html.indexOf('id="vl-stat"') > -1;
      } catch (e) { o.error = String(e && e.stack || e); }
      return o;
    })()`);
    Object.assign(out, gen);
    if (gen.error) throw new Error(gen.error);

    /* ---------- 窗口2：真实 http 环境加载导出页，验证外链被改写 ---------- */
    served = gen.page;
    const win2 = new BrowserWindow({
      show: false,
      webPreferences: { contextIsolation: true, nodeIntegration: false, sandbox: false }
    });
    await win2.loadURL('http://127.0.0.1:8123/p.html');
    await new Promise(r => setTimeout(r, 600));

    const live = await win2.webContents.executeJavaScript(`(function(){
      var as = document.querySelectorAll('a[href]');
      var list = [];
      for (var i = 0; i < as.length; i++) list.push(as[i].getAttribute('href'));
      return { total: as.length, hrefs: list.slice(0, 8), protocol: location.protocol };
    })()`);
    const rewritten = live.hrefs.filter(h => h && h.indexOf('/go.php?u=') > -1);
    out.live = {
      protocol: live.protocol,
      linksTotal: live.total,
      rewrittenCount: rewritten.length,
      sample: rewritten.slice(0, 3),
      raw: live.hrefs.slice(0, 4)
    };
    out.livePass = live.total >= 2 && rewritten.length >= 2 && live.protocol === 'http:';
  } catch (e) {
    out.fatal = String(e && e.stack || e);
  }

  console.log('TEST_RESULTS ' + JSON.stringify(out));
  try { srv.close(); } catch (e) {}
  app.exit(out.fatal ? 1 : 0);
});
