/* NoncePay payment method for the WooCommerce Checkout block. Plain script, no build step. */
;(function () {
  'use strict'

  var registry = window.wc && window.wc.wcBlocksRegistry
  var wcSettings = window.wc && window.wc.wcSettings
  if (!registry || !wcSettings || !window.wp || !window.wp.element) {
    return
  }

  var el = window.wp.element.createElement
  var useState = window.wp.element.useState
  var useEffect = window.wp.element.useEffect
  var decode = window.wp.htmlEntities
    ? window.wp.htmlEntities.decodeEntities
    : function (s) {
        return s
      }
  var __ = window.wp.i18n
    ? window.wp.i18n.__
    : function (s) {
        return s
      }

  var settings = wcSettings.getSetting('noncepay_data', {})
  var coins = settings.coins || []
  var title = decode(settings.title || __('Crypto', 'noncepay-for-woocommerce'))

  function Content(props) {
    var initial = coins.length ? coins[0].value : ''
    var state = useState(initial)
    var coin = state[0]
    var setCoin = state[1]
    var registration = props.eventRegistration || {}
    var onSetup = registration.onPaymentSetup || registration.onPaymentProcessing
    var responses = props.emitResponse && props.emitResponse.responseTypes

    useEffect(
      function () {
        if (!onSetup) {
          return undefined
        }
        return onSetup(function () {
          if (!coin) {
            return {
              type: responses.ERROR,
              message: __(
                'Please choose a cryptocurrency to pay with.',
                'noncepay-for-woocommerce'
              ),
            }
          }
          return {
            type: responses.SUCCESS,
            meta: { paymentMethodData: { noncepay_coin: coin } },
          }
        })
      },
      [onSetup, coin]
    )

    return el(
      'div',
      { className: 'noncepay-blocks' },
      settings.description ? el('p', null, decode(settings.description)) : null,
      el(
        'label',
        { htmlFor: 'noncepay-coin', style: { display: 'block', marginBottom: '4px' } },
        __('Pay with', 'noncepay-for-woocommerce')
      ),
      el(
        'select',
        {
          id: 'noncepay-coin',
          value: coin,
          onChange: function (event) {
            setCoin(event.target.value)
          },
          style: { width: '100%', padding: '8px', minHeight: '44px' },
        },
        coins.map(function (option) {
          return el('option', { key: option.value, value: option.value }, decode(option.label))
        })
      )
    )
  }

  function Label(props) {
    var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel
    return PaymentMethodLabel ? el(PaymentMethodLabel, { text: title }) : el('span', null, title)
  }

  registry.registerPaymentMethod({
    name: 'noncepay',
    label: el(Label, null),
    content: el(Content, null),
    edit: el(Content, null),
    canMakePayment: function () {
      return coins.length > 0
    },
    ariaLabel: title,
    supports: { features: settings.supports || ['products'] },
  })
})()
