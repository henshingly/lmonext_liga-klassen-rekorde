<style>
.rk-wrap{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;color:#1f2430;max-width:900px;margin:0 auto}
.rk-title{font-size:1.15rem;font-weight:700;margin:0 0 12px;text-align:center}
.rk-nav{display:flex;gap:6px;justify-content:center;margin-bottom:16px}
.rk-tab{padding:7px 16px;border-radius:20px;background:#eef0f4;color:#4b5160;text-decoration:none;font-size:.85rem;font-weight:600}
.rk-tab-active{background:#153A8C;color:#fff}
.rk-subheading{font-size:.95rem;font-weight:700;margin:20px 0 8px;color:#153A8C}
.rk-subheading:first-child{margin-top:0}
.rk-table{width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:6px}
.rk-table th{background:#f0f2f5;color:#4b5160;text-align:left;padding:7px 10px;font-weight:600;font-size:.78rem}
.rk-table td{padding:7px 10px;border-top:1px solid #eef0f3}
.rk-table tbody tr:nth-child(even){background:#fafbfc}
.rk-meister{font-weight:700}
.rk-value{font-weight:700;color:#153A8C;text-align:center}
.rk-empty{color:#9098a8;font-size:.85rem;padding:12px 0}
.rk-meister-bar{background:#153A8C;color:#fff;font-weight:700;font-size:1rem;
                 padding:10px 14px;border-radius:8px 8px 0 0}
.rk-meister-table{margin-bottom:0}
.rk-meister-table th{text-align:right}
.rk-meister-table th:nth-child(1),.rk-meister-table th:nth-child(2),.rk-meister-table th:nth-child(3){text-align:left}
.rk-meister-table td{text-align:right}
.rk-meister-table td:nth-child(1){white-space:nowrap;color:#c9942a}
.rk-meister-table td:nth-child(2),.rk-meister-table td:nth-child(3){text-align:left}
.rk-meister-table a{color:#153A8C;text-decoration:none}
.rk-meister-table a:hover{text-decoration:underline}
.rk-meister-footer{text-align:right;font-size:.75rem;color:#9098a8;padding:8px 10px 0}
.rk-rendertime{text-align:center;font-size:.72rem;color:#b7bccb;margin-top:6px}
</style>
<div class="rk-wrap">
  <h2 class="rk-title">{KLASSE_NAME}</h2>
  <div class="rk-nav">{NAV}</div>
  <div id="rk-body">{BODY}</div>
  {COPYRIGHT}
  {RENDER_TIME}
</div>
{LAZY_JS}
