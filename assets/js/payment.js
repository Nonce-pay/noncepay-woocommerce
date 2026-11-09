/* NoncePay payment page: copy buttons, quote countdown and live status. */
;(function () {
  'use strict'

  var root = document.getElementById('noncepay-payment')
  if (!root) {
    return
  }

  var __ =
    window.wp && window.wp.i18n
      ? window.wp.i18n.__
      : function (text) {
          return text
        }
  var sprintf = window.wp && window.wp.i18n ? window.wp.i18n.sprintf : null
  var config = JSON.parse(root.getAttribute('data-config') || '{}')
  var data = config.data || {}
  var clockOffset = Date.now() / 1000 - (config.now || Date.now() / 1000)

  var STATUS = {
    waiting: __('Waiting for your payment', 'noncepay-for-woocommerce'),
    seen: __('Payment seen, waiting for confirmations', 'noncepay-for-woocommerce'),
    partial: __('Partial payment received', 'noncepay-for-woocommerce'),
    paid: __('Payment confirmed. Thank you!', 'noncepay-for-woocommerce'),
    expired: __('This quote has expired', 'noncepay-for-woocommerce'),
    cancelled: __('This order is cancelled', 'noncepay-for-woocommerce'),
  }

  function el(name) {
    return root.querySelector('[data-noncepay="' + name + '"]')
  }

  function render() {
    root.setAttribute('data-state', data.state)
    el('status').textContent = STATUS[data.state] || ''
    var partial = 'partial' === data.state
    el('amount-label').textContent = partial
      ? __('Amount still to send', 'noncepay-for-woocommerce')
      : __('Amount to send', 'noncepay-for-woocommerce')
    el('amount').textContent = data.to_send || (partial ? data.remaining : data.amount)
    var note = el('minimum-note')
    note.hidden = !data.below_minimum
    if (data.below_minimum && sprintf) {
      note.textContent = sprintf(
        /* translators: 1: amount still missing, 2: minimum payment, 3: coin symbol */
        __(
          'Only %1$s %3$s is missing, but payments below %2$s %3$s cannot be processed. Send at least %2$s %3$s: the extra is noted on your order.',
          'noncepay-for-woocommerce'
        ),
        data.remaining,
        data.minimum,
        data.symbol
      )
    }
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
              'noncepay-for-woocommerce'
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
    timer.textContent = __('Rate guaranteed for %s', 'noncepay-for-woocommerce').replace(
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
      button.textContent = __('Copied', 'noncepay-for-woocommerce')
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
    } else if ('requote' === button.getAttribute('data-noncepay')) {
      requote(button)
    }
  })

  render()
  window.setInterval(tick, 1000)
  window.setTimeout(poll, 10000)
})()
