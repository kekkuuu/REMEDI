{{-- resources/views/partials/_chart-gradient.blade.php --}}
{{--
    Gradient fills for every chart in the app, registered once.

    Included immediately AFTER each page's Chart.js <script src>, because
    Chart.js is loaded per page (eight of them) rather than in the layout --
    registering this in the layout would run before Chart exists. The dashboard
    bodies are AJAX-injected and their scripts are re-created one at a time in
    order, so the same "straight after the CDN tag" placement works there too.

    Done as a plugin rather than by editing 32 chart definitions: the colours
    stay where they are, every chart picks the treatment up automatically, and
    a new chart added later does not have to remember to opt in.
--}}
<script>
(function () {
    if (!window.Chart || Chart.__remediGradient) return;
    Chart.__remediGradient = true;

    /* Accepts the two forms the charts actually use -- #rgb / #rrggbb and
       rgb()/rgba(). Anything else (a CanvasGradient someone built by hand, a
       scriptable function, a CSS variable) returns null and is left untouched,
       so this can never overwrite a deliberate choice it does not understand. */
    function parse(input) {
        if (typeof input !== 'string') return null;

        var value = input.trim();

        if (value.charAt(0) === '#') {
            var hex = value.slice(1);
            if (hex.length === 3) {
                hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            }
            if (hex.length !== 6 || /[^0-9a-f]/i.test(hex)) return null;

            return {
                r: parseInt(hex.slice(0, 2), 16),
                g: parseInt(hex.slice(2, 4), 16),
                b: parseInt(hex.slice(4, 6), 16),
                a: 1,
            };
        }

        var m = value.match(/^rgba?\(([^)]+)\)$/i);
        if (!m) return null;

        var parts = m[1].split(',').map(function (n) { return parseFloat(n); });
        if (parts.length < 3 || parts.some(isNaN)) return null;

        return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
    }

    function rgba(c, alpha) {
        return 'rgba(' + Math.round(c.r) + ',' + Math.round(c.g) + ',' + Math.round(c.b) + ',' + alpha + ')';
    }

    function lighten(c, amount) {
        return {
            r: c.r + (255 - c.r) * amount,
            g: c.g + (255 - c.g) * amount,
            b: c.b + (255 - c.b) * amount,
            a: c.a,
        };
    }

    function vertical(ctx, area) {
        return ctx.createLinearGradient(0, area.top, 0, area.bottom);
    }

    function fillGradient(ctx, area, color, type, filled) {
        var c = parse(color);
        if (!c) return color;

        var g = vertical(ctx, area);

        if (type === 'doughnut' || type === 'pie' || type === 'polarArea') {
            /* Arcs lighten toward the top rather than fading out. A doughnut
               with a transparent lower half reads as a rendering fault against
               the card behind it, not as a gradient. */
            g.addColorStop(0, rgba(lighten(c, 0.30), c.a));
            g.addColorStop(1, rgba(c, c.a));

            return g;
        }

        if (type === 'line' && filled) {
            // An area fill sits over the gridlines, so it has to thin out or
            // the plot behind it stops being readable.
            g.addColorStop(0, rgba(c, Math.min(c.a, 0.40)));
            g.addColorStop(1, rgba(c, 0.02));

            return g;
        }

        // Bars: full strength at the top where the value is read, lifting
        // toward the baseline so the column has some depth to it.
        g.addColorStop(0, rgba(c, c.a));
        g.addColorStop(1, rgba(lighten(c, 0.38), Math.max(c.a * 0.72, 0.34)));

        return g;
    }

    function strokeGradient(ctx, area, color) {
        var c = parse(color);
        if (!c) return color;

        var g = vertical(ctx, area);
        g.addColorStop(0, rgba(c, c.a));
        g.addColorStop(1, rgba(lighten(c, 0.42), c.a));

        return g;
    }

    Chart.register({
        id: 'remediGradient',

        /* afterLayout, because a gradient needs chartArea's pixel coordinates
           and they do not exist before layout. It also re-runs on resize, which
           is why the original colours are stashed below -- building the next
           gradient out of the last one would compound into mud. */
        afterLayout: function (chart) {
            var area = chart.chartArea;
            if (!area || area.bottom <= area.top) return;

            var ctx = chart.ctx;

            chart.data.datasets.forEach(function (ds) {
                if (!('__bg' in ds)) ds.__bg = ds.backgroundColor;
                if (!('__border' in ds)) ds.__border = ds.borderColor;

                var type = ds.type || (chart.config && chart.config.type);
                // fill: '-1' is the confidence band on the forecast charts --
                // it is filled BETWEEN two datasets, and fading it vertically
                // would misrepresent the interval it is drawing.
                var filled = ds.fill === true || ds.fill === 'origin' || ds.fill === 'start';

                if (ds.__bg) {
                    ds.backgroundColor = Array.isArray(ds.__bg)
                        ? ds.__bg.map(function (c) { return fillGradient(ctx, area, c, type, filled); })
                        : fillGradient(ctx, area, ds.__bg, type, filled);
                }

                /* An unfilled line has no area to shade, so the gradient goes on
                   the stroke instead -- otherwise those ten charts would be the
                   only flat ones left. Points are pinned back to the solid
                   colour: a marker drawn in the faded end of the gradient all
                   but disappears against the grid. */
                if (type === 'line' && !filled && ds.__border && !Array.isArray(ds.__border)) {
                    ds.borderColor = strokeGradient(ctx, area, ds.__border);

                    if (!ds.pointBackgroundColor) ds.pointBackgroundColor = ds.__border;
                    if (!ds.pointBorderColor) ds.pointBorderColor = ds.__border;
                }
            });
        },
    });
})();
</script>
