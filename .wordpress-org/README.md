# wp.org 资产 (banner / icon / screenshot)

这个目录存的是上传到 **WordPress.org 插件市场** 的视觉资产,
**不打进 plugin zip**, 上传渠道是 SVN 仓库的 `assets/` 目录。

## 目录布局

```
packages/wordpress-plugin/
├── .wordpress-org/              ← 这里 (源文件 + 输出 PNG)
│   ├── generate-assets.js       ← banner / icon 生成脚本
│   ├── generate-screenshots.js  ← screenshot 生成脚本
│   ├── banner-1544x500.png      ← wp.org 高分屏 banner
│   ├── banner-772x250.png       ← wp.org 标准 banner
│   ├── icon-256x256.png         ← wp.org 高分屏 icon
│   ├── icon-128x128.png         ← wp.org 标准 icon
│   └── screenshot-1.png         ← readme.txt Screenshots 段对应
├── chainpay-for-woocommerce/    ← plugin 实际代码 (打 zip 用)
└── chainpay-for-woocommerce-0.1.0.zip
```

## 重新生成

需要本机有 Chrome (`C:\Program Files\Google\Chrome\Application\chrome.exe`):

```bash
cd packages/wordpress-plugin/.wordpress-org
node generate-assets.js       # 出 banner + icon (4 张)
node generate-screenshots.js  # 出 screenshot-1.png
```

`generate-screenshots.js` 借用了 `backend/node_modules/qrcode` 生成真二维码,
所以跑前确保 `cd backend && npm install` 已经执行过。

## 上传到 WordPress.org SVN

WordPress.org 给每个 plugin 一个 SVN 仓库:

```
https://plugins.svn.wordpress.org/chainpay-for-woocommerce/
├── trunk/        ← plugin 当前开发版代码
├── tags/0.1.0/   ← 发布版本快照
└── assets/       ← banner / icon / screenshot (本目录的内容)
```

首次上传:

```bash
svn co https://plugins.svn.wordpress.org/chainpay-for-woocommerce my-svn
cp .wordpress-org/*.png my-svn/assets/
cd my-svn
svn add assets/*
svn ci -m "Add wp.org assets" --username YOUR_WP_ORG_USERNAME
```

更新单个文件:

```bash
cd my-svn
svn up assets
cp ../.wordpress-org/banner-1544x500.png assets/
svn ci assets/banner-1544x500.png -m "Update banner"
```

> 资产更新**立即生效**, 不需要等 plugin 审核。

## 文件命名规范 (wp.org 强制)

| 用途 | 标准版 | 高分屏 (Retina) |
|---|---|---|
| Banner | `banner-772x250.png` | `banner-1544x500.png` |
| Icon | `icon-128x128.png` | `icon-256x256.png` |
| Screenshot | `screenshot-N.png` | (不需要) |

screenshot 编号从 1 连续递增, 必须和 `readme.txt` 的
`== Screenshots ==` 段条目顺序一致。
