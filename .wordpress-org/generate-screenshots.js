// 生成 screenshot-3.png:ChainPay 收银台真实视觉的 mockup
// 不依赖前端 dev server / 后端 API,直接 standalone HTML + chrome headless 截图。
// 视觉照搬 frontend/src/pages/pay/Cashier.jsx 的样式,二维码用 backend qrcode 包生成。
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME =
  process.env.CHROME_PATH ||
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const OUT = __dirname;
const TMP = path.join(OUT, '.tmp');
if (!fs.existsSync(TMP)) fs.mkdirSync(TMP);

// 借用 backend 已装的 qrcode 包,免去本目录 npm install
// __dirname = packages/wordpress-plugin/.wordpress-org → 3 层 .. 到仓库根
const QRCode = require(path.join(
  __dirname, '..', '..', '..',
  'backend', 'node_modules', 'qrcode',
));

// ===== Mock 订单数据 =====
const order = {
  amount: '100.00',
  token: 'USDT',
  chain: 'BSC',
  isEvm: true,
  address: '0x71eA181B9b713DAB16624500940a15625f0f0066',
  orderNo: 'CP202604261203AB7C',
  countdownText: '14:32',
};

(async () => {
  // 真二维码(EIP-681 风格深链,扫码自动填金额)
  const qrContent =
    `ethereum:0x55d398326f99059fF775485246999027B3197955@56/transfer` +
    `?address=${order.address}&uint256=100000000000000000000`;
  const qrSvg = await QRCode.toString(qrContent, {
    type: 'svg',
    margin: 2,
    width: 160,
    errorCorrectionLevel: 'M',
  });
  // 把 qrcode 库返回的 <?xml ...?> 头去掉,只留 <svg>
  const qrInline = qrSvg.replace(/<\?xml[^>]*\?>/, '').trim();

  const html = `<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
  *,html,body { margin:0; padding:0; box-sizing:border-box; -webkit-font-smoothing:antialiased; }
  html { width: 1280px; height: 1024px; background: #0F172A; }
  body {
    width: 1280px; height: 1024px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(135deg, #0F172A 0%, #1E293B 50%, #0F172A 100%);
    display: flex; align-items: flex-start; justify-content: center;
    padding-top: 50px;
    overflow: hidden;
    position: relative;
  }
  .aurora {
    position: absolute; inset: -50%;
    background:
      radial-gradient(circle at 30% 20%, rgba(10,132,255,.10) 0%, transparent 40%),
      radial-gradient(circle at 70% 80%, rgba(48,209,88,.08) 0%, transparent 40%);
    pointer-events: none;
  }

  .card {
    position: relative; z-index: 1;
    width: 420px;
    background: #fff;
    border-radius: 28px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.45);
    border: 1px solid rgba(255,255,255,0.1);
    overflow: hidden;
  }

  .header {
    text-align: center;
    padding: 22px 24px 18px;
    border-bottom: 1px solid #E8EAED;
  }
  .brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 14px; }
  .brand-logo { width: 28px; height: 28px; }
  .brand-name { font-size: 18px; font-weight: 700; color: #0F172A; }
  .status-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 100px;
    background: #FFF8E8; color: #FF9F0A;
    font-weight: 600; font-size: 13px;
  }
  .status-icon { width: 14px; height: 14px; }
  .status-desc { margin-top: 8px; font-size: 12px; color: #94A3B8; }

  .amount {
    text-align: center; padding: 18px 24px; background: #FAFBFC;
  }
  .amount-label { font-size: 13px; color: #64748B; margin-bottom: 4px; }
  .amount-value {
    font-size: 40px; font-weight: 800; color: #0F172A;
    display: flex; align-items: baseline; justify-content: center; gap: 8px;
  }
  .amount-token { font-size: 18px; font-weight: 500; color: #64748B; }
  .chain-tag {
    margin-top: 8px;
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 6px;
    background: #E8F4FF; color: #0A84FF;
    font-size: 12px; font-weight: 500;
  }
  .evm-badge {
    margin-left: 4px; padding: 1px 6px; border-radius: 4px;
    background: #F3E8FF; color: #722ED1;
    font-size: 10px; font-weight: 500;
  }
  .warning {
    margin-top: 12px; padding: 6px 10px;
    background: #FFFBE6; border: 1px solid #FFE58F;
    border-radius: 4px; font-size: 11px; color: #614700;
    text-align: left;
    display: flex; gap: 6px; align-items: flex-start;
  }
  .warning-icon { color: #FAAD14; flex-shrink: 0; margin-top: 1px; }

  .qr-section { padding: 18px 24px; text-align: center; }
  .qr-box {
    padding: 16px; background: #fff; border-radius: 18px;
    display: inline-block; border: 2px solid #E8EAED;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
  }
  .qr-box svg { display: block; }
  .qr-tip { font-size: 12px; color: #64748B; line-height: 1.6; margin-top: 10px; }
  .qr-tip strong { font-weight: 700; color: #0F172A; }
  .qr-tip-sub { font-size: 11px; color: #94A3B8; }

  .address-box {
    margin: 0 24px;
    background: #F4F6F8; padding: 12px 14px; border-radius: 12px;
  }
  .address-label {
    font-size: 11px; color: #94A3B8; margin-bottom: 6px;
    text-transform: uppercase; letter-spacing: .5px;
  }
  .address-value {
    word-break: break-all; font-size: 12px;
    font-family: 'SFMono-Regular','Consolas','Menlo',monospace;
    color: #0F172A; line-height: 1.6;
  }
  .copy-btn {
    margin-top: 12px;
    background: #0A84FF; color: #fff; border: none;
    padding: 10px 16px; border-radius: 10px;
    font-size: 14px; font-weight: 500; width: 100%;
    display: flex; align-items: center; justify-content: center; gap: 6px;
  }

  .countdown {
    margin: 12px 24px 22px;
    padding: 12px; background: #F4F6F8; border-radius: 12px;
    text-align: center;
  }
  .countdown-label { font-size: 11px; color: #94A3B8; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px; }
  .countdown-value {
    font-size: 24px; font-weight: 700; color: #FF9F0A;
    font-family: 'SFMono-Regular','Consolas','Menlo',monospace;
  }
</style></head><body>
  <div class="aurora"></div>
  <div class="card">

    <div class="header">
      <div class="brand">
        <svg class="brand-logo" viewBox="0 0 40 40">
          <path d="M20 0 L38 6 V18 C38 28 30 35 20 40 C10 35 2 28 2 18 V6 Z"
            stroke="#3B82F6" stroke-width="2.5" fill="none"/>
          <circle cx="20" cy="20" r="8" fill="#10B981"/>
          <path d="M16 20 L19.5 23.5 L26 15.5"
            stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span class="brand-name">ChainPay</span>
      </div>
      <div class="status-pill">
        <svg class="status-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="12" cy="12" r="9"/>
          <path d="M12 7 V12 L15 14" stroke-linecap="round"/>
        </svg>
        <span>Waiting for Payment</span>
      </div>
      <div class="status-desc">Please complete payment before timer ends</div>
    </div>

    <div class="amount">
      <div class="amount-label">Amount Due</div>
      <div class="amount-value">
        <span>${order.amount}</span>
        <span class="amount-token">${order.token}</span>
      </div>
      <div class="chain-tag">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="6" width="18" height="13" rx="2"/>
          <path d="M3 10 H21" />
        </svg>
        ${order.chain}
        <span class="evm-badge">EVM</span>
      </div>
      <div class="warning">
        <svg class="warning-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
          <path d="M12 2 L22 20 H2 Z" /><circle cx="12" cy="16" r="1.2" fill="#fff"/>
          <rect x="11.2" y="9" width="1.6" height="5" fill="#fff"/>
        </svg>
        <span>Send only on <strong>${order.chain}</strong> network. Other networks will lose funds.</span>
      </div>
    </div>

    <div class="qr-section">
      <div class="qr-box">${qrInline}</div>
      <div class="qr-tip">
        Wallet auto-fills <strong>${order.amount} ${order.token}</strong> after scan.
        <div class="qr-tip-sub">MetaMask · OKX · imToken · Trust Wallet supported</div>
      </div>
    </div>

    <div class="address-box">
      <div class="address-label">Receiving Address</div>
      <div class="address-value">${order.address}</div>
      <button class="copy-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="8" y="8" width="12" height="12" rx="2"/>
          <path d="M16 4 H6 a2 2 0 0 0 -2 2 V16"/>
        </svg>
        Copy Address
      </button>
    </div>

    <div class="countdown">
      <div class="countdown-label">Time Remaining</div>
      <div class="countdown-value">${order.countdownText}</div>
    </div>

  </div>
</body></html>`;

  const htmlPath = path.join(TMP, 'screenshot-1.html');
  const pngPath = path.join(OUT, 'screenshot-1.png');
  fs.writeFileSync(htmlPath, html);

  console.log('[shoot] screenshot-1.png  1280x1024');
  execFileSync(CHROME, [
    '--headless=new',
    '--disable-gpu',
    `--screenshot=${pngPath}`,
    '--window-size=1280,1024',
    '--hide-scrollbars',
    '--no-sandbox',
    `file:///${htmlPath.replace(/\\/g, '/')}`,
  ], { stdio: 'inherit' });

  console.log('Done.');
})().catch(err => { console.error(err); process.exit(1); });
