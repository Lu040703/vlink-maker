/**
 * 预加载脚本
 *
 * 作用：在页面和 Electron 主进程之间搭一座"受控的桥"。
 * 页面只能通过这里暴露的几个方法跟系统打交道，拿不到完整 Node 权限，
 * 这样即使页面里有脚本，也读不了你电脑上的其他文件。
 */

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('__vlinkDesktop', {
  // 标记：页面靠这个判断"我现在跑在桌面版里"
  isDesktop: true,

  /**
   * 保存文件，弹出系统"另存为"窗口
   * @returns {Promise<{ok:boolean, path?:string, canceled?:boolean, error?:string}>}
   */
  saveFile: (opts) => ipcRenderer.invoke('save-file', opts),

  /**
   * 选择并读取文件
   * @returns {Promise<{ok:boolean, path?:string, content?:string, canceled?:boolean, error?:string}>}
   */
  openFile: (opts) => ipcRenderer.invoke('open-file', opts),

  /**
   * 监听顶部菜单点击
   * @param {(action:string)=>void} cb
   */
  onMenu: (cb) => {
    const actions = ['menu-export-html', 'menu-export-json', 'menu-import-json', 'menu-help'];
    actions.forEach((a) => {
      ipcRenderer.on(a, () => cb(a));
    });
  }
});
