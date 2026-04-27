/**
 * ChainPay self-test runner (admin)
 *
 * 在 WC → Settings → Payments → ChainPay 页, 按下「Run integration test」
 * 后顺序执行: 创建 sandbox 订单 → 模拟付款 → 轮询本站 webhook 是否收到.
 *
 * 安全要点: 所有 ajax 调用都带 nonce + capability 守卫, 请求体只接受白名单字段.
 *
 * 设计参数:
 *  POLL_INTERVAL_MS: 1500    每 1.5 秒查一次 webhook 状态
 *  POLL_MAX_ATTEMPTS: 24     最多 36 秒, 给 webhook 投递 + 网络抖动留余量
 */
(function ($) {
    'use strict';

    var POLL_INTERVAL_MS = 1500;
    var POLL_MAX_ATTEMPTS = 24;

    function t(key) {
        return (window.chainpayTest && window.chainpayTest.i18n && window.chainpayTest.i18n[key]) || key;
    }

    /**
     * 渲染单个步骤行 (在结果面板里追加).
     * @param {string} key 唯一标识 (用于后续 update)
     * @param {string} state pending | running | success | failed
     * @param {string} text 文案
     */
    function renderStep(key, state, text) {
        var $box = $('#chainpay-test-results');
        var $row = $box.find('[data-step="' + key + '"]');
        var stateMap = {
            pending: { color: '#94A3B8', icon: '○' },
            running: { color: '#3B82F6', icon: '⟳' },
            success: { color: '#10B981', icon: '✓' },
            failed:  { color: '#EF4444', icon: '✕' }
        };
        var conf = stateMap[state] || stateMap.pending;
        var html = '<div data-step="' + key + '" '
            + 'style="display:flex;align-items:flex-start;gap:8px;padding:6px 0;border-bottom:1px solid #F1F5F9;">'
            + '<span style="color:' + conf.color + ';font-weight:700;font-size:16px;line-height:1.3;width:20px;'
            + (state === 'running' ? 'animation:chainpay-spin 1s linear infinite;display:inline-block;' : '')
            + '">' + conf.icon + '</span>'
            + '<div style="flex:1;color:#334155;line-height:1.5;">' + $('<i/>').text(text).html() + '</div>'
            + '</div>';
        if ($row.length) {
            $row.replaceWith(html);
        } else {
            $box.append(html);
        }
    }

    /**
     * 重置结果面板 (每次点击按钮重新开始)
     */
    function reset() {
        $('#chainpay-test-results').empty().css({
            background: '#F8FAFC',
            border: '1px solid #E2E8F0',
            borderRadius: 8,
            padding: '12px 16px'
        });
    }

    function ajax(action, data) {
        return $.post(window.chainpayTest.ajaxUrl, $.extend({
            action: action,
            nonce: window.chainpayTest.nonce
        }, data || {}));
    }

    /**
     * 第三步: 轮询 webhook 是否到达
     */
    function pollWebhook(merchantOrderNo, attempt) {
        attempt = attempt || 1;
        renderStep('check', 'running',
            t('Waiting for webhook to reach your site') +
            ' (' + attempt + '/' + POLL_MAX_ATTEMPTS + ')');

        return ajax('chainpay_test_check', { merchant_order_no: merchantOrderNo })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    renderStep('check', 'failed',
                        t('Server error checking webhook') + ': ' +
                        ((resp && resp.data && resp.data.message) || 'unknown'));
                    return false;
                }
                if (resp.data && resp.data.received) {
                    renderStep('check', 'success',
                        t('Webhook received and verified') +
                        ' (event=' + (resp.data.event || 'order.paid') +
                        ', mode=' + (resp.data.order_mode || 'sandbox') +
                        ', took ' + resp.data.waited_seconds + 's)');
                    return true;
                }
                if (attempt >= POLL_MAX_ATTEMPTS) {
                    renderStep('check', 'failed', t('Webhook timeout — your site did NOT receive the callback within 36s. Common causes:') +
                        '\n  • ' + t('Site is behind a firewall / NAT and not publicly reachable') +
                        '\n  • ' + t('Webhook secret mismatch (server returned 401, payload dropped)') +
                        '\n  • ' + t('home_url() returns an internal URL (e.g. localhost) — set Site Address to a public domain'));
                    return false;
                }
                return new Promise(function (resolve) {
                    setTimeout(function () {
                        pollWebhook(merchantOrderNo, attempt + 1).then(resolve);
                    }, POLL_INTERVAL_MS);
                });
            });
    }

    function runTest() {
        reset();
        var $btn = $('#chainpay-run-test');
        $btn.prop('disabled', true);

        renderStep('create', 'running', t('Creating sandbox order on ChainPay'));

        ajax('chainpay_test_create')
            .then(function (resp) {
                if (!resp || !resp.success) {
                    renderStep('create', 'failed',
                        t('Failed to create sandbox order') + ': ' +
                        ((resp && resp.data && resp.data.message) || 'unknown'));
                    throw new Error('create_failed');
                }
                renderStep('create', 'success',
                    t('Sandbox order created') +
                    ' (order_no=' + resp.data.order_no + ', mode=' + resp.data.order_mode + ')');

                renderStep('simulate', 'running', t('Triggering simulate-paid'));
                return ajax('chainpay_test_simulate', { order_no: resp.data.order_no })
                    .then(function (sim) {
                        if (!sim || !sim.success) {
                            renderStep('simulate', 'failed',
                                t('Failed to simulate paid') + ': ' +
                                ((sim && sim.data && sim.data.message) || 'unknown'));
                            throw new Error('simulate_failed');
                        }
                        renderStep('simulate', 'success', t('simulate-paid OK, ChainPay queued the webhook'));
                        return resp.data.merchant_order_no;
                    });
            })
            .then(function (moc) {
                renderStep('check', 'pending', t('Waiting for webhook to reach your site'));
                return pollWebhook(moc, 1);
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    }

    $(function () {
        $('#chainpay-run-test').on('click', runTest);

        // 注入旋转动画 keyframes (只一次)
        if (!document.getElementById('chainpay-test-keyframes')) {
            var style = document.createElement('style');
            style.id = 'chainpay-test-keyframes';
            style.innerHTML = '@keyframes chainpay-spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }
    });
})(jQuery);
