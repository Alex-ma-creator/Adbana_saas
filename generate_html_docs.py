import re
import html

def md_to_html(md_text):
    # Process mermaid blocks first
    mermaid_blocks = []
    def save_mermaid(match):
        idx = len(mermaid_blocks)
        mermaid_blocks.append(match.group(1).strip())
        return f"<!--MERMAID_PLACEHOLDER_{idx}-->"

    md_text = re.sub(r'```mermaid\s*\n(.*?)\n```', save_mermaid, md_text, flags=re.DOTALL)

    # Process generic code blocks
    code_blocks = []
    def save_code(match):
        idx = len(code_blocks)
        lang = match.group(1) or ""
        code = match.group(2)
        code_blocks.append((lang, code))
        return f"<!--CODE_PLACEHOLDER_{idx}-->"

    md_text = re.sub(r'```(\w+)?\s*\n(.*?)\n```', save_code, md_text, flags=re.DOTALL)

    # Split lines
    lines = md_text.split('\n')
    out = []
    in_table = False
    table_rows = []
    in_ul = False

    def flush_table():
        nonlocal in_table, table_rows, out
        if not in_table or not table_rows:
            return
        out.append("<table>")
        is_header = True
        for row in table_rows:
            # check if separator row
            if all(re.match(r'^:?-+:?$', c.strip()) for c in row if c.strip()):
                is_header = False
                continue
            tag = "th" if is_header else "td"
            out.append("  <tr>")
            for c in row:
                content = format_inlines(c.strip())
                out.append(f"    <{tag}>{content}</{tag}>")
            out.append("  </tr>")
        out.append("</table>")
        in_table = False
        table_rows = []

    def flush_ul():
        nonlocal in_ul, out
        if in_ul:
            out.append("</ul>")
            in_ul = False

    def format_inlines(t):
        # Escape html chars
        # Handle bold/italic
        t = re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', t)
        t = re.sub(r'\*(.+?)\*', r'<em>\1</em>', t)
        t = re.sub(r'`([^`]+)`', r'<code>\1</code>', t)
        t = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', r'<a href="\2">\1</a>', t)
        return t

    for line in lines:
        sline = line.strip()

        # Check table
        if sline.startswith('|') and sline.endswith('|'):
            flush_ul()
            in_table = True
            cells = [c for c in sline[1:-1].split('|')]
            table_rows.append(cells)
            continue
        else:
            if in_table:
                flush_table()

        # Check list item
        m_ul = re.match(r'^[\*\-]\s+(.*)', sline)
        if m_ul:
            if not in_ul:
                out.append("<ul>")
                in_ul = True
            out.append(f"  <li>{format_inlines(m_ul.group(1))}</li>")
            continue
        else:
            if in_ul and not sline.startswith((' ', '\t')):
                flush_ul()

        # Check blockquote
        if sline.startswith('>'):
            flush_ul()
            bq_text = format_inlines(sline.lstrip('> ').strip())
            out.append(f"<blockquote><p>{bq_text}</p></blockquote>")
            continue

        # Check headings
        m_h = re.match(r'^(#{1,6})\s+(.*)', sline)
        if m_h:
            flush_ul()
            level = len(m_h.group(1))
            htext = format_inlines(m_h.group(2))
            out.append(f"<h{level}>{htext}</h{level}>")
            continue

        # Check horizontal rule
        if re.match(r'^-{3,}$', sline):
            flush_ul()
            out.append("<hr>")
            continue

        # Check empty line
        if not sline:
            continue

        # Regular paragraph or placeholder
        flush_ul()
        out.append(f"<p>{format_inlines(sline)}</p>")

    if in_table:
        flush_table()
    if in_ul:
        flush_ul()

    html_body = '\n'.join(out)

    # Restore mermaid
    for i, m_code in enumerate(mermaid_blocks):
        escaped_m = html.escape(m_code)
        html_body = html_body.replace(f"<!--MERMAID_PLACEHOLDER_{i}-->", f'<div class="mermaid">\n{m_code}\n</div>')

    # Restore code
    for i, (lang, c_code) in enumerate(code_blocks):
        escaped_c = html.escape(c_code)
        html_body = html_body.replace(f"<!--CODE_PLACEHOLDER_{i}-->", f'<pre><code class="language-{lang}">{escaped_c}</code></pre>')

    return html_body

with open('src/docs/DOCUMENTACION-PROYECTO-25.md', 'r', encoding='utf-8') as f:
    raw_md = f.read()

body = md_to_html(raw_md)

full_html = f'''<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documentación Técnica Adbana SaaS (Entregable 25%)</title>
<script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
<script>mermaid.initialize({{startOnLoad:true, theme:'neutral'}});</script>
<style>
    @page {{
        margin: 20mm;
        size: A4;
    }}
    body {{
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        line-height: 1.6;
        color: #1e293b;
        max-width: 980px;
        margin: 0 auto;
        padding: 40px 25px;
        background-color: #f1f5f9;
    }}
    .document-wrapper {{
        background: #ffffff;
        padding: 50px 60px;
        border-radius: 8px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1);
    }}
    h1 {{
        color: #0f172a;
        font-size: 2.1rem;
        border-bottom: 3px solid #2563eb;
        padding-bottom: 12px;
        margin-top: 0;
    }}
    h2 {{
        color: #1e3a8a;
        font-size: 1.55rem;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 8px;
        margin-top: 40px;
    }}
    h3 {{
        color: #1d4ed8;
        font-size: 1.25rem;
        margin-top: 26px;
    }}
    h4 {{
        color: #334155;
        font-size: 1.05rem;
        margin-top: 18px;
    }}
    p, li {{
        color: #334155;
        font-size: 14.5px;
    }}
    blockquote {{
        background-color: #eff6ff;
        border-left: 4px solid #3b82f6;
        margin: 18px 0;
        padding: 12px 18px;
        border-radius: 0 6px 6px 0;
    }}
    blockquote p {{
        margin: 0;
        color: #1e40af;
        font-size: 14px;
    }}
    table {{
        width: 100%;
        border-collapse: collapse;
        margin: 22px 0;
        font-size: 13.5px;
    }}
    th, td {{
        border: 1px solid #cbd5e1;
        padding: 9px 12px;
        text-align: left;
        vertical-align: top;
    }}
    th {{
        background-color: #f8fafc;
        color: #0f172a;
        font-weight: 600;
    }}
    tr:nth-child(even) {{
        background-color: #f8fafc;
    }}
    code {{
        background-color: #f1f5f9;
        color: #0969da;
        padding: 2px 5px;
        border-radius: 4px;
        font-family: Consolas, "Courier New", monospace;
        font-size: 13px;
    }}
    pre {{
        background-color: #0f172a;
        color: #f8fafc;
        padding: 14px 18px;
        border-radius: 6px;
        overflow-x: auto;
        font-size: 13px;
    }}
    pre code {{
        background-color: transparent;
        color: inherit;
        padding: 0;
    }}
    .mermaid {{
        margin: 24px 0;
        text-align: center;
        background: #fafafa;
        padding: 16px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }}
    hr {{
        border: none;
        border-top: 1px solid #e2e8f0;
        margin: 30px 0;
    }}
    a {{
        color: #2563eb;
        text-decoration: none;
    }}
    a:hover {{
        text-decoration: underline;
    }}
    @media print {{
        body {{
            background: #ffffff;
            padding: 0;
        }}
        .document-wrapper {{
            box-shadow: none;
            padding: 0;
        }}
        h2 {{
            page-break-before: always;
        }}
        table, pre, .mermaid {{
            page-break-inside: avoid;
        }}
    }}
</style>
</head>
<body>
<div class="document-wrapper">
{body}
</div>
</body>
</html>
'''

with open('Documentacion_Adbana_SaaS_25.html', 'w', encoding='utf-8') as f:
    f.write(full_html)

with open('src/docs/Documentacion_Adbana_SaaS_25.html', 'w', encoding='utf-8') as f:
    f.write(full_html)

print("HTML generated successfully! File size:", len(full_html))
