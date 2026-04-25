// 用 Chrome headless 把内联 SVG 渲染成 PNG (banner + icon)。
// 不依赖 npm 包,本机有 Chrome 即可。运行: node generate-assets.js
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME =
  process.env.CHROME_PATH ||
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

const OUT = __dirname;
const TMP = path.join(OUT, '.tmp');
if (!fs.existsSync(TMP)) fs.mkdirSync(TMP);

function htmlWrap(svgInline, w, h, transparent) {
  // body 给白色背景做对比保护;真正想透明的(icon)在 chrome flag 上控制
  const bg = transparent ? 'transparent' : '#0F172A';
  return `<!DOCTYPE html><html><head><meta charset="utf-8"><style>
*,html,body{margin:0;padding:0}
html,body{width:${w}px;height:${h}px;background:${bg};overflow:hidden}
svg{display:block;width:${w}px;height:${h}px}
</style></head><body>${svgInline}</body></html>`;
}

function shoot({ name, svg, w, h, transparent }) {
  const htmlPath = path.join(TMP, `${name}.html`);
  const pngPath = path.join(OUT, `${name}.png`);
  fs.writeFileSync(htmlPath, htmlWrap(svg, w, h, transparent));

  const args = [
    '--headless=new',
    '--disable-gpu',
    `--screenshot=${pngPath}`,
    `--window-size=${w},${h}`,
    '--hide-scrollbars',
    '--no-sandbox',
  ];
  if (transparent) args.push('--default-background-color=00000000');
  args.push(`file:///${htmlPath.replace(/\\/g, '/')}`);

  console.log(`[shoot] ${name}.png  ${w}x${h}  transparent=${!!transparent}`);
  execFileSync(CHROME, args, { stdio: 'inherit' });
}

// ---- ICON SVG (512x512 画布,后续 resample 到 256/128) ----
// 设计原则:
//  - 用 512x512 渲染再降采样,避开 chrome 在小尺寸 viewport 渲染 SVG 时
//    底部贝塞尔曲线丢失的怪异问题(实测 256x256 viewport 必出问题)
//  - 直接用绝对坐标手画盾牌,不用嵌套 transform/scale (同样会触发 chrome bug)
//  - 深色圆角矩形背景与 banner 统一,在浅色仓库列表上对比度更好
const ICON_SVG = `<svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#1E3A8A"/>
      <stop offset="100%" stop-color="#0F172A"/>
    </linearGradient>
  </defs>
  <rect width="512" height="512" rx="96" fill="url(#bg)"/>

  <!-- 盾牌外轮廓(在 512 坐标系里,scale 9 + offset 76) -->
  <!-- 顶尖(20,0)→(256,76)  右上肩(38,6)→(418,130)  右壁底(38,18)→(418,238) -->
  <!-- 右弧终点(20,40)→(256,436)  左侧对称 -->
  <path d="M256 76 L418 130 L418 238 C418 328 346 391 256 436 C166 391 94 328 94 238 L94 130 Z"
    stroke="#60A5FA" stroke-width="22" fill="none" stroke-linejoin="round"/>

  <!-- 中央绿圆(cx=20,cy=20,r=8 → cx=256,cy=256,r=72) -->
  <circle cx="256" cy="256" r="72" fill="#10B981"/>

  <!-- 对勾(16,20→220,256 / 19.5,23.5→276,288 / 26,15.5→334,216) -->
  <path d="M220 256 L276 288 L334 216"
    stroke="white" stroke-width="22" fill="none"
    stroke-linecap="round" stroke-linejoin="round"/>
</svg>`;

// ---- BANNER SVG ----
// 关键:SVG 用固定 viewBox="0 0 1544 500",所有坐标按这套画布走;
// 输出 772x250 时 css 给 svg 设小尺寸,浏览器会等比缩放,不会有超框。
function bannerSvg() {
  return `<svg viewBox="0 0 1544 500" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#0F172A"/>
      <stop offset="100%" stop-color="#1E293B"/>
    </linearGradient>
  </defs>
  <rect width="1544" height="500" fill="url(#bg)"/>

  <!-- 右侧大盾牌装饰 -->
  <g opacity="0.08" transform="translate(1180, 60) scale(9)">
    <path d="M20 0 L38 6 V18 C38 28 30 35 20 40 C10 35 2 28 2 18 V6 Z" fill="#3B82F6"/>
  </g>

  <!-- 左侧主 logo (整体上移,腾出 40px 给底部 badges) -->
  <g transform="translate(80, 145) scale(5)">
    <path d="M20 0 L38 6 V18 C38 28 30 35 20 40 C10 35 2 28 2 18 V6 Z"
      stroke="#3B82F6" stroke-width="2.5" fill="none"/>
    <circle cx="20" cy="20" r="8" fill="#10B981"/>
    <path d="M16 20 L19.5 23.5 L26 15.5"
      stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
  </g>

  <!-- 主标题(两行) -->
  <text x="350" y="170" font-family="Arial, Helvetica, sans-serif"
    font-size="60" font-weight="700" fill="#F8FAFC">Accept USDT &amp; USDC.</text>
  <text x="350" y="248" font-family="Arial, Helvetica, sans-serif"
    font-size="60" font-weight="700" fill="#3B82F6">Settle in stables.</text>

  <!-- 副标题 -->
  <text x="350" y="298" font-family="Arial, Helvetica, sans-serif"
    font-size="24" fill="#94A3B8">Crypto payment gateway for WooCommerce. No KYC, no volatility.</text>

  <!-- 链 badges (y 从 380 上移到 350,底部留 100px 安全边距) -->
  <g transform="translate(350, 350)">
    ${badge(0,    120, 'TRON',    '#3B82F6', '#93C5FD')}
    ${badge(140,  100, 'BSC',     '#10B981', '#6EE7B7')}
    ${badge(260,  130, 'Polygon', '#8B5CF6', '#C4B5FD')}
    ${badge(410,  100, 'USDT',    '#EC4899', '#F9A8D4')}
    ${badge(530,  100, 'USDC',    '#22D3EE', '#67E8F9')}
  </g>
</svg>`;
}

function badge(x, w, label, stroke, text) {
  // 圆角胶囊:外描边 + 半透明填充 + 居中文字
  return `<rect x="${x}" y="0" width="${w}" height="44" rx="22"
      fill="${stroke}22" stroke="${stroke}" stroke-width="1.5"/>
    <text x="${x + w / 2}" y="29" font-family="Arial,sans-serif"
      font-size="16" font-weight="600" fill="${text}" text-anchor="middle">${label}</text>`;
}

// ---- 执行 ----
// 用 chrome 渲染"大"版本,小版本由 GDI+ 高质量重采样得到。
// 这样:1) 避开 chrome 缩放 SVG 时丢内容的 bug;2) 大小两版视觉绝对一致。
// icon 用 512x512 渲染,再降采样到 256/128 (chrome 在 256x256 直接渲染会丢盾牌底部)
shoot({ name: 'icon-512-tmp',    svg: ICON_SVG,   w: 512,  h: 512, transparent: false });
shoot({ name: 'banner-1544x500', svg: bannerSvg(), w: 1544, h: 500, transparent: false });

function resample(srcName, dstName, dstW, dstH) {
  const src = path.join(OUT, srcName).replace(/\\/g, '\\\\');
  const dst = path.join(OUT, dstName).replace(/\\/g, '\\\\');
  const ps = `
Add-Type -AssemblyName System.Drawing
$big = [System.Drawing.Image]::FromFile('${src}')
$small = New-Object System.Drawing.Bitmap ${dstW}, ${dstH}
$g = [System.Drawing.Graphics]::FromImage($small)
$g.InterpolationMode  = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$g.SmoothingMode      = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
$g.PixelOffsetMode    = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
$g.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
$g.DrawImage($big, 0, 0, ${dstW}, ${dstH})
$small.Save('${dst}', [System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose(); $small.Dispose(); $big.Dispose()
`;
  console.log(`[resample] ${dstName}  (downsampled from ${srcName})`);
  execFileSync('powershell.exe', ['-NoProfile', '-Command', ps], { stdio: 'inherit' });
}

resample('banner-1544x500.png', 'banner-772x250.png', 772, 250);
resample('icon-512-tmp.png',    'icon-256x256.png',   256, 256);
resample('icon-512-tmp.png',    'icon-128x128.png',   128, 128);

// 清掉中间产物,只留 wp.org 用的 4 张
fs.unlinkSync(path.join(OUT, 'icon-512-tmp.png'));

console.log('\nDone. Output dir:', OUT);
