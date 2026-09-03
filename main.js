/**
 * VLink 页面制作器 —— Electron 主进程
 *
 * 这是一个纯本地应用：不联网、不上传、所有数据存在你自己的电脑上。
 */

const { app, BrowserWindow, Menu, dialog, ipcMain, shell, screen } = require('electron');
const path = require('path');
const fs = require('fs');

// 关闭硬件加速：对无独显/远程桌面/虚拟机更友好，避免启动黑屏（本应用为轻量 UI，无感知差异）
app.disableHardwareAcceleration();

// 单实例锁：防止重复启动多个窗口导致数据错乱
// （VLINK_NO_SINGLE=1 时跳过，供 CI/沙箱自动化测试使用）
const gotTheLock = process.env.VLINK_NO_SINGLE === '1' ? true : app.requestSingleInstanceLock();
if (!gotTheLock) {
  app.quit();
} else {
  app.on('second-instance', () => {
    const win = BrowserWindow.getAllWindows()[0];
    if (win) {
      if (win.isMinimized()) win.restore();
      win.focus();
    }
  });
}

let mainWindow = null;

function createWindow() {
  // 窗口尺寸自适应屏幕：笔记本小屏 / 系统缩放 125%-150% 时，
  // 写死的大窗口会超出屏幕，导致弹窗底部的按钮跑到屏幕外点不到
  const area = screen.getPrimaryDisplay().workAreaSize;   // 屏幕可用区（已扣任务栏、已考虑 DPI 缩放）
  const winW = Math.max(960, Math.min(1440, Math.floor(area.width * 0.94)));
  const winH = Math.max(600, Math.min(920, Math.floor(area.height * 0.94)));

  mainWindow = new BrowserWindow({
    width: winW,
    height: winH,
    minWidth: 960,
    minHeight: 600,
    center: true,
    title: 'VLink 页面制作器',
    backgroundColor: '#f4f5f7',
    autoHideMenuBar: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,   // 安全隔离：页面脚本拿不到 Node 能力
      nodeIntegration: false,
      sandbox: false
    }
  });

  mainWindow.loadFile(path.join(__dirname, 'src', 'index.html'));

  // 所有跳转外部网站的请求，交给系统默认浏览器，不在本应用里打开
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (url && /^https?:/.test(url)) {
      shell.openExternal(url);
      return { action: 'deny' };
    }
    return { action: 'allow' };
  });

  mainWindow.webContents.on('will-navigate', (event, url) => {
    // 只允许加载本地文件，外部一律走浏览器
    if (url && !url.startsWith('file://')) {
      event.preventDefault();
      shell.openExternal(url);
    }
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

/* ==================== 菜单 ==================== */

function buildMenu() {
  const template = [
    {
      label: '文件',
      submenu: [
        {
          label: '导出网页（Ctrl+S）',
          click: () => mainWindow && mainWindow.webContents.send('menu-export-html')
        },
        { type: 'separator' },
        {
          label: '备份数据（.json）',
          click: () => mainWindow && mainWindow.webContents.send('menu-export-json')
        },
        {
          label: '导入备份',
          click: () => mainWindow && mainWindow.webContents.send('menu-import-json')
        },
        { type: 'separator' },
        { label: '退出', role: 'quit' }
      ]
    },
    {
      label: '编辑',
      submenu: [
        { label: '撤销', role: 'undo' },
        { label: '重做', role: 'redo' },
        { type: 'separator' },
        { label: '剪切', role: 'cut' },
        { label: '复制', role: 'copy' },
        { label: '粘贴', role: 'paste' },
        { label: '全选', role: 'selectAll' }
      ]
    },
    {
      label: '视图',
      submenu: [
        { label: '重新加载', role: 'reload' },
        { label: '实际大小', role: 'resetZoom' },
        { label: '放大', role: 'zoomIn' },
        { label: '缩小', role: 'zoomOut' },
        { type: 'separator' },
        { label: '全屏切换', role: 'togglefullscreen' }
      ]
    },
    {
      label: '帮助',
      submenu: [
        {
          label: '使用说明',
          click: () => {
            dialog.showMessageBox(mainWindow, {
              type: 'info',
              title: '使用说明',
              message: '怎么用？三步：',
              detail:
                '第 1 步：改内容\n' +
                '左边「页面内容」→ 填昵称、简介、传头像；点「添加新模块」选类型（链接按钮、二维码、封面大图、轮播图、商品卡、视频、音乐、文字段落、分组标题）。\n\n' +
                '第 2 步：挑皮肤\n' +
                '左边「外观主题」→ 9 套预设点一下即换，也可细调背景、圆角、配色。\n\n' +
                '第 3 步：导出分享\n' +
                '点「导出网页」（或按 Ctrl+S）→ 选个位置保存 → 得到一个 html 文件 → 传到网上（如艾可秀 axureshow.com）就能分享。\n\n' +
                '小提示：\n' +
                '· 导出文件名建议保持 index，托管平台都要求入口文件叫这个。\n' +
                '· 「备份」导出的 .json 可随时「导入」恢复，换电脑也能带走。\n' +
                '· 所有数据只存本机，不上传任何服务器。',
              buttons: ['好']
            });
          }
        },
        {
          label: '关于',
          click: () => {
            dialog.showMessageBox(mainWindow, {
              type: 'info',
              title: '关于',
              message: 'VLink 页面制作器',
              detail:
                '版本 1.0.0\n\n' +
                '一个完全本地的聚合页制作工具。\n' +
                '功能对齐 VLink 付费版：密码保护、无限模块、自定义样式，全部免费。\n\n' +
                '所有数据只存在你这台电脑上，不会上传到任何服务器。',
              buttons: ['好']
            });
          }
        }
      ]
    }
  ];

  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

/* ==================== 文件读写（供页面调用） ==================== */

// 保存文件：弹出系统"另存为"窗口，用户自己选位置
ipcMain.handle('save-file', async (event, { defaultName, content, encoding, filters }) => {
  const win = BrowserWindow.getFocusedWindow() || mainWindow;
  const res = await dialog.showSaveDialog(win, {
    title: '保存文件',
    defaultPath: path.join(app.getPath('documents'), defaultName),
    filters: filters || [{ name: '所有文件', extensions: ['*'] }]
  });

  if (res.canceled || !res.filePath) {
    return { ok: false, canceled: true };
  }

  try {
    // base64:true 时 content 是 base64 字符串（用于保存图片等二进制文件）
    if (opts && opts.base64) {
      fs.writeFileSync(res.filePath, Buffer.from(String(content || ''), 'base64'));
    } else {
      fs.writeFileSync(res.filePath, content, (encoding || 'utf8'));
    }
    return { ok: true, path: res.filePath };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

// 打开文件：弹出系统"打开"窗口
ipcMain.handle('open-file', async (event, { filters }) => {
  const win = BrowserWindow.getFocusedWindow() || mainWindow;
  const res = await dialog.showOpenDialog(win, {
    title: '选择文件',
    properties: ['openFile'],
    filters: filters || [{ name: '所有文件', extensions: ['*'] }]
  });

  if (res.canceled || !res.filePaths || !res.filePaths.length) {
    return { ok: false, canceled: true };
  }

  try {
    const content = fs.readFileSync(res.filePaths[0], 'utf8');
    return { ok: true, path: res.filePaths[0], content };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

/* ==================== 生命周期 ==================== */

app.whenReady().then(() => {
  createWindow();
  buildMenu();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
