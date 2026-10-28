# Accessibility and responsive release checklist

Reviewed against the working browser application on 28 October 2026.

- Keyboard: skip link, workspace tabs, explorer rows, dialogs, command palette, canvas cards, and relationship proposals are reachable without a pointer.
- Focus: interactive controls use visible focus rings; dialogs return focus through native dialog behavior.
- Semantics: primary navigation uses tabs, the explorer uses tree/row semantics, data uses tables, forms have labels, and icon-only controls have accessible names.
- Color: destructive operations include explicit labels and confirmations rather than relying on red alone; light and dark themes preserve foreground/background contrast.
- Motion: animations are disabled under `prefers-reduced-motion: reduce`.
- Responsive: desktop workspace and 390px emergency-admin viewport were reviewed; side panes become explicit overlays/controls and selecting an explorer object closes the mobile overlay.
- Data safety: NULL, binary metadata, permissions, confirmations, and server errors are conveyed as text.
- Zoom: layout uses responsive CSS and scrollable data surfaces rather than clipping the whole document.

Known boundary: dense data tables remain horizontally scrollable on narrow phones. Row editing uses the record form for a more practical mobile workflow.
