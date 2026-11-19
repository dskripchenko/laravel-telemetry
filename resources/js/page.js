/*
 * dskripchenko/laravel-telemetry — the page as the person's browser saw it.
 *
 * Sends one beacon per page load to the application's own server: the phases of
 * the navigation (DNS, connection, TLS, waiting, download), the protocol, the
 * paints, the application's marks (performance.mark('app:…')) and what its API
 * calls cost on the wire against what the server says they took (Server-Timing).
 * No identifiers, no addresses with their queries, nothing of the page's content.
 *
 * The application says when it is usable:
 *     performance.mark('app:ready', { detail: { page: 'orders.list' } })
 * Without the mark the beacon goes 15 s after the load, or when the page is hidden.
 */
(function () {
  'use strict'

  var ENDPOINT = '__ENDPOINT__'
  var PATIENCE = 15000 // ms to wait for the application's 'app:ready'
  var QUIET = 1000 // ms without a new API call after it: the first data is in

  if (!window.performance || !performance.getEntriesByType || !navigator.sendBeacon) return

  var sent = false
  var lcp = 0
  var page = ''
  var timer = null

  function ms(value) {
    return value > 0 ? Math.round(value * 10) / 10 : 0
  }

  // The server's own time of a response: the 'app' metric of its Server-Timing.
  function serverTime(entry) {
    var list = entry.serverTiming || []
    for (var i = 0; i < list.length; i++) {
      if (list[i].name === 'app') return list[i].duration
    }
    return 0
  }

  function observe(type, each) {
    try {
      new PerformanceObserver(function (list) {
        list.getEntries().forEach(each)
      }).observe({ type: type, buffered: true })
    } catch (e) {
      // The browser does not know the entry type: the field stays empty.
    }
  }

  function collect() {
    var nav = performance.getEntriesByType('navigation')[0]
    if (!nav) return null

    var data = {
      page: page,
      path: page ? '' : location.pathname,
      nav: nav.type,
      proto: nav.nextHopProtocol || '',
      start: ms(nav.fetchStart),
      dns: ms(nav.domainLookupEnd - nav.domainLookupStart),
      connect: ms(nav.connectEnd - nav.connectStart),
      tls: nav.secureConnectionStart > 0 ? ms(nav.connectEnd - nav.secureConnectionStart) : 0,
      wait: ms(nav.responseStart - nav.requestStart),
      server: ms(serverTime(nav)),
      download: ms(nav.responseEnd - nav.responseStart),
      dom: ms(nav.domContentLoadedEventEnd),
      load: ms(nav.loadEventEnd),
      lcp: ms(lcp),
    }

    performance.getEntriesByType('paint').forEach(function (entry) {
      if (entry.name === 'first-contentful-paint') data.fcp = ms(entry.startTime)
    })

    // The application's marks: app:session, app:ready and whatever else it names.
    var marks = 0
    performance.getEntriesByType('mark').forEach(function (entry) {
      if (entry.name.indexOf('app:') !== 0 || marks >= 8) return
      var name = entry.name.slice(4).toLowerCase().replace(/[^a-z0-9_]+/g, '_').slice(0, 24)
      if (name) {
        data['m_' + name] = ms(entry.startTime)
        marks++
      }
    })

    // Resources: how many and how heavy; API calls — on the wire against on the server.
    var resources = performance.getEntriesByType('resource')
    var api = { n: 0, ms: 0, max: 0, server: 0, end: 0 }
    var bytes = 0
    resources.forEach(function (entry) {
      bytes += entry.transferSize || 0
      if (entry.initiatorType !== 'fetch' && entry.initiatorType !== 'xmlhttprequest') return
      if (entry.name.indexOf(location.origin + '/') !== 0 || entry.name.indexOf(ENDPOINT) !== -1) return
      api.n++
      api.ms += entry.duration
      api.max = Math.max(api.max, entry.duration)
      api.server += serverTime(entry)
      api.end = Math.max(api.end, entry.responseEnd)
    })
    data.res_n = resources.length
    data.res_kb = Math.round(bytes / 1024)
    data.api_n = api.n
    data.api_ms = ms(api.ms)
    data.api_max = ms(api.max)
    data.api_server = ms(api.server)
    data.api_end = ms(api.end)

    var connection = navigator.connection
    if (connection) {
      if (connection.rtt >= 0) data.rtt = connection.rtt
      if (connection.downlink >= 0) data.down = connection.downlink
      if (connection.effectiveType) data.net = connection.effectiveType
    }

    return data
  }

  function send() {
    if (sent) return
    sent = true
    var data = collect()
    if (data) {
      // text/plain: a simple request, no preflight; the server reads JSON all the same.
      navigator.sendBeacon(ENDPOINT, new Blob([JSON.stringify(data)], { type: 'text/plain' }))
    }
  }

  // After the load event has ended, so that its time is known.
  function afterLoad(then) {
    if (document.readyState === 'complete') setTimeout(then, 0)
    else window.addEventListener('load', function () { setTimeout(then, 0) })
  }

  function soon(delay) {
    clearTimeout(timer)
    timer = setTimeout(function () { afterLoad(send) }, delay)
  }

  observe('largest-contentful-paint', function (entry) {
    lcp = entry.startTime
  })

  // The application is usable: wait for its first data to stop coming, then send.
  var ready = false
  observe('mark', function (entry) {
    if (entry.name !== 'app:ready' || ready) return
    ready = true
    if (entry.detail && typeof entry.detail.page === 'string') page = entry.detail.page
    soon(QUIET)
  })
  observe('resource', function (entry) {
    if (ready && !sent && (entry.initiatorType === 'fetch' || entry.initiatorType === 'xmlhttprequest')) soon(QUIET)
  })

  soon(PATIENCE)
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') send()
  })
  window.addEventListener('pagehide', send)
})()
