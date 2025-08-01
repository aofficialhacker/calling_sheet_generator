import sys
import mysql.connector
from reportlab.platypus import SimpleDocTemplate, Table, TableStyle, Paragraph, Spacer, PageBreak
from reportlab.lib.styles import getSampleStyleSheet
from reportlab.lib.pagesizes import landscape, A4
from reportlab.lib import colors
from reportlab.lib.units import cm

# --- Configuration ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '123456',
    'database': 'caller_sheet'
}

def generate_pdf(where_clause, output_buffer):
    """
    Connects to the DB, fetches data, and generates a PDF using ReportLab.
    """
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)

        # Step 1: Detect which columns have data (same logic as before)
        mandatory_headers = ['Mobile No', 'Slot', 'Connectivity', 'Disposition']
        optional_columns_db = ['name', 'title', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured']
        
        selects = [f"MAX(CASE WHEN `{col}` IS NOT NULL AND `{col}` != '' THEN 1 ELSE 0 END) as has_{col}" for col in optional_columns_db]
        presence_check_sql = f"SELECT {', '.join(selects)} FROM final_call_logs {where_clause}"
        cursor.execute(presence_check_sql)
        column_presence = cursor.fetchone()

        # Step 2: Build the final list of headers for the PDF
        pdf_headers_db = ['mobile_no']
        pdf_headers_display = ['Mobile No']
        if column_presence:
            for col in optional_columns_db:
                if column_presence.get(f"has_{col}") == 1:
                    pdf_headers_db.append(col)
                    pdf_headers_display.append(col.replace('_', ' ').title())

        # Fetch all records
        cursor.execute(f"SELECT {', '.join(pdf_headers_db)} FROM final_call_logs {where_clause}")
        records = cursor.fetchall()
        
        if not records:
             print("Error: No data found for the selected criteria.", file=sys.stderr)
             sys.exit(1)

        # --- Step 3: Build the PDF with ReportLab ---
        doc = SimpleDocTemplate(output_buffer, pagesize=landscape(A4), topMargin=0.5*cm, bottomMargin=0.5*cm, leftMargin=0.5*cm, rightMargin=0.5*cm)
        styles = getSampleStyleSheet()
        style_normal = styles['Normal']
        style_normal.fontSize = 6
        style_normal.leading = 8

        # Container for all PDF elements
        elements = []
        
        # Fixed header content
        slot_legend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)"
        disp_legend = "DISPO CODES (Y): 11:Interested | 12:Not Interested | 13:Call Back | 14:Follow Up | 15:More Info | 16:Language Barrier | 17:Drop || (N): 21:Ringing | 22:Switch Off | 23:Invalid Number | 24:Out of Service | 25:Wrong Number | 26:Busy"
        
        # Create the data for the main table
        # We add placeholder columns now and will replace them later
        table_data = [mandatory_headers + pdf_headers_display]
        for record in records:
            row_data = ['PLACEHOLDER_CONNECTIVITY', 'PLACEHOLDER_SLOT', 'PLACEHOLDER_DISPOSITION'] # Placeholders for special columns
            for db_col in pdf_headers_db:
                cell_value = record.get(db_col)
                row_data.append(Paragraph(str(cell_value) if cell_value is not None else '', style_normal))
            table_data.append(row_data)

        # Create the disposition grid (to be inserted into cells)
        dispo_grid_data = [
            ['○ 11', '○ 12', '○ 13', '○ 14', '○ 15', '○ 16', '○ 17'],
            ['○ 21', '○ 22', '○ 23', '○ 24', '○ 25', '○ 26', '']
        ]
        dispo_table_style = TableStyle([
            ('FONTSIZE', (0,0), (-1,-1), 6),
            ('LEFTSPACE', (0,0), (-1,-1), 0),
            ('RIGHTSPACE', (0,0), (-1,-1), 0),
            ('TOPPADDING', (0,0), (-1,-1), 0),
            ('BOTTOMPADDING', (0,0), (-1,-1), 0),
        ])
        dispo_grid = Table(dispo_grid_data, colWidths=[1*cm, 1*cm, 1*cm, 1*cm, 1*cm, 1*cm, 1*cm])
        dispo_grid.setStyle(dispo_table_style)

        # Replace placeholders with actual content
        for i, row in enumerate(table_data):
            if i == 0: continue # Skip header row
            row[0] = Paragraph('○ Y / ○ N', style_normal)
            row[1] = '' # Slot
            row[2] = dispo_grid

        # Create the main table object
        main_table = Table(table_data, repeatRows=1) # Repeat headers on each page

        # Define styles for the main table
        style = TableStyle([
            ('BACKGROUND', (0,0), (-1,0), colors.lightgrey),
            ('TEXTCOLOR', (0,0), (-1,0), colors.black),
            ('ALIGN', (0,0), (-1,-1), 'CENTER'),
            ('VALIGN', (0,0), (-1,-1), 'MIDDLE'),
            ('FONTNAME', (0,0), (-1,0), 'Helvetica-Bold'),
            ('BOTTOMPADDING', (0,0), (-1,0), 6),
            ('GRID', (0,0), (-1,-1), 1, colors.black),
            ('FONTSIZE', (0,1), (-1,-1), 7),
        ])
        main_table.setStyle(style)
        
        # Add a fixed header to each page
        def header(canvas, doc):
            canvas.saveState()
            canvas.setFont('Helvetica', 7)
            canvas.drawCentredString(landscape(A4)[0]/2.0, A4[0] - 1*cm, slot_legend)
            canvas.drawCentredString(landscape(A4)[0]/2.0, A4[0] - 1.5*cm, disp_legend)
            canvas.restoreState()

        elements.append(main_table)
        doc.build(elements, onFirstPage=header, onLaterPages=header)

    except Exception as e:
        print(f"Python Error: {e}", file=sys.stderr)
        sys.exit(1)
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python reportlab_generator.py batch_id=123 OR disposition=follow_up", file=sys.stderr)
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
        
        # The PDF is written to the standard output buffer
        generate_pdf(where_clause, sys.stdout.buffer)

    except Exception as e:
        print(f"Critical Error in script execution: {e}", file=sys.stderr)
        sys.exit(1)