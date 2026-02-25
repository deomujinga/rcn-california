/**
 * Fluent Forms (Form 3): Date Picker Sundays-only for field "week_start_date"
 * - Disables all days except Sunday (0)
 * - Re-applies after FF re-render / step change / conditional refresh
 */
add_action('wp_footer', function () {
    if (is_admin()) return; ?>
<script>
(function () {
  var FORM_SEL  = 'form#fluentform_3, form[data-form_id="3"]';
  var INPUT_SEL = 'input[name="week_start_date"], input[name*="week_start_date"]';

  function applySundaysOnlyToInput(input) {
    if (!input) return;

    // If FF already attached flatpickr, reuse it; otherwise, bail (FF will init it)
    var fp = input._flatpickr;
    if (!fp) return;

    // Allow only Sundays
    try {
      fp.set('enable', [function (d) { return d.getDay() === 0; }]);
      // Optional UX tweaks:
      fp.set('allowInput', false);       // prevent typing invalid dates
      fp.set('disableMobile', true);     // force consistent UI on mobile
      // Force a refresh of the calendar UI
      if (typeof fp.redraw === 'function') fp.redraw();
      fp.jumpToDate(fp.selectedDates[0] || new Date());
      // console.log('[FF Sunday-only] applied to', input.name);
    } catch (e) {
      // console.warn('[FF Sunday-only] set() failed', e);
    }
  }

  function findInputsIn(form) {
    return Array.prototype.slice.call(form.querySelectorAll(INPUT_SEL));
  }

  function tryWire(form) {
    if (!form) return;

    // 1) If flatpickr already exists, apply immediately
    findInputsIn(form).forEach(function (input) {
      if (input._flatpickr) applySundaysOnlyToInput(input);
    });

    // 2) Poll briefly (covers delayed FF init)
    var tries = 0, maxTries = 40; // ~4s total if 100ms interval
    var iv = setInterval(function () {
      var done = true;
      findInputsIn(form).forEach(function (input) {
        if (input._flatpickr) {
          applySundaysOnlyToInput(input);
        } else {
          done = false;
        }
      });
      tries++;
      if (done || tries >= maxTries) clearInterval(iv);
    }, 100);

    // 3) Re-apply on FF-specific events if present
    ['fluentform-field-reinit','fluentform-step-changed','fluentform_after_form_render','fluentform_rendered']
      .forEach(function (evt) {
        document.addEventListener(evt, function () {
          findInputsIn(form).forEach(function (input) {
            if (input._flatpickr) applySundaysOnlyToInput(input);
          });
        });
      });

    // 4) MutationObserver as a final safety net (captures AJAX/conditional redraws)
    var mo = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        if (!m.addedNodes || !m.addedNodes.length) return;
        findInputsIn(form).forEach(function (input) {
          if (input._flatpickr) applySundaysOnlyToInput(input);
        });
      });
    });
    mo.observe(form, { childList: true, subtree: true });
  }

  function init() {
    var form = document.querySelector(FORM_SEL);
    if (!form) return;

    // If FF hasn’t initialized the date field yet, that’s fine—our polling/observer handles it.
    tryWire(form);
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(init, 0);
  } else {
    document.addEventListener('DOMContentLoaded', init);
  }
})();
</script>
<style>
  /* Make disabled days more obviously unavailable */
  .fluentform .flatpickr-day.flatpickr-disabled {
    opacity: 0.35 !important;
    cursor: not-allowed !important;
    text-decoration: line-through;
  }
</style>
<?php
});
