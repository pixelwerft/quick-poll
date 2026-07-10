/**
 * Quick Poll — progressive-enhancement front-end widget.
 *
 * Works without JS (native form POST → redirect back with a notice). With JS,
 * voting and live results happen inline via the JSON endpoints. No framework,
 * no build step — shipped straight from the plugin's resources.
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  /**
   * Resolve a user-facing string. The plugin ships neutral English defaults;
   * the host can localise any of them per poll via data-qp-t-<key> attributes
   * (e.g. data-qp-t-vote-many="Stimmen"). Keeps the plugin language-agnostic.
   */
  function t(root, key, fallback) {
    return root.getAttribute('data-qp-t-' + key) || fallback;
  }

  function renderResults(root, data) {
    var box = root.querySelector('.qp-results');
    if (!box || !data) return;

    var total = data.total || 0;
    var unit = total === 1 ? t(root, 'vote-one', 'vote') : t(root, 'vote-many', 'votes');
    var html;

    if (data.display === 'pill') {
      var segs = (data.options || []).map(function (o) {
        var lead = data.leader != null && data.leader === o.key ? ' is-leader' : '';
        return '<div class="qp-pill__seg' + lead + '"><span class="qp-pill__pct">' + (o.pct || 0) + '%</span></div>';
      }).join('');
      html = '<div class="qp-pill qp-pill--result" role="group">' + segs + '</div>' +
        '<p class="qp-results__based">' + t(root, 'based-on', 'Based on') + ' <strong>' + total + '</strong> ' + unit + '</p>';
    } else if (data.type === 'grid') {
      var grows = (data.options || []).map(function (o) {
        var best = data.best != null && data.best === o.key ? ' is-best' : '';
        return (
          '<li class="qp-bar qp-bar--grid' + best + '">' +
          '<span class="qp-bar__label">' + escapeHtml(o.label) + '</span>' +
          '<span class="qp-bar__track qp-bar__track--seg">' +
          '<span class="qp-seg qp-seg--yes" style="width:' + (o.yesPct || 0) + '%"></span>' +
          '<span class="qp-seg qp-seg--maybe" style="width:' + (o.maybePct || 0) + '%"></span>' +
          '<span class="qp-seg qp-seg--no" style="width:' + (o.noPct || 0) + '%"></span>' +
          '</span>' +
          '<span class="qp-bar__value">✓' + o.yes + ' ~' + o.maybe + ' ✗' + o.no + '</span>' +
          '</li>'
        );
      }).join('');
      html = '<p class="qp-results__total">' + total + ' ' + unit + '</p>' +
        '<ul class="qp-bars qp-bars--grid">' + grows + '</ul>';
    } else {
      var rows = (data.options || []).map(function (o) {
        var pct = o.pct || 0;
        return (
          '<li class="qp-bar' + (data.type === 'mood' ? ' qp-bar--mood' : '') + '">' +
          '<span class="qp-bar__label">' + escapeHtml(o.label) + '</span>' +
          '<span class="qp-bar__track"><span class="qp-bar__fill" style="width:' + pct + '%"></span></span>' +
          '<span class="qp-bar__value">' + pct + '% (' + o.count + ')</span>' +
          '</li>'
        );
      }).join('');
      var head = (data.type === 'rating' && data.average != null)
        ? '<p class="qp-results__avg"><strong>' + data.average + '</strong> / 5 · ' + total + ' ' + unit + '</p>'
        : '<p class="qp-results__total">' + total + ' ' + unit + '</p>';
      html = head + '<ul class="qp-bars">' + rows + '</ul>';
    }

    box.innerHTML = html;
    box.hidden = false;
    // animate fills in
    requestAnimationFrame(function () { box.classList.add('is-shown'); });

    var share = root.querySelector('.qp-share');
    if (share) share.hidden = false;

    var answer = root.querySelector('.qp-poll__answer');
    if (answer) answer.hidden = false;
  }

  function wireShare(root) {
    var share = root.querySelector('.qp-share');
    if (!share) return;
    share.addEventListener('click', function () {
      var url = root.getAttribute('data-qp-share-url') || location.href;
      var title = root.getAttribute('data-qp-share-title') || document.title;
      if (navigator.share) {
        navigator.share({ title: title, url: url }).catch(function () {});
      } else if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function () {
          var lbl = share.querySelector('.qp-share__label');
          if (!lbl) return;
          var prev = lbl.textContent;
          lbl.textContent = t(root, 'copied', 'Link copied');
          setTimeout(function () { lbl.textContent = prev; }, 2000);
        }).catch(function () {});
      }
    });
  }

  /**
   * Re-vote: reopen the (pre-selected) form so the visitor can change their
   * answer. JS-only enhancement — the button stays hidden without JS and is
   * only revealed once the visitor has voted.
   */
  function wireRevote(root) {
    var btn = root.querySelector('.qp-revote');
    var form = root.querySelector('.qp-form');
    if (!btn || !form) return;

    if (root.getAttribute('data-qp-voted') === '1') btn.hidden = false;

    btn.addEventListener('click', function () {
      var results = root.querySelector('.qp-results');
      if (results) { results.hidden = true; results.classList.remove('is-shown'); }
      var share = root.querySelector('.qp-share');
      if (share) share.hidden = true;
      var answer = root.querySelector('.qp-poll__answer');
      if (answer) answer.hidden = true;
      var err = root.querySelector('.qp-error');
      if (err) { err.hidden = true; err.textContent = ''; }
      btn.hidden = true;
      root.classList.remove('qp-poll--voted');
      form.hidden = false;
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function showError(root, msg) {
    var el = root.querySelector('.qp-error');
    if (el) { el.textContent = msg; el.hidden = false; }
  }

  function fetchResults(root) {
    var url = root.getAttribute('data-qp-results');
    if (!url) return;
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.canSee && json.results) renderResults(root, json.results);
      })
      .catch(function () { /* silent — results just stay hidden */ });
  }

  function submit(root, form) {
    var body = new FormData(form);
    fetch(root.getAttribute('data-qp-action'), {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: body,
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.j || !res.j.success) {
          showError(root, (res.j && res.j.error) || t(root, 'err-failed', 'Voting failed.'));
          return;
        }
        root.classList.add('qp-poll--voted');
        form.hidden = true;
        if (res.j.canSee) renderResults(root, res.j.results);
        // Re-vote stays possible after a fresh vote: reveal the change button.
        var revote = root.querySelector('.qp-revote');
        if (revote) revote.hidden = false;
      })
      .catch(function () { showError(root, t(root, 'err-network', 'Network error. Please try again.')); });
  }

  ready(function () {
    var polls = document.querySelectorAll('.qp-poll');
    Array.prototype.forEach.call(polls, function (root) {
      root.classList.add('qp-poll--js');
      var form = root.querySelector('.qp-form');
      var keepForm = root.getAttribute('data-qp-keep-form') === '1';

      // Already-voted / always-visible polls: pull results immediately.
      if (root.getAttribute('data-qp-show-results') === '1') {
        if (form && !keepForm) form.hidden = true;
        fetchResults(root);
      }

      wireShare(root);
      wireRevote(root);

      if (!form) return;
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!form.querySelector('input:checked')) {
          showError(root, t(root, 'err-required', 'Please choose an option.'));
          return;
        }
        submit(root, form);
      });

      // Segmented pill: clicking a segment votes immediately.
      if (root.getAttribute('data-qp-display') === 'pill') {
        form.addEventListener('change', function (e) {
          if (e.target && e.target.type === 'radio') submit(root, form);
        });
      }
    });
  });
})();
