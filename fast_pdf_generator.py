import sys
import mysql.connector
from weasyprint import HTML
import html  # Use the standard html library for escaping

# --- Configuration ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '123456',
    'database': 'caller_sheet'
}

def generate_pdf(where_clause):
    """
    Connects to the DB, fetches data in chunks, and generates a PDF using WeasyPrint.
    """
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)

        # --- Step 1: Dynamically Detect Which Columns Have Data ---
        mandatory_headers = ['mobile_no', 'slot', 'connectivity', 'disposition']
        optional_columns = ['name', 'title', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured']

        selects = [f"MAX(CASE WHEN `{col}` IS NOT NULL AND `{col}` != '' THEN 1 ELSE 0 END) as has_{col}" for col in optional_columns]
        presence_check_sql = f"SELECT {', '.join(selects)} FROM final_call_logs {where_clause}"
        cursor.execute(presence_check_sql)
        column_presence = cursor.fetchone()

        # --- Step 2: Build the Final List of Headers for the PDF ---
        pdf_headers = mandatory_headers[:]
        columns_to_select_list = ['mobile_no']
        if column_presence:
            for col in optional_columns:
                if column_presence.get(f"has_{col}") == 1:
                    pdf_headers.append(col)
                    columns_to_select_list.append(col)
        
        # --- Step 3: Define HTML Template and CSS ---
        html_template = """
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page {{ size: A4 landscape; margin: 1cm; }}
                body {{ font-family: sans-serif; font-size: 7.5pt; }}
                table.data-table {{ width: 100%; border-collapse: collapse; table-layout: fixed; }}
                thead {{ display: table-header-group; }}
                tr {{ page-break-inside: avoid; page-break-after: auto; }}
                th, td {{ border: 1px solid #333; padding: 3px; text-align: left; vertical-align: middle; word-wrap: break-word; }}
                thead th, .legend-cell {{ text-align: center; font-weight: bold; background-color: #f2f2f2; }}
                .anchor-col {{ font-weight: bold; font-family: monospace; }}
                .connectivity-col, .slot-cell {{ text-align: center; }}
                .disposition-cell {{ font-size: 7pt; padding: 1px !important; }}
                .dispo-grid {{ border: none !important; width: 100%; table-layout: fixed; }}
                .dispo-grid td {{ border: none !important; padding: 1px 2px; text-align: left; }}
            </style>
        </head>
        <body>
            {content}
        </body>
        </html>
        """
        
        slot_legend = "<strong>SLOTS:</strong> 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)"
        disp_legend = "<strong>DISPO CODES (Y):</strong> 11:Interested | 12:Not Interested | 13:Call Back | 14:Follow Up | 15:More Info | 16:Language Barrier | 17:Drop || <strong>(N):</strong> 21:Ringing | 22:Switch Off | 23:Invalid Number | 24:Out of Service | 25:Wrong Number | 26:Busy"
        
        header_row_html = "<tr>" + "".join([f"<th>{h.replace('_', ' ').title()}</th>" for h in pdf_headers]) + "</tr>"
        table_header = f"<thead><tr><th class='legend-cell' colspan='{len(pdf_headers)}'>{slot_legend}</th></tr><tr><th class='legend-cell' colspan='{len(pdf_headers)}'>{disp_legend}</th></tr>{header_row_html}</thead>"

        # --- Step 4: Fetch data in chunks and build HTML content ---
        content_html = ""
        chunk_size = 1000
        offset = 0
        
        # **BUG FIX**: Correctly handle the list of columns to select
        columns_to_select_str = ', '.join(f"`{col}`" for col in columns_to_select_list)

        while True:
            query = f"SELECT {columns_to_select_str} FROM final_call_logs {where_clause} LIMIT {chunk_size} OFFSET {offset}"
            cursor.execute(query)
            records = cursor.fetchall()
            if not records:
                break

            table_rows_html = ""
            for record in records:
                row_html = "<tr>"
                for header in pdf_headers:
                    if header == 'disposition':
                        row_html += '<td class="disposition-cell"><table class="dispo-grid"><tr><td>○ 11</td><td>○ 12</td><td>○ 13</td><td>○ 14</td><td>○ 15</td><td>○ 16</td><td>○ 17</td></tr><tr><td>○ 21</td><td>○ 22</td><td>○ 23</td><td>○ 24</td><td>○ 25</td><td>○ 26</td><td></td></tr></table></td>'
                    elif header == 'connectivity':
                        row_html += '<td class="connectivity-col">○ Y / ○ N</td>'
                    elif header == 'slot':
                        row_html += '<td class="slot-cell"></td>'
                    else:
                        cell_data = record.get(header)
                        # Ensure data is properly escaped to prevent HTML errors
                        cell_data_str = html.escape(str(cell_data)) if cell_data is not None else ''
                        css_class = 'anchor-col' if header == 'mobile_no' else ''
                        row_html += f'<td class="{css_class}">{cell_data_str}</td>'
                row_html += "</tr>"
                table_rows_html += row_html
            
            content_html += f"<table class='data-table'>{table_header}<tbody>{table_rows_html}</tbody></table>"
            offset += chunk_size
        
        # --- Step 5: Render PDF and output to stdout ---
        if not content_html:
             print("Error: No data found for the selected criteria.", file=sys.stderr)
             sys.exit(1)
             
        final_html = html_template.format(content=content_html)
        pdf_bytes = HTML(string=final_html).write_pdf()
        sys.stdout.buffer.write(pdf_bytes)

    except Exception as e:
        print(f"Python Error: {e}", file=sys.stderr)
        sys.exit(1)
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python fast_pdf_generator.py batch_id=123 OR python fast_pdf_generator.py disposition=follow_up", file=sys.stderr)
        sys.exit(1)

    arg = sys.argv[1]
    where_clause = ""
    
    try:
        if arg.startswith("batch_id="):
            batch_id = int(arg.split('=')[1])
            where_clause = f"WHERE batch_id = {batch_id}"
        elif arg == "disposition=follow_up":
            where_clause = "WHERE disposition = 'Follow Up'"
        else:
            print("Invalid argument provided.", file=sys.stderr)
            sys.exit(1)
            
        generate_pdf(where_clause)

    except Exception as e:
        print(f"Critical Error in script execution: {e}", file=sys.stderr)
        sys.exit(1)