<style>
    @page { margin: 36px 40px 48px; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 10px; color: #1f2937; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    h2 { font-size: 13px; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; }
    h3 { font-size: 11px; margin: 10px 0 4px; }
    .muted { color: #6b7280; }
    .right { text-align: right; }
    .small { font-size: 8.5px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 8.5px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 4px 3px; }
    td { padding: 4px 3px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
    .totals td { border: none; padding: 2px 3px; }
    .grand td { font-size: 12px; font-weight: bold; border-top: 1px solid #1f2937; }
    .head td { border: none; padding: 0; }
    .box { border: 1px solid #e5e7eb; padding: 8px; }
    .watermark { position: fixed; top: 300px; left: 80px; font-size: 110px; color: rgba(220, 38, 38, 0.12); transform: rotate(-30deg); }
    .footer { position: fixed; bottom: -28px; left: 0; right: 90px; font-size: 8px; color: #9ca3af; }
    /* E-43..E-45: never split a row, keep totals and blocks whole, repeat table headers
       (the first header row is the section title, so it can't be left alone at a page end). */
    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }
    tr, .keep, .totals, .box { page-break-inside: avoid; }
    h1, h2, h3 { page-break-after: avoid; }
    th.section { font-size: 12px; text-transform: none; color: #1f2937; border-bottom: 1px solid #d1d5db; padding: 14px 3px 4px; font-weight: bold; }
    .logo { max-height: 48px; max-width: 200px; margin-bottom: 6px; }
    .brand-bar { height: 3px; margin-bottom: 14px; }
</style>
