{{-- Temail admin brand palette — injected on every Filament admin page --}}
<style>
    :root {
        /* Named brand tokens */
        --farm-green: #196343;
        --lettuce: #2fa73f;
        --lemon-green: #91cc3b;
        --light-green: #e1efa3;
        --lemon: #ffcc02;
        --pumpkin: #fa9339;
        --chilli: #b31942;
        --pumping-spice: #f43e4a;
        --apricot: #ff9742;
        --yellow-jacket: #ffca3c;
        --ceramic-green: #3abd6f;
        --lynx-blue: #26ade4;
        --true-v: #806dc6;

        /* Semantic roles */
        --brand-ink: #0f2a22;
        --brand-muted: #5c7269;
        --brand-line: #c9e0cf;
        --brand-surface: #f6fbf4;
        --brand-surface-2: #ffffff;
        --brand-glow: rgba(25, 99, 67, 0.12);
    }

    /* ─── Light mode chrome ─── */
    html:not(.dark) .fi-sidebar {
        background:
            radial-gradient(120% 80% at 0% 0%, rgba(145, 204, 59, 0.22), transparent 55%),
            linear-gradient(180deg, #145338 0%, var(--farm-green) 45%, #124f35 100%) !important;
    }

    html:not(.dark) .fi-sidebar .fi-sidebar-header {
        background: transparent !important;
        border-bottom: 1px solid rgba(225, 239, 163, 0.22) !important;
    }

    /* Idle: Light Green / white ink on Farm Green */
    html:not(.dark) .fi-sidebar .fi-sidebar-group-label,
    html:not(.dark) .fi-sidebar .fi-logo,
    html:not(.dark) .fi-sidebar .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-button,
    html:not(.dark) .fi-sidebar .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-label,
    html:not(.dark) .fi-sidebar .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-icon,
    html:not(.dark) .fi-sidebar .fi-sidebar-item:not(.fi-active) svg,
    html:not(.dark) .fi-sidebar .fi-sidebar-group-button,
    html:not(.dark) .fi-sidebar .fi-icon-btn {
        color: #f7fcee !important;
        opacity: 1 !important;
        -webkit-text-fill-color: #f7fcee !important;
    }

    /* Hover + active: Light Green surface, Farm Green ink */
    html:not(.dark) .fi-sidebar .fi-sidebar-item .fi-sidebar-item-button:hover,
    html:not(.dark) .fi-sidebar .fi-sidebar-item .fi-sidebar-item-button:focus-visible,
    html:not(.dark) .fi-sidebar .fi-active .fi-sidebar-item-button,
    html:not(.dark) .fi-sidebar .fi-sidebar-item-button[aria-current="page"] {
        background: var(--light-green) !important;
        box-shadow: inset 3px 0 0 var(--lemon-green) !important;
        color: var(--farm-green) !important;
        -webkit-text-fill-color: var(--farm-green) !important;
        opacity: 1 !important;
    }

    html:not(.dark) .fi-sidebar .fi-sidebar-item .fi-sidebar-item-button:hover *,
    html:not(.dark) .fi-sidebar .fi-sidebar-item .fi-sidebar-item-button:focus-visible *,
    html:not(.dark) .fi-sidebar .fi-active .fi-sidebar-item-button *,
    html:not(.dark) .fi-sidebar .fi-active .fi-sidebar-item-label,
    html:not(.dark) .fi-sidebar .fi-active .fi-sidebar-item-icon,
    html:not(.dark) .fi-sidebar .fi-active svg,
    html:not(.dark) .fi-sidebar .fi-sidebar-item-button[aria-current="page"] * {
        color: var(--farm-green) !important;
        stroke: var(--farm-green) !important;
        -webkit-text-fill-color: var(--farm-green) !important;
        opacity: 1 !important;
        background: transparent !important;
    }

    html:not(.dark) .fi-topbar {
        background: linear-gradient(90deg, #ffffff 0%, #f7fcee 55%, #fffdf3 100%) !important;
        border-bottom: 1px solid var(--brand-line) !important;
    }

    html:not(.dark) .fi-topbar .fi-logo,
    html:not(.dark) .fi-topbar a.fi-logo,
    html:not(.dark) .fi-logo {
        color: var(--farm-green) !important;
        -webkit-text-fill-color: var(--farm-green) !important;
        opacity: 1 !important;
        font-weight: 800 !important;
    }

    html:not(.dark) .fi-main,
    html:not(.dark) .fi-body {
        background:
            radial-gradient(90% 60% at 100% 0%, rgba(225, 239, 163, 0.55), transparent 50%),
            radial-gradient(70% 50% at 0% 100%, rgba(38, 173, 228, 0.08), transparent 45%),
            var(--brand-surface) !important;
    }

    html:not(.dark) .fi-header-heading {
        color: var(--farm-green) !important;
        letter-spacing: -0.02em;
    }

    html:not(.dark) .fi-simple-page,
    html:not(.dark) .fi-simple-main {
        background:
            radial-gradient(80% 60% at 50% 0%, rgba(225, 239, 163, 0.7), transparent 55%),
            linear-gradient(180deg, #f4faef, #eef7ea) !important;
    }

    /* ─── Dark mode chrome (palette kept; contrast raised) ─── */
    .dark .fi-sidebar {
        background:
            radial-gradient(120% 70% at 0% 0%, rgba(145, 204, 59, 0.16), transparent 50%),
            linear-gradient(180deg, #0d2a1e 0%, #123528 40%, #0b2118 100%) !important;
    }

    .dark .fi-sidebar .fi-sidebar-header {
        background: rgba(0, 0, 0, 0.18) !important;
        border-bottom: 1px solid rgba(145, 204, 59, 0.28) !important;
    }

    .dark .fi-sidebar .fi-logo,
    .dark .fi-sidebar .fi-sidebar-header .fi-logo,
    .dark .fi-sidebar .fi-sidebar-header a {
        color: var(--light-green) !important;
        -webkit-text-fill-color: var(--light-green) !important;
        opacity: 1 !important;
        font-weight: 800 !important;
    }

    .dark .fi-sidebar .fi-sidebar-group-label,
    .dark .fi-sidebar .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-button,
    .dark .fi-sidebar .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-label,
    .dark .fi-sidebar .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-icon,
    .dark .fi-sidebar .fi-sidebar-item:not(.fi-active) svg,
    .dark .fi-sidebar .fi-sidebar-group-button,
    .dark .fi-sidebar .fi-icon-btn {
        color: #e8f6e4 !important;
        -webkit-text-fill-color: #e8f6e4 !important;
        opacity: 1 !important;
    }

    /* Dark hover/active: Lemon Green bar + Farm Green text (always readable) */
    .dark .fi-sidebar .fi-sidebar-item .fi-sidebar-item-button:hover,
    .dark .fi-sidebar .fi-sidebar-item .fi-sidebar-item-button:focus-visible,
    .dark .fi-sidebar .fi-active .fi-sidebar-item-button,
    .dark .fi-sidebar .fi-sidebar-item-button[aria-current="page"] {
        background: var(--lemon-green) !important;
        box-shadow: inset 3px 0 0 var(--lemon) !important;
        color: var(--farm-green) !important;
        -webkit-text-fill-color: var(--farm-green) !important;
        opacity: 1 !important;
    }

    .dark .fi-sidebar .fi-sidebar-item .fi-sidebar-item-button:hover *,
    .dark .fi-sidebar .fi-sidebar-item .fi-sidebar-item-button:focus-visible *,
    .dark .fi-sidebar .fi-active .fi-sidebar-item-button *,
    .dark .fi-sidebar .fi-active .fi-sidebar-item-label,
    .dark .fi-sidebar .fi-active .fi-sidebar-item-icon,
    .dark .fi-sidebar .fi-active span,
    .dark .fi-sidebar .fi-active svg,
    .dark .fi-sidebar .fi-sidebar-item-button[aria-current="page"] * {
        color: var(--farm-green) !important;
        stroke: var(--farm-green) !important;
        -webkit-text-fill-color: var(--farm-green) !important;
        opacity: 1 !important;
        background: transparent !important;
    }

    .dark .fi-topbar {
        background: linear-gradient(90deg, #0f241c 0%, #143228 55%, #10271f 100%) !important;
        border-bottom: 1px solid rgba(145, 204, 59, 0.28) !important;
        box-shadow: none !important;
    }

    .dark .fi-topbar .fi-logo,
    .dark .fi-topbar a.fi-logo,
    .dark .fi-logo,
    .dark .fi-logo span,
    .dark .fi-topbar .fi-logo * {
        color: var(--light-green) !important;
        -webkit-text-fill-color: var(--light-green) !important;
        opacity: 1 !important;
        font-weight: 800 !important;
    }

    .dark .fi-main,
    .dark .fi-body {
        background:
            radial-gradient(80% 50% at 100% 0%, rgba(47, 167, 63, 0.18), transparent 50%),
            radial-gradient(60% 40% at 0% 100%, rgba(38, 173, 228, 0.1), transparent 45%),
            #0c1914 !important;
    }

    .dark .fi-header-heading {
        color: var(--lemon-green) !important;
        letter-spacing: -0.02em;
    }

    .dark .fi-simple-page,
    .dark .fi-simple-main {
        background:
            radial-gradient(80% 60% at 50% 0%, rgba(25, 99, 67, 0.45), transparent 55%),
            #0c1914 !important;
    }

    .dark .fi-btn-color-primary {
        background-color: var(--pumpkin) !important;
    }

    /* Shared ops surface language */
    .temail-ops {
        color: var(--brand-ink);
        max-width: 1180px;
        margin: 0 auto;
    }

    .temail-ops .ops-kicker {
        margin: 0;
        color: var(--farm-green);
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .temail-ops .ops-card {
        border: 1px solid var(--brand-line);
        border-radius: 1rem;
        background: var(--brand-surface-2);
        padding: 1.5rem;
        box-shadow: 0 4px 18px var(--brand-glow);
    }

    .temail-ops .ops-card--hero {
        border-color: var(--lemon-green);
        background: linear-gradient(135deg, var(--light-green), #ffffff 62%);
    }

    .temail-ops .ops-card--info {
        border-color: rgba(38, 173, 228, 0.35);
        background: linear-gradient(135deg, #eaf8fd, #ffffff 70%);
    }

    .temail-ops .ops-card--accent {
        border-color: rgba(128, 109, 198, 0.35);
        background: linear-gradient(135deg, #f3effc, #ffffff 70%);
    }

    .temail-ops .ops-card--warn {
        border-color: rgba(250, 147, 57, 0.45);
        background: linear-gradient(135deg, #fff4e8, #ffffff 70%);
    }

    .temail-ops .ops-card h2,
    .temail-ops .ops-card h3 {
        color: var(--farm-green);
    }

    .temail-ops .ops-muted {
        color: var(--brand-muted);
    }

    .temail-ops .ops-chip {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.3rem 0.7rem;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .temail-ops .ops-chip--ok {
        background: rgba(58, 189, 111, 0.18);
        color: #146b3a;
        border: 1px solid rgba(58, 189, 111, 0.4);
    }

    .temail-ops .ops-chip--ready {
        background: var(--lettuce);
        color: #fff;
    }

    .temail-ops .ops-chip--warn {
        background: rgba(250, 147, 57, 0.18);
        color: #8a4508;
        border: 1px solid rgba(250, 147, 57, 0.45);
    }

    .temail-ops .ops-chip--caution {
        background: var(--yellow-jacket);
        color: #5c4700;
    }

    .temail-ops .ops-chip--danger {
        background: rgba(179, 25, 66, 0.12);
        color: var(--chilli);
        border: 1px solid rgba(179, 25, 66, 0.35);
    }

    .temail-ops .ops-chip--critical {
        background: var(--pumping-spice);
        color: #fff;
    }

    .temail-ops .ops-chip--info {
        background: rgba(38, 173, 228, 0.16);
        color: #0b6f99;
        border: 1px solid rgba(38, 173, 228, 0.4);
    }

    .temail-ops .ops-chip--accent {
        background: rgba(128, 109, 198, 0.16);
        color: #4f3d8f;
        border: 1px solid rgba(128, 109, 198, 0.4);
    }

    .temail-ops .ops-chip--highlight {
        background: var(--lemon);
        color: #5c4700;
    }

    .temail-ops .ops-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 0.6rem;
        padding: 0.65rem 1rem;
        background: var(--farm-green);
        color: #fff;
        font-size: 0.875rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.15s ease, transform 0.15s ease;
    }

    .temail-ops .ops-btn:hover {
        background: var(--lettuce);
    }

    .temail-ops .ops-btn--danger {
        background: var(--chilli);
    }

    .temail-ops .ops-btn--danger:hover {
        background: var(--pumping-spice);
    }

    .temail-ops .ops-btn--warm {
        background: var(--pumpkin);
        color: #fff;
    }

    .temail-ops .ops-btn--warm:hover {
        background: var(--apricot);
    }

    .temail-ops .ops-btn--info {
        background: var(--lynx-blue);
        color: #fff;
    }

    .temail-ops .ops-btn--accent {
        background: var(--true-v);
        color: #fff;
    }

    .temail-ops .ops-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
    }

    .temail-ops .ops-table th,
    .temail-ops .ops-table td {
        padding: 0.75rem 0.5rem;
        border-bottom: 1px solid #e4efe6;
        text-align: left;
    }

    .temail-ops .ops-table th {
        color: var(--farm-green);
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        background: rgba(225, 239, 163, 0.35);
    }

    .temail-ops .ops-alert {
        border-radius: 0.75rem;
        padding: 1rem;
        border: 1px solid var(--yellow-jacket);
        background: linear-gradient(135deg, #fff9df, #fff);
        color: #735900;
    }

    .temail-ops .ops-alert--danger {
        border-color: rgba(244, 62, 74, 0.45);
        background: linear-gradient(135deg, #fff0f2, #fff);
        color: var(--chilli);
    }

    .temail-ops .ops-alert--info {
        border-color: rgba(38, 173, 228, 0.4);
        background: linear-gradient(135deg, #eaf8fd, #fff);
        color: #0b6f99;
    }

    .temail-ops .ops-metric {
        border: 1px solid #cde8c9;
        border-radius: 0.85rem;
        padding: 1rem;
        background: linear-gradient(135deg, #fff, var(--light-green));
    }

    .temail-ops .ops-metric--bad {
        border-color: #f2c1cd;
        background: #fff7f9;
    }

    .temail-ops .ops-metric strong {
        display: block;
        color: var(--farm-green);
    }

    .temail-ops .ops-metric--bad strong {
        color: var(--chilli);
    }

    .temail-ops input.ops-input,
    .temail-ops select.ops-input,
    .temail-ops .ops-input {
        width: 100%;
        margin-top: 0.35rem;
        padding: 0.65rem 0.75rem;
        border: 1px solid #b7d0bf;
        border-radius: 0.55rem;
        background: #fff;
        color: var(--brand-ink);
    }

    .temail-ops input.ops-input:focus,
    .temail-ops select.ops-input:focus,
    .temail-ops .ops-input:focus {
        outline: 2px solid rgba(47, 167, 63, 0.35);
        border-color: var(--lettuce);
    }

    .temail-ops .ops-link {
        color: var(--lynx-blue);
        font-weight: 600;
        text-decoration: underline;
        cursor: pointer;
    }

    .temail-ops .ops-link--danger {
        color: var(--chilli);
    }

    .dark .temail-ops {
        color: #e7f3ec;
    }

    .dark .temail-ops .ops-card {
        background: #12241c;
        border-color: #2a4a3a;
        box-shadow: none;
    }

    .dark .temail-ops .ops-card--hero {
        background: linear-gradient(135deg, #1a3a2c, #12241c 65%);
        border-color: var(--lemon-green);
    }

    .dark .temail-ops .ops-card--info {
        background: linear-gradient(135deg, #102834, #12241c 70%);
        border-color: rgba(38, 173, 228, 0.45);
    }

    .dark .temail-ops .ops-card--accent {
        background: linear-gradient(135deg, #241c38, #12241c 70%);
        border-color: rgba(128, 109, 198, 0.45);
    }

    .dark .temail-ops .ops-card--warn {
        background: linear-gradient(135deg, #2a1c10, #12241c 70%);
        border-color: rgba(250, 147, 57, 0.5);
    }

    .dark .temail-ops .ops-card h2,
    .dark .temail-ops .ops-card h3,
    .dark .temail-ops .ops-kicker,
    .dark .temail-ops .ops-table th {
        color: var(--lemon-green);
    }

    .dark .temail-ops .ops-muted {
        color: #9bb5a8;
    }

    .dark .temail-ops .ops-metric {
        background: #183528;
        border-color: #2f5a44;
    }

    .dark .temail-ops .ops-input {
        background: #0f1f18;
        border-color: #2f5a44;
        color: #e7f3ec;
    }
</style>
