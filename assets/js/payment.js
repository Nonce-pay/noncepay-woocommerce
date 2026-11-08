/* TrezorPay payment page: copy buttons, quote countdown and live status. */
;(function () {
  'use strict'

  var root = document.getElementById('trezorpay-payment')
  if (!root) {
    return
  }

  var __ =
    window.wp && window.wp.i18n
      ? window.wp.i18n.__
      : function (text) {
          return text
        }
  var config = JSON.parse(root.getAttribute('data-config') || '{}')
  var data = config.data || {}
  var clockOffset = Date.now() / 1000 - (config.now || Date.now() / 1000)

  var STATUS = {
    waiting: __('Waiting for your payment', 'trezorpay-for-woocommerce'),
    seen: __('Payment seen, waiting for confirmations', 'trezorpay-for-woocommerce'),
    partial: __('Partial payment received', 'trezorpay-for-woocommerce'),
    paid: __('Payment confirmed. Thank you!', 'trezorpay-for-woocommerce'),
    expired: __('This quote has expired', 'trezorpay-for-woocommerce'),
    cancelled: __('This order is cancelled', 'trezorpay-for-woocommerce'),
  }

  function el(name) {
    return root.querySelector('[data-trezorpay="' + name + '"]')
  }

  function render() {
    root.setAttribute('data-state', data.state)
    el('status').textContent = STATUS[data.state] || ''
    var partial = 'partial' === data.state
    el('amount-label').textContent = partial
      ? __('Amount still to send', 'trezorpay-for-woocommerce')
      : __('Amount to send', 'trezorpay-for-woocommerce')
    el('amount').textContent = partial ? data.remaining : data.amount
    el('address').textContent = data.address
    var qr = el('qr')
    if (qr && data.qr) {
      qr.src = data.qr
    }
    el('requote').hidden = 'expired' !== data.state
    tick()
  }

  function tick() {
    var timer = el('timer')
    if ('waiting' !== data.state) {
      timer.textContent =
        'expired' === data.state
          ? __(
              'The exchange rate is no longer guaranteed. Get a new quote before paying.',
              'trezorpay-for-woocommerce'
            )
          : ''
      return
    }
    var left = Math.round(data.expires_at - (Date.now() / 1000 - clockOffset))
    if (left <= 0) {
      data.state = 'expired'
      render()
      return
    }
    var minutes = Math.floor(left / 60)
    var seconds = left % 60
    /* translators: %s: remaining time as mm:ss */
    timer.textContent = __('Rate guaranteed for %s', 'trezorpay-for-woocommerce').replace(
      '%s',
      minutes + ':' + (seconds < 10 ? '0' : '') + seconds
    )
  }

  function url(base, extra) {
    var params =
      'order_id=' +
      encodeURIComponent(config.orderId) +
      '&key=' +
      encodeURIComponent(config.key) +
      (extra || '')
    return base + (base.indexOf('?') === -1 ? '?' : '&') + params
  }

  function poll() {
    if ('paid' === data.state || 'cancelled' === data.state) {
      return
    }
    fetch(url(config.statusUrl, '&_=' + Date.now()), { credentials: 'omit', cache: 'no-store' })
      .then(function (response) {
        return response.ok ? response.json() : null
      })
      .then(function (state) {
        if (state && state.state) {
          Object.assign(data, state)
          render()
        }
      })
      .catch(function () {})
      .then(function () {
        if ('paid' !== data.state && 'cancelled' !== data.state) {
          window.setTimeout(poll, 10000)
        }
      })
  }

  function copy(button) {
    var text = el(button.getAttribute('data-copy')).textContent.trim()
    var done = function () {
      var label = button.textContent
      button.textContent = __('Copied', 'trezorpay-for-woocommerce')
      window.setTimeout(function () {
        button.textContent = label
      }, 1500)
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done)
      return
    }
    var area = document.createElement('textarea')
    area.value = text
    area.setAttribute('readonly', '')
    area.style.position = 'absolute'
    area.style.left = '-9999px'
    document.body.appendChild(area)
    area.select()
    try {
      document.execCommand('copy')
      done()
    } catch (e) {}
    document.body.removeChild(area)
  }

  function requote(button) {
    button.disabled = true
    fetch(url(config.requoteUrl), { method: 'POST', credentials: 'omit' })
      .then(function (response) {
        return response.json().then(function (body) {
          return { ok: response.ok, body: body }
        })
      })
      .then(function (result) {
        if (result.ok) {
          Object.assign(data, result.body)
          render()
        } else {
          el('timer').textContent = (result.body && result.body.message) || ''
        }
      })
      .catch(function () {})
      .then(function () {
        button.disabled = false
      })
  }

  root.addEventListener('click', function (event) {
    var button = event.target.closest('button')
    if (!button) {
      return
    }
    if (button.hasAttribute('data-copy')) {
      copy(button)
    } else if ('requote' === button.getAttribute('data-trezorpay')) {
      requote(button)
    }
  })

  render()
  window.setInterval(tick, 1000)
  window.setTimeout(poll, 10000)
})()
