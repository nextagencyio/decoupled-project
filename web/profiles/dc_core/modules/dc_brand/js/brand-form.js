/**
 * @file
 * Live preview bits for the dc_brand settings form.
 *
 * - Each color input paints a 9-stop HSL ramp in its sibling `.brand-ramp`
 *   container. Matches the server-side math in HslScaleGenerator.php so the
 *   admin preview is pixel-accurate to what Astro will render.
 * - Each font select loads its Google Font family and shows a sample line
 *   in `.brand-font-preview` so the marketer sees what they're picking.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.dcBrandForm = {
    attach: function (context) {
      // Color pickers → live ramp.
      once('dc-brand-color', '[data-brand-color]', context).forEach(function (input) {
        var rampKey = input.dataset.brandColor;
        var ramp = document.querySelector('[data-brand-ramp="' + rampKey + '"]');
        var hexLabel = document.querySelector('[data-brand-hex="' + rampKey + '"]');
        if (!ramp) { return; }

        var render = function () {
          paintRamp(ramp, input.value);
          if (hexLabel) { hexLabel.textContent = input.value.toLowerCase(); }
        };
        input.addEventListener('input', render);
        input.addEventListener('change', render);
        render();
      });

      // Font dropdowns → load family + show sample.
      once('dc-brand-font', '[data-brand-font]', context).forEach(function (select) {
        var slot = select.dataset.brandFont;
        var preview = document.querySelector('[data-brand-font-preview="' + slot + '"]');
        if (!preview) { return; }

        var render = function () { loadAndShow(select.value, preview); };
        select.addEventListener('change', render);
        render();
      });
    }
  };

  // ---------- ramp math (mirrors HslScaleGenerator.php) ----------------

  var STOPS = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900'];
  var LIGHTNESS = { '50': 97, '100': 93, '200': 85, '300': 74, '400': 62, '500': 50, '600': 42, '700': 33, '800': 24, '900': 15 };

  function paintRamp(container, hex) {
    var hsl = hexToHsl(hex);
    var h = Math.round(hsl[0]);
    var s = hsl[1].toFixed(1);
    var userL = hsl[2];
    container.innerHTML = '';
    STOPS.forEach(function (stop) {
      var l = (stop === '500') ? userL : LIGHTNESS[stop];
      var isDark = l < 55;
      var el = document.createElement('div');
      el.className = 'brand-ramp-stop' + (isDark ? ' is-dark' : '');
      el.style.backgroundColor = 'hsl(' + h + ', ' + s + '%, ' + l.toFixed(1) + '%)';
      el.title = stop + ' · hsl(' + h + ' ' + s + '% ' + l.toFixed(1) + '%)';
      var label = document.createElement('span');
      label.textContent = stop;
      el.appendChild(label);
      container.appendChild(el);
    });
  }

  function hexToHsl(hex) {
    var h = hex.replace('#', '');
    if (h.length === 3) {
      h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    }
    var r = parseInt(h.slice(0, 2), 16) / 255;
    var g = parseInt(h.slice(2, 4), 16) / 255;
    var b = parseInt(h.slice(4, 6), 16) / 255;
    var max = Math.max(r, g, b), min = Math.min(r, g, b);
    var l = (max + min) / 2;
    if (max === min) { return [0, 0, l * 100]; }
    var d = max - min;
    var s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    var hue = 0;
    if (max === r)      { hue = ((g - b) / d) + (g < b ? 6 : 0); }
    else if (max === g) { hue = ((b - r) / d) + 2; }
    else                { hue = ((r - g) / d) + 4; }
    return [hue * 60, s * 100, l * 100];
  }

  // ---------- font loader --------------------------------------------

  var loadedFonts = new Set();

  function loadAndShow(family, preview) {
    if (!family) { return; }
    if (!loadedFonts.has(family)) {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = 'https://fonts.googleapis.com/css2?family=' + family.replace(/ /g, '+') + ':wght@400;600;700&display=swap';
      document.head.appendChild(link);
      loadedFonts.add(family);
    }
    preview.style.fontFamily = '"' + family + '", system-ui, sans-serif';
    preview.textContent = 'The quick brown fox jumps over the lazy dog — 1234567890';
  }

})(Drupal, once);
